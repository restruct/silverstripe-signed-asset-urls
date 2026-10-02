# Browser-test targets for this module, sourced by the shared runner
# (~/Sites/0_ss-mods-maintenance/tools/browser/run.sh) and by .github/workflows/browser-tests.yml.
# Plain bash assignments only. CI tests only the targets with an empty SS<n>_SRC_REF (= this
# checkout). main serves both majors.

BROWSER_PACKAGE="restruct/silverstripe-signed-asset-urls"
BROWSER_TARGETS="ss5 ss6"

SS5_RECIPE="^5"
SS5_PHP="8.3"
SS5_PORT="8899"
SS5_SRC_REF=""

SS6_RECIPE="^6"
SS6_PHP="8.3"
SS6_PORT="8900"
SS6_SRC_REF=""

# The module refuses to sign without a secret. A fixed TEST value for the scratch hosts and CI
# only; it signs nothing outside them.
BROWSER_ENV="ASSET_SIGNING_SECRET=browser-test-only-0123456789abcdef0123456789abcdef"
