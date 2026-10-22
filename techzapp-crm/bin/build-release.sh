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
BUILD_DIR="$(dirname "${PLUGIN_DIR}")/build"
STAGE="${BUILD_DIR}/techzapp-crm"

# AWS services this plugin actually uses. Everything else is removed.
KEEP_SERVICES=(ses sesv2 sns sts)

echo "==> Cleaning ${BUILD_DIR}"
rm -rf "${BUILD_DIR}"
mkdir -p "${STAGE}"

echo "==> Copying plugin files"
# tar rather than rsync: rsync is absent on some build machines (notably Git
# Bash on Windows), tar is not.
( cd "${PLUGIN_DIR}" && tar -cf - \
    --exclude='./vendor' \
    --exclude='./bin' \
    --exclude='./tests' \
    --exclude='./node_modules' \
    --exclude='*.zip' \
    --exclude='.DS_Store' \
    . ) | ( cd "${STAGE}" && tar -xf - )

echo "==> Installing production dependencies"
composer install \
  --working-dir="${STAGE}" \
  --no-dev \
  --optimize-autoloader \
  --classmap-authoritative \
  --no-interaction \
  --quiet

echo "==> Stripping unused AWS service definitions"
AWS_DATA="${STAGE}/vendor/aws/aws-sdk-php/src/data"
if [ -d "${AWS_DATA}" ]; then
  before=$(du -sm "${STAGE}/vendor" | cut -f1)

  find "${AWS_DATA}" -mindepth 1 -maxdepth 1 -type d | while read -r dir; do
    name="$(basename "${dir}")"
    keep=0
    for service in "${KEEP_SERVICES[@]}"; do
      [ "${name}" = "${service}" ] && keep=1
    done
    [ "${keep}" -eq 0 ] && rm -rf "${dir}"
  done

  # Per-service PHP clients we never construct.
  find "${STAGE}/vendor/aws/aws-sdk-php/src" -mindepth 1 -maxdepth 1 -type d | while read -r dir; do
    name="$(basename "${dir}")"
    case "${name}" in
      Ses|SesV2|Sns|Sts|Api|Credentials|Endpoint|EndpointV2|Exception|Handler|Multipart|Retry|Signature|ClientSideMonitoring|Token|Auth|Configuration|DefaultsMode|EndpointDiscovery|Script|data)
        ;;
      *)
        rm -rf "${dir}"
        ;;
    esac
  done

  after=$(du -sm "${STAGE}/vendor" | cut -f1)
  echo "    vendor: ${before} MB -> ${after} MB"
fi

echo "==> Removing development cruft from vendor"
find "${STAGE}/vendor" \( \
  -name 'tests' -o -name 'test' -o -name 'docs' -o -name 'examples' -o \
  -name '.github' -o -name '*.md' -o -name 'phpunit.xml*' -o -name '.gitignore' \
  \) -prune -exec rm -rf {} + 2>/dev/null || true

echo "==> Verifying the bundle still loads"
# Run from inside the stage directory with a relative require: an absolute
# POSIX path is not resolvable by a native Windows PHP.
( cd "${STAGE}" && php -r "
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
