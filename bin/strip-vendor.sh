#!/usr/bin/env bash
#
# Reduces vendor/ to what this plugin actually needs, then proves it still
# works. Shared by bin/install-vendor.sh and bin/build-release.sh.
#
#   ./bin/strip-vendor.sh <vendor-dir>
#
# A plain `composer install` is ~74 MB, almost all of it support for AWS
# services this plugin never touches: 50 MB of JSON service definitions under
# src/data, and 19 MB of per-service PHP clients.
#
# Directories are classified by structure rather than by a hand-written list of
# names. An earlier version kept an allowlist of directories to preserve, which
# silently deleted Aws\Identity — 12 KB of infrastructure that nothing
# references until the moment a message is signed. The result installed
# cleanly, passed a class_exists check, and then failed on the first real send
# with "Class Aws\Identity\AwsCredentialIdentity not found". Removing only what
# is positively identified as an unused service client cannot fail that way:
# anything unrecognised is kept.
#
set -euo pipefail

VENDOR="${1:?usage: strip-vendor.sh <vendor-dir>}"
SDK="${VENDOR}/aws/aws-sdk-php/src"

# AWS services this plugin constructs a client for.
KEEP_SERVICES=(Ses SesV2 Sns Sts)
KEEP_DATA=(ses sesv2 sns sts)

if [ ! -d "${SDK}" ]; then
  echo "    no AWS SDK at ${SDK}; nothing to strip"
  exit 0
fi

before=$(du -sm "${VENDOR}" | cut -f1)

# --- service definitions -----------------------------------------------------
# src/data holds one directory per AWS service. Only the four above are read.
if [ -d "${SDK}/data" ]; then
  find "${SDK}/data" -mindepth 1 -maxdepth 1 -type d | while read -r dir; do
    name="$(basename "${dir}")"
    keep=0
    for service in "${KEEP_DATA[@]}"; do
      if [ "${name}" = "${service}" ]; then keep=1; fi
    done
    if [ "${keep}" -eq 0 ]; then
      rm -rf "${dir}"
    fi
  done
fi

# --- per-service clients -----------------------------------------------------
# A service client directory is identifiable: it contains <Name>Client.php.
# Infrastructure directories (Api, Credentials, Identity, Retry, Signature, …)
# do not, so they are never considered for removal.
find "${SDK}" -mindepth 1 -maxdepth 1 -type d | while read -r dir; do
  name="$(basename "${dir}")"

  if [ "${name}" = "data" ]; then
    continue
  fi

  # Not a service client -> infrastructure -> keep, whatever it is called.
  if [ ! -f "${dir}/${name}Client.php" ]; then
    continue
  fi

  keep=0
  for service in "${KEEP_SERVICES[@]}"; do
    if [ "${name}" = "${service}" ]; then keep=1; fi
  done

  if [ "${keep}" -eq 0 ]; then
    rm -rf "${dir}"
  fi
done

# --- development cruft -------------------------------------------------------
find "${VENDOR}" \( \
  -name 'tests' -o -name 'test' -o -name 'docs' -o -name 'examples' -o \
  -name '.github' -o -name '*.md' -o -name 'phpunit.xml*' -o -name '.gitignore' \
  \) -prune -exec rm -rf {} + 2>/dev/null || true

after=$(du -sm "${VENDOR}" | cut -f1)
echo "    vendor: ${before} MB -> ${after} MB"
