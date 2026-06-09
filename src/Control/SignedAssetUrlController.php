<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Control;

use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Flysystem\LocalFilesystemAdapter;
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
            return $this->httpError(404, 'File not found');
        }

        // Check if file is published (respects SilverStripe's protected assets system)
        // Versioned::isPublished() handles both staging and versioning-only modes
        if ($signingService->shouldCheckPublishedStatus() && !$signingService->canBypassSigning()) {
            if (!$file->isPublished()) {
                return $this->httpError(403, 'File not available');
            }
        }

        // Disposition opt-in: ?d=att forces "attachment" (download). Default is "inline" for
        // browser-viewable types (PDFs, images) so they can be embedded in <iframe>/<img>.
        // Caller appends &d=att manually when a download prompt is desired — the signed URL
        // builders intentionally don't pass this through.
        $forceAttachment = $request->getVar('d') === 'att';

        // Serve the file (pass variantPath for variants, null for originals)
        return $this->serveFile($file, $isVariant ? $assetPath : null, $expires, $signingService, $forceAttachment);
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
     * We need to find the File with matching Hash and Name.
     *
     * Filtering by Name is critical when multiple File records share the same hash
     * (e.g. re-imports of the same image). Without it, File's default_sort='Name'
     * can return the wrong record, causing variant path resolution to fail.
     */
    protected function findFileByVariantPath(string $variantPath): ?File
    {
        // Extract hash and variant filename from path (first segment is hash prefix)
        $parts = explode('/', $variantPath, 2);
        if (count($parts) < 2) {
            return null;
        }

        $hashPrefix = $parts[0];
        $variantFilename = $parts[1]; # e.g. "image__FillWzQwMCwyMjVd.jpg"

        # Extract original filename by stripping variant suffix
        # "image__FillWzQwMCwyMjVd.jpg" → "image.jpg"
        $originalName = preg_replace('/__[A-Za-z0-9+\/=]+\./', '.', $variantFilename);

        # Filter by both hash and name to avoid returning wrong record when duplicates exist
        return File::get()->filter([
            'FileHash:StartsWith' => $hashPrefix,
            'Name' => $originalName,
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
    protected function serveFile(File $file, ?string $variantPath, int $expires, AssetUrlSigningService $signingService, bool $forceAttachment = false): HTTPResponse
    {
        $displayFilename = $file->Name;

        // Determine MIME type and resolved path based on original vs variant
        if ($variantPath) {
            $extension = pathinfo($variantPath, PATHINFO_EXTENSION);
            $mimeType = $this->getMimeTypeFromExtension($extension);
            // Build full relative path: folder/hashprefix/filename__variant.ext
            $dirname = dirname($file->getFilename());
            $relativePath = ($dirname !== '.' ? $dirname . '/' : '') . $variantPath;
            // For variants, resolve via the signing service (known layout)
            $absolutePath = $signingService->getAbsoluteFilePath($relativePath);
            $resolved = $absolutePath ? [
                'absolutePath' => $absolutePath,
                'relativePath' => $relativePath,
            ] : null;
        } else {
            if (!$file->exists()) {
                return $this->httpError(404, 'File not found in storage');
            }
            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            // Use framework resolution for originals (handles all storage layouts)
            $resolved = $this->resolveFilePath($file);
        }

        // Try X-Sendfile/X-Accel-Redirect first (more efficient than PHP streaming).
        // Guard with is_file() — handing a non-existent path to the web server would
        // produce its own 404 and we couldn't apply the no-op-variant fallback below.
        if ($resolved && is_file($resolved['absolutePath']) && $signingService->getFileServer() !== 'php') {
            $response = $this->createWebServerResponse(
                $resolved,
                $mimeType,
                $displayFilename,
                $expires,
                $signingService,
                $forceAttachment
            );
            if ($response) {
                return $response;
            }
        }

        // Fall back to PHP streaming
        $stream = null;
        $fileSize = null;

        // Try direct file path first (faster, works for all file server modes)
        if ($resolved && is_file($resolved['absolutePath'])) {
            $absolutePath = $resolved['absolutePath'];
            $stream = fopen($absolutePath, 'rb');
            $fileSize = filesize($absolutePath) ?: null;
        }

        // Variant fallbacks (only if direct path didn't yield a stream)
        if (!$stream && $variantPath) {
            $variant = $this->parseVariantIdentifier($variantPath);

            # Fallback 1: AssetStore resolution (handles non-standard storage layouts)
            if ($variant) {
                /** @var AssetStore $store */
                $store = Injector::inst()->get(AssetStore::class);
                $stream = $store->getAsStream($file->getFilename(), $file->getHash(), $variant);
                $metadata = $store->getMetadata($file->getFilename(), $file->getHash(), $variant);
                $fileSize = $metadata['size'] ?? null;
            }

            # Fallback 2: SS5 no-op-scale workaround.
            # ImageManipulation::ScaleMaxWidth and friends produce a variant URL
            # even when the source dimensions are already within the requested
            # target (a no-op transformation) — but the variant file is not
            # always materialised on disk, producing a "Variant file not found"
            # 404 here. When this is the case, the variant content would have
            # been a byte-identical copy of the original anyway, so we just
            # stream the original's bytes at the variant URL.
            #
            # Scoping (DDoS-defensive):
            #  - whitelisted Scale* methods only (no Fill/Pad/Crop)
            #  - source dimension must be <= target (the no-op case only)
            #  - target dimension sanity-capped
            # Zero new attack surface vs the original file URL.
            if (!$stream && $variant) {
                $stream = $this->tryServeOriginalForNoOpVariant($file, $variant);
                if ($stream) {
                    $fileSize = $file->getAbsoluteSize();
                }
            }
        }

        // Original (non-variant) fallback
        if (!$stream && !$variantPath) {
            $stream = $file->getStream();
            $fileSize = $file->getAbsoluteSize();
        }

        if (!$stream) {
            return $this->httpError(404, $variantPath ? 'Variant file not found' : 'File stream not available');
        }

        return $this->createStreamResponse($stream, $fileSize, $mimeType, $displayFilename, $expires, $signingService, $forceAttachment);
    }

    /**
     * Extract the variant identifier (e.g. "ScaleMaxWidthWzI0MDBd") from a variant path.
     */
    protected function parseVariantIdentifier(string $variantPath): ?string
    {
        $variantFilename = basename($variantPath);
        if (preg_match('/__([A-Za-z0-9+\/=_-]+)\./', $variantFilename, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * SS5 "ScaleMaxWidth on already-smaller source = variant URL with no
     * variant file" workaround. When the requested variant is a no-op scale
     * (source dimension already within target), return a stream of the
     * original file's bytes — the variant would have been a byte-identical
     * copy anyway.
     *
     * Strictly scoped to avoid becoming a DDoS amplifier:
     *  - Scale* methods only (no Fill/Pad/Crop expensive operations)
     *  - Only when source dim ≤ target dim (the no-op case)
     *  - Target dimension sanity-capped at 16000px
     *
     * Returns null (and the caller 404s) for any case outside this window.
     *
     * @return resource|null Stream of original file bytes, or null if not applicable
     */
    protected function tryServeOriginalForNoOpVariant(File $file, string $variantIdentifier)
    {
        # Variant identifier format: "{MethodName}{base64url(jsonArgs)}" — there's
        # no delimiter, so we match against a whitelist of known method names
        # (longest first so "ScaleMaxWidth" wins over "ScaleWidth" prefix match).
        # Whitelist: only no-op-capable Scale methods. Fill/Pad/Crop are NOT
        # safe to "serve as original" because they crop/letterbox and produce
        # visually different output at different dimensions.
        $methodAccessor = [
            'ScaleMaxWidth'  => 'getWidth',
            'ScaleMaxHeight' => 'getHeight',
            'ScaleWidth'     => 'getWidth',
            'ScaleHeight'    => 'getHeight',
        ];
        $methodName = null;
        $encodedArgs = null;
        $dimensionAccessor = null;
        foreach ($methodAccessor as $candidate => $accessor) {
            if (str_starts_with($variantIdentifier, $candidate)) {
                $methodName = $candidate;
                $encodedArgs = substr($variantIdentifier, strlen($candidate));
                $dimensionAccessor = $accessor;
                break;
            }
        }
        if ($methodName === null) {
            return null;
        }

        # Decode args — base64url encoded JSON array
        $jsonArgs = base64_decode(strtr($encodedArgs, '-_', '+/'), true);
        if ($jsonArgs === false) {
            return null;
        }
        $args = json_decode($jsonArgs, true);
        if (!is_array($args) || empty($args)) {
            return null;
        }

        $targetDim = (int) $args[0];
        if ($targetDim <= 0 || $targetDim > 16000) {
            return null;
        }

        # Source must be an Image (or DBFile with image dimension accessors)
        if (!method_exists($file, $dimensionAccessor)) {
            return null;
        }
        $sourceDim = (int) $file->{$dimensionAccessor}();
        if ($sourceDim === 0 || $sourceDim > $targetDim) {
            return null; # actual resize would be needed — not a no-op
        }

        # Stream the original — by this point we know the requested variant
        # would have been a byte-identical copy of the original.
        return $file->getStream() ?: null;
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
        AssetUrlSigningService $signingService,
        bool $forceAttachment = false
    ): HTTPResponse {
        $response = HTTPStreamResponse::create($stream, $fileSize);
        $response->setStatusCode(200);
        $response->addHeader('Content-Type', $mimeType);

        // Add cache headers for signed URLs (can be cached until expiry)
        if (!$signingService->canBypassSigning()) {
            $maxAge = max(0, $expires - time());
            $response->addHeader('Cache-Control', "private, max-age={$maxAge}");
        }

        // Content-Disposition. PDFs/images default to "inline" so they can render in
        // <iframe>/<img>. ZIP/octet-stream always download (no inline use case). Callers
        // can append ?d=att to any URL to force download — see serve().
        $safeFilename = str_replace(['"', '\\'], '', $displayFilename);
        $disposition = $this->resolveDisposition($mimeType, $forceAttachment);
        $response->addHeader('Content-Disposition', $disposition . '; filename="' . $safeFilename . '"');

        return $response;
    }

    /**
     * Decide attachment vs inline disposition for a given mime type.
     *
     * - $forceAttachment (from ?d=att) always wins → "attachment"
     * - ZIP/octet-stream → "attachment" (no inline rendering)
     * - Everything else (including application/pdf, images) → "inline"
     */
    protected function resolveDisposition(string $mimeType, bool $forceAttachment): string
    {
        if ($forceAttachment) {
            return 'attachment';
        }
        $alwaysDownload = ['application/zip', 'application/octet-stream'];
        return in_array($mimeType, $alwaysDownload, true) ? 'attachment' : 'inline';
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
     * Resolve a File to its absolute local filesystem path using framework resolution.
     *
     * Checks both protected and public stores via the framework's FileResolutionStrategy,
     * which handles all storage layouts (hash paths, natural paths, DB hash lookups).
     *
     * @return array{absolutePath: string, relativePath: string}|null
     */
    protected function resolveFilePath(File $file): ?array
    {
        $store = Injector::inst()->get(AssetStore::class);
        if (!$store instanceof FlysystemAssetStore) {
            return null;
        }

        $parsedFileID = new ParsedFileID($file->getFilename(), $file->getHash() ?: '');

        // Try protected first (most signed-asset files are protected), then public
        foreach (['Protected', 'Public'] as $type) {
            $filesystem = $store->{"get{$type}Filesystem"}();
            $strategy = $store->{"get{$type}ResolutionStrategy"}();
            $resolved = $strategy->searchForTuple($parsedFileID, $filesystem);

            if ($resolved) {
                $adapter = $filesystem->getAdapter();
                if ($adapter instanceof LocalFilesystemAdapter) {
                    $fileID = $resolved->getFileID();
                    $absolutePath = $adapter->prefixPath($fileID);
                    if (file_exists($absolutePath)) {
                        return [
                            'absolutePath' => $absolutePath,
                            'relativePath' => $fileID,
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Create response using web server file handoff (X-Sendfile or X-Accel-Redirect).
     *
     * @param array{absolutePath: string, relativePath: string} $resolved Resolved file paths
     * @param string $mimeType MIME type of the file
     * @param string $displayFilename Filename to use in Content-Disposition header
     * @param int $expires Expiry timestamp for cache headers
     * @param AssetUrlSigningService $signingService Signing service instance
     * @return HTTPResponse|null Response if successful, null to fall back to PHP streaming
     */
    protected function createWebServerResponse(
        array $resolved,
        string $mimeType,
        string $displayFilename,
        int $expires,
        AssetUrlSigningService $signingService,
        bool $forceAttachment = false
    ): ?HTTPResponse {
        $fileServer = $signingService->getFileServer();
        $absolutePath = $resolved['absolutePath'];
        $relativePath = $resolved['relativePath'];

        $response = HTTPResponse::create();
        $response->setStatusCode(200);
        $response->addHeader('Content-Type', $mimeType);

        // Cache headers
        if (!$signingService->canBypassSigning()) {
            $maxAge = max(0, $expires - time());
            $response->addHeader('Cache-Control', "private, max-age={$maxAge}");
        }

        // Content-Disposition (see resolveDisposition() for policy)
        $safeFilename = str_replace(['"', '\\'], '', $displayFilename);
        $disposition = $this->resolveDisposition($mimeType, $forceAttachment);
        $response->addHeader('Content-Disposition', $disposition . '; filename="' . $safeFilename . '"');

        if ($fileServer === 'apache') {
            // Apache mod_xsendfile needs absolute path
            $response->addHeader('X-Sendfile', $absolutePath);
            $fileSize = filesize($absolutePath);
            if ($fileSize) {
                $response->addHeader('Content-Length', (string) $fileSize);
            }
        } elseif ($fileServer === 'nginx') {
            // Nginx X-Accel-Redirect needs internal location + relative path
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
