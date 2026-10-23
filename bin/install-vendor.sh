#!/usr/bin/env bash
#
# Installs the bundled libraries into vendor/, stripped down.
#
# vendor/ is committed to this repository, because the whole point is that the
# repository can be downloaded as a ZIP and installed straight into WordPress —
# shared hosting cannot run Composer. A plain `composer install` produces about
# 72 MB, almost all of it AWS service definitions for APIs this plugin never
# calls. This script installs and then removes everything except SES and SNS,
# which brings it under 10 MB.
#
# Run it after changing composer.json, then commit the result. The stripping
# and the verification both live in bin/strip-vendor.sh and
# bin/verify-vendor.php so the release build does exactly the same thing.
#
#   ./bin/install-vendor.sh
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"



echo "==> Installing production dependencies"
composer install \
  --working-dir="${PLUGIN_DIR}" \
  --no-dev \
  --optimize-autoloader \
  --classmap-authoritative \
  --no-interaction \
  --quiet

VENDOR="${PLUGIN_DIR}/vendor"

echo "==> Stripping to what this plugin uses"
"${PLUGIN_DIR}/bin/strip-vendor.sh" "${VENDOR}"

echo "==> Verifying the send path still works"
php "${PLUGIN_DIR}/bin/verify-vendor.php" "${PLUGIN_DIR}"

echo
echo "Done. Commit vendor/ so the repository stays installable as a ZIP."
