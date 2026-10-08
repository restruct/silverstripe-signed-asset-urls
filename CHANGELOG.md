# Changelog

## 1.2.2 (2026-10-08)

### Fixed

- CMS draft preview: an unpublished image rendered through `SignedURL`/`AutoURL` answered 404,
  also for the CMS user. The image request carries no stage and `Versioned.use_session` is false,
  so it was handled in the Live stage, where a draft-only File does not exist. A user who may view
  draft content (the same check that bypasses signing) now gets a second lookup in the draft
  stage when the live one finds nothing. Visitors and members without CMS access still get 404
  ([#7](https://github.com/restruct/silverstripe-signed-asset-urls/issues/7)).
  Known edge case: an original replaced in draft under the same filename (not yet published) is
  still served with its live content in the draft preview, since the live record is found first.
  Variants are not affected: their URL carries the file hash.
  The draft lookup serves a file only if that user may view it (`canView()`), as core's own
  protected-asset check does: the permissions that allow draft content in general
  (`CMS_ACCESS_CMSMain`, `VIEW_DRAFT_CONTENT`, ...) do not open a draft file restricted to other
  users, on a plain or a masked path. Such a request answers 404.
- A page that renders a signed URL and was sent as `no-store` (`disableCache()`, a form with a
  security token, the CMS, the dev environment's default) got `max-age=N` and an `Expires` in the
  future added to its `Cache-Control`, so the browser could keep it for as long as the URL lived.
  A `no-store` response is now left alone. Where core has not written the header yet (it runs
  outside this module's middleware only if a project reorders `Director.Middlewares`), the
  middleware steers core's cache state (`privateCache()` + `setMaxAge()`, never on a disabled
  state, never raising a shorter max-age) instead of writing `private, max-age=N` over it.
  Without `HTTPCacheControlMiddleware` in the stack at all, nothing else writes the header, so
  the middleware still writes `private, max-age=N` and `Expires` as in 1.2.1
  ([#8](https://github.com/restruct/silverstripe-signed-asset-urls/issues/8)).
- `SignedAssetUrlVerifyTask` run in a browser wrote its messages unescaped, so the Apache hint's
  `<IfModule mod_xsendfile.c>` lines were parsed as tags and not shown. HTML output is now
  escaped on both majors; terminal output is unchanged
  ([#3](https://github.com/restruct/silverstripe-signed-asset-urls/issues/3)).

### Changed

- The warning for an unknown policy name in `AutoURL()`/`MaskedURL()` is logged once per name per
  request instead of on every call, so a list of 50 files with a mistyped policy logs one line,
  not 50. The fallback (default TTL, no session binding) is unchanged
  ([#5](https://github.com/restruct/silverstripe-signed-asset-urls/issues/5)).

## 1.2.1 (2026-10-07)

### Security

- Session-bound signed URLs (`AutoURL('ss')`, `'ms'`, `'ls'`, `SignedURL($ttl, true)`,
  `bind_to_session: true`) were not bound to anything when the visitor had no PHP session yet,
  eg a first-time anonymous visitor. The URL was signed with an empty session token, every other
  browser without a session computed the same empty token, and was served the file until the URL
  expired ([#6](https://github.com/restruct/silverstripe-signed-asset-urls/issues/6)). Now:
  - a session-bound URL is refused (403) to any request without a session;
  - generating one for a visitor without a session starts their session and binds the URL to it,
    so it keeps working for that visitor and for nobody else;
  - a page that renders a session-bound URL gets `Cache-Control: private` (`public` and
    `s-maxage` are removed), so a shared cache never stores it, nor the session cookie it may set.
    A stricter state (`no-store` from `disableCache()`) is kept.
- The signed-asset controller refuses any validation result other than success, not only the two
  it knew by name.

### Changed (behaviour)

- Anonymous visitors of a page that renders a session-bound URL now receive a session cookie, and
  that page is no longer publicly cacheable. Every such visitor, bots included, now creates a
  server-side session, so session storage grows with anonymous traffic to those pages. Use a
  policy without session binding on pages that must stay cacheable or see heavy anonymous traffic.
- A session-bound URL generated where no session can exist (CLI, queued jobs, mail sent from a
  task) now works for nobody, where it used to work for anyone without a session: it is signed
  with a random token, so removing its `ss=1` does not turn it into a shareable URL either. A
  warning is logged once per request. Use a policy without session binding for such URLs.
- Silverstripe 6 only: its default `Session.cookie_samesite` is `Strict`, so a visitor arriving
  from another site (email, search, chat link) sends no session cookie; generating a
  session-bound URL then starts a new session whose cookie replaces the visitor's existing one
  (logged out, session state lost). Set `SilverStripe\Control\Session.cookie_samesite: Lax` on
  Silverstripe 6 sites that use session-bound URLs (see README, "Session Binding").
  Silverstripe 5 defaults to `Lax` and is not affected.
- `SignedAssetUrlVerifyTask` checks its signature round-trip with an unbound URL, so it no longer
  reports a failure on the CLI when `bind_to_session` is true.

## 1.2.0 (2026-09-25)

Adds Silverstripe 6 support. One line now covers Silverstripe 5 and 6 (PHP 8.1+; Silverstripe 6
itself needs 8.3+). Nothing changes for Silverstripe 5 projects: URLs, config, template and PHP
methods are the same as in 1.1.9. See [UPGRADING.md](UPGRADING.md).

### Silverstripe 6 support

- `SignedAssetUrlVerifyTask` redeclared `BuildTask`'s `$title` and `$description`, which
  Silverstripe 6 made typed (and `$description` static). On Silverstripe 6 that is a fatal error
  when the class loads, and the class manifest loads every class on a flush, so the whole
  application failed on its first `dev/build`. The task's entry point now comes from a per-major
  trait (`run()` on 5, `execute()` on 6). Run it with `sake tasks:SignedAssetUrlVerifyTask` on 6;
  `sake dev/tasks/SignedAssetUrlVerifyTask` on 5 is unchanged.
- `composer.json` allows `silverstripe/framework` `^5 || ^6` and `silverstripe/assets` `^2 || ^3`,
  and declares PHP `^8.1`.

### Fixed

- Serving a signed URL called `File::isPublished()` without checking that File has the
  `Versioned` extension. `silverstripe/versioned` is not a dependency of `silverstripe/assets` or
  `recipe-core`, so on a project without it every signed URL failed under the default config
  (`check_published_status: true`). Files without versioning now count as published, as they
  already did when the URL was generated.
- README: the caching examples used the policy names `md` and `md_sess`, which do not exist. An
  unknown policy name falls back to the default TTL **without session binding**, so a template
  copied from the README handed out shareable links where it meant session-bound ones. The
  examples use `m` and `ms` now. If you copied them, check your templates.
- Silverstripe 6: the verify task exited 0 ("completed successfully") even when a check failed,
  eg a missing `ASSET_SIGNING_SECRET`. It now exits non-zero, so `sake ... || alert` works.
  Silverstripe 5's task runner has no exit code for tasks; the output still says `FAIL`.

### Changed (behaviour)

- An unknown policy name in `AutoURL()` / `MaskedURL()` / `MaskedScaleWidthURL()` now logs a
  warning (via the `Psr\Log\LoggerInterface` service). Silverstripe ships that service without a
  handler, so the warning only shows up if your project attaches one. The URL it returns is
  unchanged: default TTL, not session-bound. Turning an unknown name into an error is left for a major version.

### Documentation

- How to generate `ASSET_SIGNING_SECRET`, including Silverstripe's own token generator
  (`sake generatesecuretoken` on 6, `sake dev/generatesecuretoken` on 5) (#1). The module does not
  hook into the generator: any long random string works, and a generic one is enough.
- Requirements, installation, a compatibility table, and how to run the tests.
- Configuring versioning-only files in YAML alone, without `_config.php`.
- The task commands for both majors.

### Tests and CI

- The suite (59 tests in 1.1.9) gains tests for the verify task on both majors (including that
  each failing check makes it report a failure, and on 6 exit non-zero), a regression test for the
  unversioned-file fix, a test of the published-status check against staged (draft/live) File,
  and a test for the unknown-policy warning. It runs on
  Silverstripe 5 and 6 in GitHub Actions, with a consumer-shape database test, a `dev/build` and a
  run of the verify task; a run that finds no tests fails.
- `phpunit.xml.dist` is now for running from a host project (see README); its coverage block,
  which pointed at a `src/` directory that does not exist in a host, is dropped.

### Changed

- Licence: MIT, as `composer.json` already declared; a `LICENSE` file is added.
- `composer.json` has a `funding` entry; `require-dev` is `silverstripe/recipe-testing` instead of
  `phpunit/phpunit`; it suggests `silverstripe/versioned` (optional, as before).
- `/tests`, `.github` and development files are excluded from dist installs (`.gitattributes`).
