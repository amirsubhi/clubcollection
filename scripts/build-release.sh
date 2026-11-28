#!/usr/bin/env bash
# Builds the cPanel release zip: dist/clubportal-<version>.zip (+ .sha256).
#
#   scripts/build-release.sh <version> [git-ref]      # ref defaults to HEAD
#
# The zip is built from a clean `git archive` export of <git-ref> in a temporary folder, never from
# this working tree, so a local .env, the demo SQLite database, storage/ contents or node_modules can't
# end up in it. Inside the export it runs `composer install --no-dev -o`, `npm ci && npm run build` and
# the licence generator, removes dev-only files, writes release.json and release-files.json, zips one top-level folder
# `clubportal-<version>/`. The host then needs no Composer, Node or git (docs/RELEASE.md).
#
# Also writes dist/clubportal-manifest.json (the in-app updater's manifest; scripts/release-manifest.php).
#
# Needs: git, PHP 8.4 + Composer, Node 22 + npm, zip, sha256sum.
# Env:   COMPOSER_FLAGS  extra composer flags (e.g. --ignore-platform-req=php on an older local PHP;
#                        never in CI)
#        OUT_DIR         where the zip goes (default: <repo>/dist, git-ignored)
set -euo pipefail

# Reads zip entry paths (one per line, "<top folder>/<path>") on stdin and prints those that must never
# ship. The top folder is stripped first, so the patterns don't depend on the version string.
forbidden_entries() {
  sed -E 's#^[^/]+/##' \
    | grep -E '^(node_modules|tests|mockup|scripts|\.github)(/|$)|^public/(hot|storage)(/|$)|(^|/)\.env$|(^|/)\.env\.[^/]*$|\.sqlite(-journal|-wal|-shm)?$|(^|/)\.git(/|$)|(^|/)\.installed$|^storage/.*[^/]$|^bootstrap/cache/.*[^/]$|(^|/)auth\.json$' \
    | grep -vE '(^|/)\.gitignore$|(^|/)\.env\.example$' \
    || true
}

# Test hook: `build-release.sh --check-list < paths` prints forbidden paths, exit 1 when there are any.
if [[ "${1:-}" == "--check-list" ]]; then
  BAD="$(forbidden_entries)"
  [[ -z "$BAD" ]] || { echo "$BAD"; exit 1; }
  exit 0
fi

usage() { echo "usage: $0 <version> [git-ref]   (e.g. $0 2.0.0 v2.0.0)" >&2; exit 2; }
[[ $# -ge 1 && $# -le 2 ]] || usage

VERSION="${1#v}"
REF="${2:-HEAD}"
[[ "$VERSION" =~ ^[0-9A-Za-z][0-9A-Za-z.-]*$ ]] || { echo "Invalid version: $1 (letters, digits, dots and dashes)" >&2; exit 2; }

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${OUT_DIR:-$REPO/dist}"
mkdir -p "$OUT_DIR"
OUT_DIR="$(cd "$OUT_DIR" && pwd)"   # absolute, before any cd below
NAME="clubportal-$VERSION"

# Dev/build-only paths dropped from the release (relative to the app folder). Everything else in git
# ships: app, bootstrap, config, database (migrations + seeders the installer needs), lang, public,
# resources, routes, the storage/ skeleton (only its .gitignore files), artisan, composer.json/.lock
# (Laravel reads composer.json), docs and the front-end sources/configs (small, not web-reachable).
REMOVE_PATHS=(
  node_modules tests mockup scripts .github
  phpunit.xml .phpunit.result.cache CLAUDE.MD
  public/hot public/storage
  docs/superpowers
)

COMMIT="$(git -C "$REPO" rev-parse --verify "$REF^{commit}")"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/clubportal-release.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
APP="$WORK/$NAME"

echo "==> Exporting $REF ($COMMIT) to $APP"
git -C "$REPO" archive --format=tar --prefix="$NAME/" "$COMMIT" | tar -x -C "$WORK"
# .gitattributes marks CHANGELOG.md export-ignore; the release should still carry it (upgrade notes).
git -C "$REPO" show "$COMMIT:CHANGELOG.md" > "$APP/CHANGELOG.md"

cd "$APP"

echo "==> composer install --no-dev"
# shellcheck disable=SC2086 # COMPOSER_FLAGS is a space-separated list of flags
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist ${COMPOSER_FLAGS:-}

echo "==> npm ci && npm run build"
npm ci --no-audit --no-fund
npm run build

echo "==> Regenerating licence files"
cp docs/THIRD-PARTY-LICENSES.md "$WORK/licenses.md.committed"
cp public/licenses.txt "$WORK/licenses.txt.committed"
node scripts/licenses.mjs
if ! cmp -s docs/THIRD-PARTY-LICENSES.md "$WORK/licenses.md.committed" || ! cmp -s public/licenses.txt "$WORK/licenses.txt.committed"; then
  echo "WARNING: the committed licence files are out of date. The zip carries the regenerated ones;" >&2
  echo "         run 'npm run licenses' and commit docs/THIRD-PARTY-LICENSES.md and public/licenses.txt." >&2
fi

# The manifest and file-list code lives in scripts/, which the next step removes: keep a copy.
cp -R scripts "$WORK/release-scripts"

echo "==> Removing dev-only files"
rm -rf "${REMOVE_PATHS[@]}"
# Local secrets and databases (none should be in git, but never ship them): every .env* except the example.
find . -name '.env*' ! -name '.env.example' -prune -exec rm -rf {} +
# (vendor/ included: a few packages carry *.sqlite test fixtures.)
find . -type f \( -name '*.sqlite' -o -name '*.sqlite-journal' -o -name '*.sqlite-wal' -o -name '*.sqlite-shm' \) -delete
# Composer falls back to git clones when a dist download fails; never ship their .git folders.
find vendor -name .git -prune -exec rm -rf {} +
# Runtime state: keep only the skeleton (folders + their .gitignore files).
find storage -type f ! -name .gitignore -delete
find bootstrap/cache -type f ! -name .gitignore -delete

# The lowest PHP from the shared requirements list (/install, app:doctor and the public/index.php guard read it too).
PHP_REQ="$(php -r '$r = require "bootstrap/requirements.php"; echo ">=" . $r["php"];')"
cat > release.json <<JSON
{
    "version": "$VERSION",
    "commit": "$COMMIT",
    "built_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "php_requirement": "$PHP_REQ"
}
JSON

# Every shipped file with its SHA-256 (the updater verifies an extract with it and finds files a release removed).
php -r 'require $argv[1]; file_put_contents("release-files.json", json_encode(release_files($argv[2], "."), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");' \
  "$WORK/release-scripts/lib/release_manifest.php" "$VERSION"

echo "==> Checking the tree"
for required in vendor/autoload.php public/build/manifest.json bootstrap/requirements.php bootstrap/preflight.php artisan .env.example storage/app/private/.gitignore storage/framework/views/.gitignore bootstrap/cache/.gitignore; do
  [[ -e "$required" ]] || { echo "Missing from the release: $required" >&2; exit 1; }
done
# The guard in public/index.php must refuse exactly what Composer's platform check refuses.
PLATFORM_ID="$(grep -soE 'PHP_VERSION_ID >= [0-9]+' vendor/composer/platform_check.php | grep -oE '[0-9]+$' || true)"
REQ_ID="$(php -r '$r = require "bootstrap/requirements.php"; $p = explode(".", $r["php"]); echo $p[0] * 10000 + $p[1] * 100 + $p[2];')"
if [[ -z "$PLATFORM_ID" ]]; then
  echo "WARNING: no PHP version in vendor/composer/platform_check.php (COMPOSER_FLAGS?); not compared with bootstrap/requirements.php." >&2
elif [[ "$PLATFORM_ID" != "$REQ_ID" ]]; then
  echo "bootstrap/requirements.php says PHP $PHP_REQ, but Composer's platform check needs PHP_VERSION_ID >= ${PLATFORM_ID:-?}: make them match." >&2
  exit 1
fi
# The example .env must not carry a real key or installer token.
if grep -qE '^(APP_KEY|INSTALL_TOKEN)=[^[:space:]#]' .env.example; then
  echo ".env.example sets APP_KEY or INSTALL_TOKEN; they must be empty in the release." >&2
  exit 1
fi

ZIP="$OUT_DIR/$NAME.zip"
rm -f "$ZIP" "$ZIP.sha256"
# -y stores symlinks as links instead of following them out of the tree.
(cd "$WORK" && zip -r -y -q -X "$ZIP" "$NAME")

# Belt and braces: refuse a zip that carries anything that must never ship.
BAD="$(unzip -Z1 "$ZIP" | forbidden_entries)"
if [[ -n "$BAD" ]]; then
  echo "Refusing the zip, it contains:" >&2
  echo "$BAD" >&2
  rm -f "$ZIP"
  exit 1
fi

# The manifest the in-app updater reads (fixed name, so releases/latest/download/ always points at the newest).
# It fails the build when the CHANGELOG has no section or no "What's new" bullets for this version.
rm -f "$OUT_DIR/clubportal-manifest.json"
php "$WORK/release-scripts/release-manifest.php" "$VERSION" "$ZIP" "$OUT_DIR/clubportal-manifest.json" "$APP" \
  || { rm -f "$ZIP"; exit 1; }

(cd "$OUT_DIR" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")
echo "==> Built $ZIP ($(du -h "$ZIP" | cut -f1))"
cat "$ZIP.sha256"
echo "==> Manifest $OUT_DIR/clubportal-manifest.json"
