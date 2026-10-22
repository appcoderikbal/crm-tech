#!/usr/bin/env bash
#
# Cuts a release: bump the version, build the ZIP, tag it, and publish a
# GitHub Release with the built ZIP attached.
#
#   ./bin/release.sh 1.0.1
#   ./bin/release.sh 1.0.1 --notes "Fixes the campaign report totals"
#   ./bin/release.sh 1.0.1 --dry-run
#
# The attached ZIP is what WordPress installs. The source archive GitHub
# generates automatically is NOT usable: vendor/ is git-ignored, so that
# archive has no AWS SDK and the plugin would fatal after updating. The
# updater therefore ignores releases with no attached ZIP rather than
# installing a broken one.
#
# Needs the GitHub CLI (gh) authenticated, or GITHUB_TOKEN set.
#
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REPO_DIR="$(cd "${PLUGIN_DIR}/.." && pwd)"
MAIN_FILE="${PLUGIN_DIR}/techzapp-crm.php"
REPO_SLUG="appcoderikbal/crm-tech"

VERSION="${1:-}"
NOTES=""
DRY_RUN=0

shift || true
while [ $# -gt 0 ]; do
  case "$1" in
    --notes)   NOTES="${2:-}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    *)         echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

if [ -z "${VERSION}" ]; then
  CURRENT=$(grep -oE "^ \* Version: +[0-9.]+" "${MAIN_FILE}" | grep -oE "[0-9.]+$")
  echo "Usage: ./bin/release.sh <version> [--notes \"…\"] [--dry-run]" >&2
  echo "Current version: ${CURRENT}" >&2
  exit 1
fi

if ! printf '%s' "${VERSION}" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+$'; then
  echo "Version must look like 1.0.1" >&2
  exit 1
fi

CURRENT=$(grep -oE "^ \* Version: +[0-9.]+" "${MAIN_FILE}" | grep -oE "[0-9.]+$")

echo "==> Releasing ${CURRENT} -> ${VERSION}"

# ---------------------------------------------------------------- preflight
if [ "${DRY_RUN}" -eq 0 ]; then
  if [ -n "$(git -C "${REPO_DIR}" status --porcelain)" ]; then
    echo "Working tree is dirty. Commit or stash first." >&2
    exit 1
  fi

  if git -C "${REPO_DIR}" rev-parse "v${VERSION}" >/dev/null 2>&1; then
    echo "Tag v${VERSION} already exists. Releases are immutable; pick a new version." >&2
    exit 1
  fi
fi

# ------------------------------------------------------------ version bump
echo "==> Updating version strings"
# Two places must agree: the plugin header WordPress reads, and the VERSION
# constant the updater compares against.
sed -i.bak -E "s/^( \* Version: +)[0-9.]+/\1${VERSION}/" "${MAIN_FILE}"
sed -i.bak -E "s/^(const VERSION = ')[0-9.]+(';)/\1${VERSION}\2/" "${MAIN_FILE}"
rm -f "${MAIN_FILE}.bak"

HEADER=$(grep -oE "^ \* Version: +[0-9.]+" "${MAIN_FILE}" | grep -oE "[0-9.]+$")
CONST=$(grep -oE "^const VERSION = '[0-9.]+'" "${MAIN_FILE}" | grep -oE "[0-9.]+")

if [ "${HEADER}" != "${VERSION}" ] || [ "${CONST}" != "${VERSION}" ]; then
  echo "Version bump failed (header=${HEADER} const=${CONST})." >&2
  exit 1
fi
echo "    header and VERSION constant both ${VERSION}"

# ------------------------------------------------------------------- build
echo "==> Building the release ZIP"
bash "${PLUGIN_DIR}/bin/build-release.sh" >/dev/null
ZIP="${REPO_DIR}/build/techzapp-crm.zip"

if [ ! -f "${ZIP}" ]; then
  echo "Build produced no ZIP." >&2
  exit 1
fi
echo "    $(du -h "${ZIP}" | cut -f1) at ${ZIP}"

# Confirm the ZIP carries the version WordPress will compare against.
PACKAGED=$(unzip -p "${ZIP}" techzapp-crm/techzapp-crm.php 2>/dev/null | grep -oE "^ \* Version: +[0-9.]+" | grep -oE "[0-9.]+$" || true)
if [ -n "${PACKAGED}" ] && [ "${PACKAGED}" != "${VERSION}" ]; then
  echo "The packaged plugin reports ${PACKAGED}, expected ${VERSION}." >&2
  exit 1
fi

if [ "${DRY_RUN}" -eq 1 ]; then
  echo
  echo "Dry run: nothing committed, tagged or published."
  echo "Revert the version bump with: git -C ${REPO_DIR} checkout -- techzapp-crm/techzapp-crm.php"
  exit 0
fi

# ------------------------------------------------------------ commit + tag
echo "==> Committing and tagging"
git -C "${REPO_DIR}" add "${MAIN_FILE}"
git -C "${REPO_DIR}" commit -q -m "Release ${VERSION}"
git -C "${REPO_DIR}" tag -a "v${VERSION}" -m "Version ${VERSION}"
git -C "${REPO_DIR}" push -q origin HEAD
git -C "${REPO_DIR}" push -q origin "v${VERSION}"
echo "    pushed commit and tag v${VERSION}"

# ---------------------------------------------------------------- publish
echo "==> Publishing the GitHub release"

if [ -z "${NOTES}" ]; then
  PREVIOUS=$(git -C "${REPO_DIR}" describe --tags --abbrev=0 "v${VERSION}^" 2>/dev/null || true)
  if [ -n "${PREVIOUS}" ]; then
    NOTES=$(git -C "${REPO_DIR}" log --no-merges --pretty='- %s' "${PREVIOUS}..v${VERSION}")
  else
    NOTES="First packaged release."
  fi
fi

NOTES="${NOTES}

Tested up to: $(grep -oE 'Requires at least: +[0-9.]+' "${MAIN_FILE}" | grep -oE '[0-9.]+$' || echo '6.8')

Install or update from within WordPress: the plugin checks this repository and
offers the attached ZIP through the normal update screen."

if command -v gh >/dev/null 2>&1; then
  gh release create "v${VERSION}" "${ZIP}" \
    --repo "${REPO_SLUG}" \
    --title "v${VERSION}" \
    --notes "${NOTES}"
elif [ -n "${GITHUB_TOKEN:-}" ]; then
  echo "    gh not found; using the REST API"

  RELEASE_JSON=$(curl -sS -X POST \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    "https://api.github.com/repos/${REPO_SLUG}/releases" \
    -d "$(php -r 'echo json_encode(["tag_name"=>$argv[1],"name"=>$argv[1],"body"=>$argv[2]]);' "v${VERSION}" "${NOTES}")")

  UPLOAD_URL=$(printf '%s' "${RELEASE_JSON}" | php -r '$d=json_decode(stream_get_contents(STDIN),true); echo str_replace("{?name,label}","",$d["upload_url"] ?? "");')

  if [ -z "${UPLOAD_URL}" ]; then
    echo "Could not create the release:" >&2
    printf '%s\n' "${RELEASE_JSON}" | head -20 >&2
    exit 1
  fi

  curl -sS -X POST \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H "Content-Type: application/zip" \
    --data-binary "@${ZIP}" \
    "${UPLOAD_URL}?name=techzapp-crm.zip" >/dev/null
else
  echo
  echo "No gh CLI and no GITHUB_TOKEN, so the release was not published." >&2
  echo "The commit and tag v${VERSION} are pushed. Finish by hand:" >&2
  echo "  1. https://github.com/${REPO_SLUG}/releases/new?tag=v${VERSION}" >&2
  echo "  2. Attach ${ZIP}" >&2
  echo "  3. Publish" >&2
  echo >&2
  echo "WordPress will not offer the update until the ZIP is attached." >&2
  exit 1
fi

echo
echo "Released v${VERSION}."
echo "Sites running this plugin will see the update within 6 hours, or"
echo "immediately via Plugins -> Techzapp CRM -> Check for updates."
