# Signed Protected Asset URLs for SilverStripe

Time-expiring signed URLs for protected SilverStripe assets, similar to Amazon S3 pre-signed URLs.

## Features

- **Time-limited URLs**: Assets are only accessible for a configurable duration
- **Session binding**: Optionally restrict URLs to the session that created them
- **HMAC-SHA256 signing**: Cryptographically secure URL signatures
- **Admin bypass**: Logged-in CMS users can access assets without signing
- **Configurable TTL**: Default and per-URL time-to-live settings
- **SilverStripe integration**: Uses SilverStripe's AssetStore for file resolution (works with hash-based paths)

## Configuration

### Environment Variables

Add to your `.env` file:

```bash
# Required: Secret key for signing URLs (use a long random string)
ASSET_SIGNING_SECRET="your-secret-key-min-32-characters-recommended"
```

### SilverStripe Config

Create `app/_config/signed-asset-urls.yml` to override defaults:

```yaml
Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService:
  # Default TTL in seconds (default: 3600 = 1 hour)
  default_ttl: 3600

  # Bind URLs to user session by default (default: false)
  bind_to_session: false

  # Automatically adjust page Cache-Control headers based on signed URL TTLs (default: true)
  auto_cache_headers: true

  # Check if files are published before serving - respects Versioned staging (default: true)
  # Set to false if you don't use Versioned staging on files
  check_published_status: true

  # Permissions that bypass signing (default: uses Versioned::$non_live_permissions)
  # This typically includes CMS_ACCESS_*, VIEW_DRAFT_CONTENT, etc.
  # Set to custom array to override:
  # bypass_permissions:
  #   - 'ADMIN'
  #   - 'CMS_ACCESS_AssetAdmin'
```

All configuration options have sensible defaults - you typically only need to set `ASSET_SIGNING_SECRET` in `.env` to get started.

## Web Server Configuration (Optional)

By default, files are served via PHP streaming. For better performance on high-traffic sites, you can enable web server file handoff using X-Sendfile (Apache) or X-Accel-Redirect (Nginx).

### Environment Variable

Add to your `.env` file:

```bash
# File serving method: 'php' (default), 'apache', or 'nginx'
ASSET_FILE_SERVER=php
```

### Nginx (X-Accel-Redirect)

1. Set `ASSET_FILE_SERVER=nginx` in `.env`

2. Add an internal location block to your nginx config. The location path is derived from your protected assets folder name:

   **Default setup** (using `.protected` inside `public/assets`):
   ```nginx
   location /.protected/ {
       internal;
       alias /path/to/project/public/assets/.protected/;
       # Alternative (more portable): root /path/to/project/public/assets;
   }
   ```

   **Custom setup** (using `SS_PROTECTED_ASSETS_PATH`):
   ```nginx
   # If SS_PROTECTED_ASSETS_PATH="../protected_assets"
   location /protected_assets/ {
       internal;
       alias /path/to/project/protected_assets/;
       # Alternative (more portable): root /path/to/project;
   }
   ```

3. Run the verification task to get your exact configuration:
   ```bash
   vendor/bin/sake dev/tasks/SignedAssetUrlVerifyTask
   ```

**How it works**: PHP validates the signed URL, then sends an `X-Accel-Redirect` header. Nginx intercepts this and serves the file directly from disk, bypassing PHP for the actual file transfer.

### Apache (X-Sendfile)

1. Install and enable mod_xsendfile:
   ```bash
   sudo a2enmod xsendfile
   sudo systemctl restart apache2
   ```

2. Set `ASSET_FILE_SERVER=apache` in `.env`

3. Add to your Apache config or `.htaccess`:

   **Default setup**:
   ```apache
   <IfModule mod_xsendfile.c>
       XSendFile On
       XSendFilePath /path/to/project/public/assets/.protected
   </IfModule>
   ```

   **Custom setup** (using `SS_PROTECTED_ASSETS_PATH`):
   ```apache
   <IfModule mod_xsendfile.c>
       XSendFile On
       XSendFilePath /path/to/project/protected_assets
   </IfModule>
   ```

4. Run the verification task to get your exact configuration:
   ```bash
   vendor/bin/sake dev/tasks/SignedAssetUrlVerifyTask
   ```

**How it works**: PHP validates the signed URL, then sends an `X-Sendfile` header with the absolute file path. Apache serves the file directly, bypassing PHP for the actual file transfer.

### Performance Considerations

| Method | Pros | Cons |
|--------|------|------|
| **PHP** (default) | No server config needed, works everywhere | Higher memory usage, slower for large files |
| **Nginx** | Very fast, low memory | Requires nginx config access |
| **Apache** | Fast, works with .htaccess | Requires mod_xsendfile installation |

For most sites, PHP streaming is sufficient. Consider web server handoff if you:
- Serve many large files (videos, archives)
- Have high concurrent download traffic
- Need to minimize PHP memory usage

## Usage

### In PHP

```php
use SilverStripe\Assets\File;

// Get a file and generate signed URL
$file = File::get()->byID(123);
$signedUrl = $file->SignedURL();  // Uses default TTL

// Custom TTL (2 hours)
$signedUrl = $file->SignedURL(7200);

// Session-bound URL (only works for current user's session)
$signedUrl = $file->SignedURL(3600, true);

// Using named policies (see Policies section below)
$signedUrl = $file->AutoURL('ss');  // 30s, session-bound
$signedUrl = $file->AutoURL('m');   // 1 hour, not session-bound

// Check if a file requires signed URLs (useful for conditional logic)
if ($file->requiresSignedURL()) {
    // File is protected - needs signing
}

// Or via the service directly
$service = Injector::inst()->get(AssetUrlSigningService::class);
$signedUrl = $service->generateSignedURL('path/to/file.pdf', 3600);
$signedUrl = $service->generateSignedURL('path/to/file.pdf', 3600, true);  // Session-bound
```

### In Templates

```html
<!-- Default TTL -->
<a href="$MyFile.SignedURL">Download File</a>

<!-- Custom TTL via AutoURL -->
<a href="$MyFile.AutoURL(7200)">Download File (2hr)</a>

<!-- Using named policies (recommended) -->
<a href="$MyFile.AutoURL('ss')">Download (30s, session-bound)</a>
<a href="$MyFile.AutoURL('m')">Download (1hr)</a>
<a href="$MyFile.AutoURL('ls')">Download (24hr, session-bound)</a>

<!-- Auto-detect (signed if protected, normal if public) -->
<img src="$MyImage.AutoURL" alt="$MyImage.Title">

<!-- Conditional logic based on protection status -->
<% if $MyFile.RequiresSignedURL %>
    <a href="$MyFile.SignedURL">Protected Download</a>
<% else %>
    <a href="$MyFile.URL">Public Download</a>
<% end_if %>
```

### Available Methods

| Method                                   | Description                                                     |
|------------------------------------------|-----------------------------------------------------------------|
| `$File.SignedURL`                        | Signed URL with default TTL (returns normal URL for public files)|
| `$File.SignedURL(ttl)`                   | Signed URL with custom TTL in seconds                           |
| `$File.SignedURL(ttl, bindToSession)`    | Signed URL with TTL and session binding                         |
| `$File.AutoURL`                          | Same as SignedURL (auto-detects if signing needed)              |
| `$File.AutoURL('policy')`                | Signed URL using named policy (e.g., 'ss', 'm')            |
| `$File.AutoURL(ttl)`                     | Signed URL with custom TTL in seconds                           |
| `$File.RequiresSignedURL`                | Boolean: true if file is protected                              |

## Policies

Named policies provide convenient presets for TTL and session binding. Use them in templates for cleaner, more maintainable code.

### Built-in Policies

| Policy  | TTL       | Session-bound | Use case                                    |
|---------|-----------|---------------|---------------------------------------------|
| `ss`    | 30 sec    | Yes           | Highly sensitive, immediate use only        |
| `s`     | 30 sec    | No            | Shareable but very short-lived              |
| `ms`    | 1 hour    | Yes           | Sensitive documents, single-user access     |
| `m`     | 1 hour    | No            | General protected content, shareable        |
| `ls`    | 24 hours  | Yes           | Long-lived user-specific access             |
| `l`     | 24 hours  | No            | Long-lived shareable links                  |

### Custom Policies

Define your own policies in YAML config:

```yaml
Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService:
  policies:
    # Override or add policies
    instant: { ttl: 10, session: true }           # 10 seconds, session-bound
    download: { ttl: 300, session: false }        # 5 minutes for downloads
    preview: { ttl: 1800, session: true }         # 30 min preview, session-bound
```

Usage:
```html
<a href="$Document.AutoURL('download')">Download</a>
<img src="$PreviewImage.AutoURL('preview')">
```

## URL Formats

Generated URLs use S3-style query parameters for clean, readable paths:

```
# Standard signed URL
/signed-asset/{path}?s={hash}&e={expires}

# Session-bound signed URL
/signed-asset/{path}?s={hash}&e={expires}&ss=1
```

- `path`: Path to asset (FileFilename in SilverStripe)
- `s`: 16-character HMAC-SHA256 signature
- `e`: Unix timestamp when link expires
- `ss`: Session-bound flag (URL only valid for same session)

Examples:
```
/signed-asset/uploads/documents/report.pdf?s=a1b2c3d4e5f6g7h8&e=1704672000
/signed-asset/uploads/documents/report.pdf?s=a1b2c3d4e5f6g7h8&e=1704672000&ss=1
```

This format keeps the asset path readable (like S3 pre-signed URLs) while signature parameters are in the query string.

## Session Binding

When `bind_to_session` is enabled (globally or per-URL), the signed URL includes a hash of the user's session. This means:

- **URLs are non-transferable**: Sharing the URL won't work for other users
- **Extra security layer**: Even if a URL leaks, it's useless to attackers
- **Use case**: Sensitive documents that should never be shared

The session token is derived from PHP's session ID using HMAC, so the actual session ID is never exposed in the URL.

## How It Works

1. **URL Generation**: PHP generates a signed URL with HMAC hash and expiry timestamp
2. **Request**: User requests the signed URL
3. **Validation**: Controller validates hash and checks expiry
4. **Session Check**: If URL is session-bound, validates against current session
5. **Admin Check**: If user has CMS access, signature validation is bypassed
6. **Serving**: File is streamed via SilverStripe's AssetStore (handles hash-based paths automatically)

## Browser & Page Caching

### Automatic Cache Header Management

By default, this module automatically adjusts the page's `Cache-Control` headers to prevent browsers from caching the page longer than the shortest-lived signed URL it contains.

**How it works:**
1. Middleware tracks the earliest expiry time of all signed URLs generated during a request
2. Before sending the response, it adjusts `Cache-Control: max-age` to not exceed that expiry
3. Also sets an `Expires` header for older HTTP caches

**Configuration:**

```yaml
Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService:
  # Enable/disable automatic cache header adjustment (default: true)
  auto_cache_headers: true
```

**Example:** If you generate a signed URL with 1-hour TTL, the page response will include:
```
Cache-Control: private, max-age=3600
Expires: Tue, 14 Jan 2026 15:30:00 GMT
```

This ensures browsers won't serve a cached page with expired signed URLs.

### Disabling Auto Cache Headers

If you manage cache headers yourself or use a CDN with custom rules:

```yaml
Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService:
  auto_cache_headers: false
```

## Partial Caching (Template Layer)

When using SilverStripe's partial caching (`<% cached %>`), signed URLs require special consideration because they contain expiration timestamps. Note that auto cache headers (above) handle **browser caching**, while this section covers **server-side template caching**.

### The Problem

```html
<%-- BAD: URL will become stale when cache outlives TTL --%>
<% cached 'my-cache-key' %>
    <a href="$Document.SignedURL">Download</a>
<% end_cached %>
```

If your cache lives longer than the signed URL's TTL, users will get expired links.

### Solutions

**1. Include TTL window in cache key:**

```html
<%-- Cache key changes every hour (matches default 3600s TTL) --%>
<% cached 'document', $Document.ID, $Now.Format('Y-m-d-H') %>
    <a href="$Document.SignedURL">Download</a>
<% end_cached %>
```

**2. Set cache lifetime shorter than TTL:**

```html
<%-- Cache expires before signed URL does --%>
<% cached 'document', $Document.ID, 1800 %>
    <a href="$Document.AutoURL('md')">Download</a>
<% end_cached %>
```

**3. Exclude signed URLs from cached blocks:**

```html
<% cached 'page-content', $ID, $LastEdited %>
    <h1>$Title</h1>
    $Content
<% end_cached %>

<%-- Outside cache block --%>
<a href="$Document.SignedURL">Download Document</a>
```

**4. Use session-bound URLs with uncached blocks:**

```html
<% cached 'page-content' %>
    <h1>$Title</h1>
<% end_cached %>

<% uncached %>
    <%-- Session-bound URLs should never be cached anyway --%>
    <a href="$Document.AutoURL('md_sess')">Download</a>
<% end_uncached %>
```

### Cache Key Helpers

You can create a helper method for time-windowed cache keys:

```php
// In your PageController or via extension
public function SignedURLCacheWindow(int $windowSeconds = 3600): string
{
    return floor(time() / $windowSeconds);
}
```

```html
<% cached 'downloads', $ID, $SignedURLCacheWindow(3600) %>
    <a href="$Document.SignedURL">Download</a>
<% end_cached %>
```

### Session-Bound URLs and Caching

**Never cache session-bound URLs** - they are unique per user session:

```html
<%-- BAD: Will serve one user's session-bound URL to everyone --%>
<% cached 'document' %>
    <a href="$Document.AutoURL('md_sess')">Download</a>
<% end_cached %>

<%-- GOOD: Always uncached --%>
<% uncached %>
    <a href="$Document.AutoURL('md_sess')">Download</a>
<% end_uncached %>
```

## Protected Assets & Versioned Staging

SilverStripe's asset system stores files in either a **public** or **protected** folder based on:
- **CanViewType permissions**: Restricted files (LoggedInUsers, OnlyTheseUsers, or inherited from parent)
- **Versioned staging**: Unpublished files when using full Versioned extension

This module respects both protection mechanisms.

### How Protection Works

Protection is checked at **two levels**:

#### 1. URL Generation (Extension)

When you call `$file->SignedURL()`, the extension checks if the file needs a signed URL:

```php
// SignedUrlDBFileExtension::requiresSignedURL()
// Returns true if file requires signed URL

// Check 1: CanViewType restrictions (includes parent folder inheritance)
if ($file->hasRestrictedAccess()) {
    return true;  // Needs signed URL
}

// Check 2: Versioned staging - unpublished files are protected
if ($file->hasExtension(Versioned::class) && !$file->isPublished()) {
    return true;  // Needs signed URL
}

return false;  // Public file, returns normal URL instead
```

This uses SilverStripe's built-in `hasRestrictedAccess()` method which handles CanViewType checking including parent folder inheritance.

#### 2. URL Serving (Controller)

When a signed URL is accessed, the controller validates:
1. **Signature validity**: HMAC hash matches and hasn't expired
2. **Session binding**: If URL is session-bound, validates current session
3. **Published status** (optional): If `check_published_status` is enabled, denies access to unpublished files

CMS users with `bypass_permissions` can always access any file.

### Projects with staging disabled (on File assets)

If your project uses versioning only (no draft/live staging):

```php
// app/_config.php
File::remove_extension(Versioned::class);

// app/_config/app.yml
SilverStripe\Assets\File:
  extensions:
    versioned: SilverStripe\Versioned\Versioned.versioned
```

In this case:
- `isPublished()` always returns true (no staging = always "published")
- Files are only protected based on CanViewType permissions
- You can disable `check_published_status` for a minor performance gain

```yaml
Restruct\SilverStripe\SignedAssetUrls\Services\AssetUrlSigningService:
  # Check published status when serving (default: true)
  # Disable if you don't use Versioned staging on files
  check_published_status: false
```

## Security Considerations

- **Keep your secret safe**: The `ASSET_SIGNING_SECRET` should be long, random, and never committed to version control
- **Use HTTPS**: Signed URLs should be served over HTTPS to prevent interception
- **Set appropriate TTL**: Balance between usability and security
- **Use session binding**: For sensitive documents, enable `bind_to_session`
- **Rotate secrets**: Consider rotating the signing secret periodically
- **Unpublished files**: By default, unpublished files are not accessible via signed URLs (see Protected Assets section)

## Development & Testing

### Verification Task

A BuildTask is included to verify your configuration and test URL generation/validation:

```bash
vendor/bin/sake dev/tasks/SignedAssetUrlVerifyTask
```

This task will:
1. Check that `ASSET_SIGNING_SECRET` is configured
2. Show the protected folder path and whether it exists
3. Display current configuration values (TTL, session binding, cache headers)
4. Generate a test signed URL and validate its components
5. Test signature validation (valid, invalid, and expired cases)
6. Test session-bound URL generation

Example output:
```
=== Signed Asset URLs Configuration Verification ===

1. Environment variable ASSET_SIGNING_SECRET... OK
2. Protected folder path: /path/to/protected_assets... EXISTS

=== Configuration ===
default_ttl: 3600 seconds
bind_to_session: false
auto_cache_headers: true
check_published_status: true

=== Validation Tests ===
Valid signature: PASS
Wrong hash (expect invalid): PASS
Expired URL (expect expired): PASS
```

