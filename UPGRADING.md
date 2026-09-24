# Upgrading

## To 1.2

1.2 supports Silverstripe 5 and 6 from one line. On Silverstripe 5 nothing changes: URLs already
handed out stay valid, and config, templates and PHP calls are the same as in 1.1.9.

```bash
composer require restruct/silverstripe-signed-asset-urls:^1.2
```

A `~1.1.9` constraint does not allow 1.2; widen it as above.

### Moving a project to Silverstripe 6

- The verify task is `vendor/bin/sake tasks:SignedAssetUrlVerifyTask` (was
  `vendor/bin/sake dev/tasks/SignedAssetUrlVerifyTask`). In a browser it stays at
  `/dev/tasks/SignedAssetUrlVerifyTask`.
- Nothing else in this module needs changing. `ASSET_SIGNING_SECRET`, `ASSET_FILE_SERVER`, the
  `AssetUrlSigningService` config and the `/signed-asset/` route are the same.

### Check your templates

The README's caching examples used `AutoURL('md')` and `AutoURL('md_sess')`. Neither policy
exists: both give a URL with the default TTL and **no** session binding. If you copied them, use
`AutoURL('m')` (1 hour) or `AutoURL('ms')` (1 hour, session-bound). From 1.2.0 an unknown policy
name logs a warning, so your error log shows any that are left.

### Scripts that run the verify task

On Silverstripe 6, `sake tasks:SignedAssetUrlVerifyTask` now exits non-zero when a check fails.
A script that ran it and ignored the result keeps working; one using `set -e` now stops there,
which is the point.
