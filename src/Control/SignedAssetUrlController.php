<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Control;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;
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
        if ($signingService->shouldCheckPublishedStatus() && !$signingService->canBypassSigning()) {
            if (!$this->isFilePublished($file)) {
                return $this->httpError(403, 'File not available');
            }
        }

        // Serve the file (original or variant)
        return $isVariant
            ? $this->serveVariant($assetPath, $expires, $signingService)
            : $this->serveOriginal($file, $expires, $signingService);
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
     * Serve original file using File::getStream()
     */
    protected function serveOriginal(File $file, int $expires, AssetUrlSigningService $signingService): HTTPResponse
    {
        if (!$file->exists()) {
            return $this->httpError(404, "File exists in DB but not in storage");
        }

        $stream = $file->getStream();
        if (!$stream) {
            return $this->httpError(404, 'File stream not available');
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $fileSize = $file->getAbsoluteSize();
        $displayFilename = $file->Name;

        return $this->createStreamResponse($stream, $fileSize, $mimeType, $displayFilename, $expires, $signingService);
    }

    /**
     * Serve variant file using AssetStore directly
     */
    protected function serveVariant(string $variantPath, int $expires, AssetUrlSigningService $signingService): HTTPResponse
    {
        /** @var AssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        // Parse the variant path to get filename and hash
        $parts = explode('/', $variantPath, 2);
        if (count($parts) < 2) {
            return $this->httpError(404, 'Invalid variant path');
        }

        $hashPrefix = $parts[0];
        $variantFilename = $parts[1];

        // Find the original file to get the full hash
        $file = $this->findFileByVariantPath($variantPath);
        if (!$file) {
            return $this->httpError(404, 'Original file not found');
        }

        // Extract variant identifier from filename (e.g., __FillWzQwMCwyMjVd from image__FillWzQwMCwyMjVd.jpg)
        $variant = null;
        if (preg_match('/__([A-Za-z0-9+\/=]+)\./', $variantFilename, $matches)) {
            $variant = $matches[1];
        }

        // Try to get the stream using the AssetStore
        // For protected assets, we access via the protected adapter
        $stream = $store->getAsStream(
            $file->getFilename(),
            $file->getHash(),
            $variant
        );

        if (!$stream) {
            return $this->httpError(404, 'Variant file not found');
        }

        // Get metadata for the variant
        $metadata = $store->getMetadata($file->getFilename(), $file->getHash(), $variant);
        $fileSize = $metadata['size'] ?? null;

        // Determine MIME type from variant filename
        $extension = pathinfo($variantFilename, PATHINFO_EXTENSION);
        $mimeType = $this->getMimeTypeFromExtension($extension);

        // Use original filename for display (without variant suffix)
        $displayFilename = $file->Name;

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
     * Check if a file is published (or doesn't use staging)
     *
     * @param File $file The file to check
     * @return bool True if file should be publicly accessible
     */
    protected function isFilePublished(File $file): bool
    {
        // First check: Does File class use Versioned with staging at all?
        if (!$this->fileClassUsesStaging()) {
            return true;
        }

        // File uses staging - check if it exists on Live stage
        return Versioned::withVersionedMode(function () use ($file) {
            Versioned::set_stage(Versioned::LIVE);
            return File::get()->byID($file->ID) !== null;
        });
    }

    /**
     * Check if the File class is configured with Versioned staging
     *
     * @return bool True if File uses Versioned with Live stage
     */
    protected function fileClassUsesStaging(): bool
    {
        if (!File::has_extension(Versioned::class)) {
            return false;
        }

        $extensions = File::config()->get('extensions') ?? [];

        foreach ($extensions as $extension) {
            if ($extension === Versioned::class || $extension === 'SilverStripe\Versioned\Versioned') {
                return true;
            }
            if (str_contains($extension, 'Versioned.versioned')) {
                return false;
            }
        }

        return false;
    }
}
