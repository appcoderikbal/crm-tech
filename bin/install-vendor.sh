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
# Run it after changing composer.json, then commit the result.
#
#   ./bin/install-vendor.sh
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# AWS services this plugin actually uses.
KEEP_SERVICES=(ses sesv2 sns sts)

# AWS SDK source directories to keep. Everything else is a client for a service
# we never construct.
KEEP_SRC="Ses|SesV2|Sns|Sts|Api|Credentials|Endpoint|EndpointV2|Exception|Handler|Multipart|Retry|Signature|ClientSideMonitoring|Token|Auth|Configuration|DefaultsMode|EndpointDiscovery|Script|data"

echo "==> Installing production dependencies"
composer install \
  --working-dir="${PLUGIN_DIR}" \
  --no-dev \
  --optimize-autoloader \
  --classmap-authoritative \
  --no-interaction \
  --quiet

VENDOR="${PLUGIN_DIR}/vendor"
before=$(du -sm "${VENDOR}" | cut -f1)

echo "==> Stripping unused AWS service definitions"
AWS_DATA="${VENDOR}/aws/aws-sdk-php/src/data"
if [ -d "${AWS_DATA}" ]; then
  find "${AWS_DATA}" -mindepth 1 -maxdepth 1 -type d | while read -r dir; do
    name="$(basename "${dir}")"
    keep=0
    for service in "${KEEP_SERVICES[@]}"; do
      if [ "${name}" = "${service}" ]; then
        keep=1
      fi
    done
    # An "if" rather than "[ … ] && rm": under set -e a false test as the last
    # command in the loop body aborts the whole script.
    if [ "${keep}" -eq 0 ]; then
      rm -rf "${dir}"
    fi
  done

  find "${VENDOR}/aws/aws-sdk-php/src" -mindepth 1 -maxdepth 1 -type d | while read -r dir; do
    name="$(basename "${dir}")"
    if ! printf '%s' "${name}" | grep -qE "^(${KEEP_SRC})$"; then
      rm -rf "${dir}"
    fi
  done
fi

echo "==> Removing development cruft"
find "${VENDOR}" \( \
  -name 'tests' -o -name 'test' -o -name 'docs' -o -name 'examples' -o \
  -name '.github' -o -name '*.md' -o -name 'phpunit.xml*' -o -name '.gitignore' \
  \) -prune -exec rm -rf {} + 2>/dev/null || true

after=$(du -sm "${VENDOR}" | cut -f1)
echo "    vendor: ${before} MB -> ${after} MB"

echo "==> Verifying the bundle still loads"
( cd "${PLUGIN_DIR}" && php -r "
  require 'vendor/autoload.php';
  \$missing = [];
  foreach ([
    'Aws\\\\SesV2\\\\SesV2Client',
    'Aws\\\\Sns\\\\MessageValidator',
    'Aws\\\\Sns\\\\Message',
    'HTMLPurifier',
  ] as \$class) {
    if (!class_exists(\$class)) { \$missing[] = \$class; }
  }
  if (\$missing) {
    fwrite(STDERR, '    MISSING: ' . implode(', ', \$missing) . PHP_EOL);
    exit(1);
  }
  echo '    all required classes present' . PHP_EOL;
" )

echo
echo "Done. Commit vendor/ so the repository stays installable as a ZIP."
