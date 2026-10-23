#!/usr/bin/env bash
#
# Builds the distributable plugin ZIP.
#
# Shared hosting cannot run Composer, so the libraries ship inside the ZIP.
# They are not in git, because the AWS SDK is ~70 MB of service definitions for
# APIs this plugin never calls — this script installs it and then deletes
# everything except SES and SNS, which takes the bundle to a few megabytes.
#
#   ./bin/build-release.sh           -> build/techzapp-crm.zip
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${PLUGIN_DIR}/build"
STAGE="${BUILD_DIR}/techzapp-crm"


echo "==> Cleaning ${BUILD_DIR}"
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE}"

echo "==> Copying plugin files"
# tar rather than rsync: rsync is absent on some build machines (notably Git
# Bash on Windows), tar is not.
# vendor/ is deliberately NOT excluded: it is committed to this repository,
# already stripped, and has to ship inside the ZIP. build/ and .git must be
# excluded or tar recurses into its own output.
( cd "${PLUGIN_DIR}" && tar -cf - \
    --exclude='./build' \
    --exclude='./.git' \
    --exclude='./bin' \
    --exclude='./tests' \
    --exclude='./node_modules' \
    --exclude='*.zip' \
    --exclude='docker-compose*.yml' \
    --exclude='.DS_Store' \
    . ) | ( cd "${STAGE}" && tar -xf - )

# vendor/ is committed to this repository, already stripped by
# bin/install-vendor.sh, and was copied above. Nothing to install here.

echo "==> Stripping to what this plugin uses"
"${PLUGIN_DIR}/bin/strip-vendor.sh" "${STAGE}/vendor"

echo "==> Verifying the send path still works"
# Runs against the staged copy, not the working tree: the thing shipped is the
# thing proved.
php "${PLUGIN_DIR}/bin/verify-vendor.php" "${STAGE}"

echo "==> Creating ZIP"
if command -v zip >/dev/null 2>&1; then
  ( cd "${BUILD_DIR}" && zip -rq techzapp-crm.zip techzapp-crm )
  echo "    packed with zip"
else
  # No zip binary (normal on Windows). The helper also verifies the entry
  # paths, because an archive built here can look fine locally and still be
  # unusable on a Linux server.
  php "${PLUGIN_DIR}/bin/make-zip.php" "${STAGE}" "${BUILD_DIR}/techzapp-crm.zip"
fi

echo
echo "Built: ${BUILD_DIR}/techzapp-crm.zip ($(du -h "${BUILD_DIR}/techzapp-crm.zip" | cut -f1))"
echo "Upload it under Plugins -> Add New -> Upload Plugin."
