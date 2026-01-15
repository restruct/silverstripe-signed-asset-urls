<?php

namespace Restruct\SilverStripe\SignedAssetUrls\Control;

use SilverStripe\Assets\File;
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
 * Validates the signature and expiry, then uses SilverStripe's File->getStream()
 * to serve the file content. This approach uses SilverStripe's built-in
 * AssetStore for file resolution, which handles hash-based paths automatically.
 *
 * URL format (S3-style with signature in query params):
 * - /signed-asset/{path}?s={hash}&e={expires}       (no session binding)
 * - /signed-asset/{path}?s={hash}&e={expires}&ss=1 (session-bound)
 *
 * Where {path} is the FileFilename (logical name, e.g. "folder/document.pdf").
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

        // URL decode the path - this is the FileFilename (logical name)
        $filename = rawurldecode($path);

        /** @var AssetUrlSigningService $signingService */
        $signingService = Injector::inst()->get(AssetUrlSigningService::class);

        // Check if user can bypass signing (admin/CMS users)
        if (!$signingService->canBypassSigning()) {
            // Validate signature against the filename
            $validation = $signingService->validateSignature($hash, $expires, $filename, $sessionBound);

            if ($validation === 'expired') {
                return $this->httpError(410, 'This link has expired');
            }

            if ($validation === 'invalid_signature') {
                return $this->httpError(403, 'Invalid signature');
            }
        }

        // Look up the File by FileFilename
        $file = File::get()->filter('FileFilename', $filename)->first();

        if (!$file) {
            return $this->httpError(404, "File not found in DB: {$filename}");
        }
        if (!$file->exists()) {
            return $this->httpError(404, "File DB record exists but file->exists() is false: {$filename}");
        }

        // Check if file is published (respects SilverStripe's protected assets system)
        if ($signingService->shouldCheckPublishedStatus() && !$signingService->canBypassSigning()) {
            if (!$this->isFilePublished($file)) {
                return $this->httpError(403, 'File not available');
            }
        }

        // Get file stream using SilverStripe's AssetStore (handles path resolution)
        $stream = $file->getStream();

        if (!$stream) {
            return $this->httpError(404, 'File not found');
        }

        // Get file metadata
        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $fileSize = $file->getAbsoluteSize();
        $displayFilename = $file->Name;

        // Create stream response
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
