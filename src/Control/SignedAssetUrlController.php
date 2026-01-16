<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Control;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Core\Injector\Injector;
use Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService;

/**
 * Controller for serving signed asset URLs.
 *
 * Validates the signature and expiry, then uses SilverStripe's AssetStore
 * to serve the file content directly. Supports both original files and
 * variants (from Fill(), ScaleWidth(), etc.).
 *
 * URL format (S3-style with signature in query params):
 * - /signed-asset/{path}?s={hash}&e={expires}       (no session binding)
 * - /signed-asset/{path}?s={hash}&e={expires}&ss=1 (session-bound)
 *
 * Where {path} is either:
 * - FileFilename (logical name, e.g. "Uploads/document.pdf") for original files
 * - Hash-prefixed path (e.g. "abc123/image__FillWzQwMCwyMjVd.jpg") for variants
 */
class SignedAssetUrlController extends Controller
{
    private static $url_segment = 'signed-asset';

    private static $url_handlers = [
        '$@' => 'serve',
    ];

    private static $allowed_actions = [
        'serve',
    ];

    /**
     * Serve a signed asset
     *
     * URL format: /signed-asset/{path}?s={hash}&e={expires}&ss={session_bound}
     */
    public function serve(HTTPRequest $request): HTTPResponse
    {
        // Get signature params from query string (S3-style)
        $hash = $request->getVar('s') ?? '';
        $expires = (int) ($request->getVar('e') ?? 0);
        $sessionBound = (bool) $request->getVar('ss');

        // Get path from URL (everything after /signed-asset/)
        $url = $request->getURL();
        $path = preg_replace('#^signed-asset/#', '', $url);

        // URL decode the path
        $assetPath = rawurldecode($path);

        /** @var AssetUrlSigningService $signingService */
        $signingService = Injector::inst()->get(AssetUrlSigningService::class);

        // Check if user can bypass signing (admin/CMS users)
        if (!$signingService->canBypassSigning()) {
            // Validate signature against the path
            $validation = $signingService->validateSignature($hash, $expires, $assetPath, $sessionBound);

            if ($validation === 'expired') {
                return $this->httpError(410, 'This link has expired');
            }

            if ($validation === 'invalid_signature') {
                return $this->httpError(403, 'Invalid signature');
            }
        }

        // Determine if this is a variant path (hash-prefixed) or original file path
        // Variant paths look like: abc123/image__FillWzQwMCwyMjVd.jpg
        // Original paths look like: Uploads/image.jpg
        $isVariant = $this->isVariantPath($assetPath);

        // Find the original File record
        $file = $isVariant
            ? $this->findFileByVariantPath($assetPath)
            : File::get()->filter('FileFilename', $assetPath)->first();

        if (!$file) {
            return $this->httpError(404, "File not found: {$assetPath}");
        }

        // Check if file is published (respects SilverStripe's protected assets system)
        // Versioned::isPublished() handles both staging and versioning-only modes
        if ($signingService->shouldCheckPublishedStatus() && !$signingService->canBypassSigning()) {
            if (!$file->isPublished()) {
                return $this->httpError(403, 'File not available');
            }
        }

        // Serve the file (pass variantPath for variants, null for originals)
        return $this->serveFile($file, $isVariant ? $assetPath : null, $expires, $signingService);
    }

    /**
     * Check if a path looks like a variant (hash-prefixed)
     *
     * Variant paths: abc123/image__FillWzQwMCwyMjVd.jpg (hash/filename pattern)
     * Original paths: Uploads/image.jpg (folder/filename pattern)
     */
    protected function isVariantPath(string $path): bool
    {
        // Variant paths have a hash prefix (10+ hex chars) followed by filename with variant suffix
        // The hash is the first path segment before the first /
        $parts = explode('/', $path, 2);
        if (count($parts) < 2) {
            return false;
        }

        $firstSegment = $parts[0];

        // Hash segments are typically 10 hex characters
        if (preg_match('/^[a-f0-9]{10}$/i', $firstSegment)) {
            return true;
        }

        return false;
    }

    /**
     * Find the original File record from a variant path
     *
     * Variant paths look like: abc123/image__FillWzQwMCwyMjVd.jpg
     * We need to find the File with matching Hash
     */
    protected function findFileByVariantPath(string $variantPath): ?File
    {
        // Extract hash from path (first segment)
        $parts = explode('/', $variantPath, 2);
        if (count($parts) < 2) {
            return null;
        }

        $hashPrefix = $parts[0];

        // Find file by hash prefix (File.FileHash starts with this)
        return File::get()->where([
            'SUBSTRING(FileHash, 1, ' . strlen($hashPrefix) . ') = ?' => $hashPrefix
        ])->first();
    }

    /**
     * Serve file (original or variant) using X-Sendfile/X-Accel-Redirect or PHP streaming.
     *
     * @param File $file The File record
     * @param string|null $variantPath For variants: hash-prefixed path (e.g., "abc123/image__FillWzQwMCwyMjVd.jpg")
     * @param int $expires Expiry timestamp for cache headers
     * @param AssetUrlSigningService $signingService Signing service instance
     */
    protected function serveFile(File $file, ?string $variantPath, int $expires, AssetUrlSigningService $signingService): HTTPResponse
    {
        $displayFilename = $file->Name;

        // Determine MIME type and relative path based on original vs variant
        if ($variantPath) {
            $extension = pathinfo($variantPath, PATHINFO_EXTENSION);
            $mimeType = $this->getMimeTypeFromExtension($extension);
            // Build full relative path: folder/hashprefix/filename__variant.ext
            $dirname = dirname($file->getFilename());
            $relativePath = ($dirname !== '.' ? $dirname . '/' : '') . $variantPath;
        } else {
            if (!$file->exists()) {
                return $this->httpError(404, "File exists in DB but not in storage");
            }
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $relativePath = $this->computeOnDiskPath($file);
        }

        // Try X-Sendfile/X-Accel-Redirect first (more efficient than PHP streaming)
        if ($signingService->getFileServer() !== 'php') {
            $absolutePath = $signingService->getAbsoluteFilePath($relativePath);
            if ($absolutePath) {
                $response = $this->createWebServerResponse(
                    $relativePath,
                    filesize($absolutePath),
                    $mimeType,
                    $displayFilename,
                    $expires,
                    $signingService
                );
                if ($response) {
                    return $response;
                }
            }
        }

        // Fall back to PHP streaming
        if ($variantPath) {
            // Extract variant identifier (e.g., "FillWzQwMCwyMjVd" from "image__FillWzQwMCwyMjVd.jpg")
            $variant = null;
            $variantFilename = basename($variantPath);
            if (preg_match('/__([A-Za-z0-9+\/=]+)\./', $variantFilename, $matches)) {
                $variant = $matches[1];
            }

            /** @var AssetStore $store */
            $store = Injector::inst()->get(AssetStore::class);
            $stream = $store->getAsStream($file->getFilename(), $file->getHash(), $variant);
            $metadata = $store->getMetadata($file->getFilename(), $file->getHash(), $variant);
            $fileSize = $metadata['size'] ?? null;
        } else {
            $stream = $file->getStream();
            $fileSize = $file->getAbsoluteSize();
        }

        if (!$stream) {
            return $this->httpError(404, $variantPath ? 'Variant file not found' : 'File stream not available');
        }

        return $this->createStreamResponse($stream, $fileSize, $mimeType, $displayFilename, $expires, $signingService);
    }

    /**
     * Create HTTP stream response with appropriate headers
     */
    protected function createStreamResponse(
        $stream,
        ?int $fileSize,
        string $mimeType,
        string $displayFilename,
        int $expires,
        AssetUrlSigningService $signingService
    ): HTTPResponse {
        $response = HTTPStreamResponse::create($stream, $fileSize);
        $response->setStatusCode(200);
        $response->addHeader('Content-Type', $mimeType);

        // Add cache headers for signed URLs (can be cached until expiry)
        if (!$signingService->canBypassSigning()) {
            $maxAge = max(0, $expires - time());
            $response->addHeader('Cache-Control', "private, max-age={$maxAge}");
        }

        // Set Content-Disposition for downloads (optional, based on file type)
        $downloadTypes = ['application/pdf', 'application/zip', 'application/octet-stream'];
        if (in_array($mimeType, $downloadTypes)) {
            $response->addHeader('Content-Disposition', 'attachment; filename="' . $displayFilename . '"');
        } else {
            $response->addHeader('Content-Disposition', 'inline; filename="' . $displayFilename . '"');
        }

        return $response;
    }

    /**
     * Get MIME type from file extension
     */
    protected function getMimeTypeFromExtension(string $extension): string
    {
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
        ];

        return $mimeTypes[strtolower($extension)] ?? 'application/octet-stream';
    }

    /**
     * Compute on-disk path for a File (adds hash prefix to FileFilename).
     *
     * Converts FileFilename (e.g., 'Uploads/image.jpg') to actual on-disk path
     * (e.g., 'Uploads/abc1234567/image.jpg') in the protected assets folder.
     *
     * @param File $file The file record
     * @return string Relative path with hash prefix
     */
    protected function computeOnDiskPath(File $file): string
    {
        $hashPrefix = substr($file->getHash(), 0, 10);
        $dirname = dirname($file->getFilename());
        $basename = basename($file->getFilename());
        return ($dirname !== '.' ? $dirname . '/' : '') . $hashPrefix . '/' . $basename;
    }

    /**
     * Create response using web server file handoff (X-Sendfile or X-Accel-Redirect).
     *
     * @param string $relativePath Path relative to protected folder
     * @param int|null $fileSize File size in bytes (null if unknown)
     * @param string $mimeType MIME type of the file
     * @param string $displayFilename Filename to use in Content-Disposition header
     * @param int $expires Expiry timestamp for cache headers
     * @param AssetUrlSigningService $signingService Signing service instance
     * @return HTTPResponse|null Response if successful, null to fall back to PHP streaming
     */
    protected function createWebServerResponse(
        string $relativePath,
        ?int $fileSize,
        string $mimeType,
        string $displayFilename,
        int $expires,
        AssetUrlSigningService $signingService
    ): ?HTTPResponse {
        $fileServer = $signingService->getFileServer();

        // Get absolute path for the file
        $absolutePath = $signingService->getAbsoluteFilePath($relativePath);
        if (!$absolutePath) {
            return null; // File doesn't exist, fall back to PHP streaming
        }

        $response = HTTPResponse::create();
        $response->setStatusCode(200);
        $response->addHeader('Content-Type', $mimeType);

        // Cache headers
        if (!$signingService->canBypassSigning()) {
            $maxAge = max(0, $expires - time());
            $response->addHeader('Cache-Control', "private, max-age={$maxAge}");
        }

        // Content-Disposition
        $downloadTypes = ['application/pdf', 'application/zip', 'application/octet-stream'];
        if (in_array($mimeType, $downloadTypes)) {
            $response->addHeader('Content-Disposition', 'attachment; filename="' . $displayFilename . '"');
        } else {
            $response->addHeader('Content-Disposition', 'inline; filename="' . $displayFilename . '"');
        }

        if ($fileServer === 'apache') {
            // Apache mod_xsendfile
            $response->addHeader('X-Sendfile', $absolutePath);
            if ($fileSize) {
                $response->addHeader('Content-Length', (string) $fileSize);
            }
        } elseif ($fileServer === 'nginx') {
            // Nginx X-Accel-Redirect
            $internalLocation = $signingService->getNginxInternalLocation();
            $internalPath = rtrim($internalLocation, '/') . '/' . ltrim($relativePath, '/');
            $response->addHeader('X-Accel-Redirect', $internalPath);
            // Nginx handles Content-Length automatically
        } else {
            return null; // Unknown server type, fall back to PHP streaming
        }

        return $response;
    }
}
