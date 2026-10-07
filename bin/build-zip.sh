#!/usr/bin/env bash
#
# Build an installable plugin zip: build/ai-by-roadmap.zip containing a
# top-level ai-by-roadmap/ folder, plus build/notes.md (this version's CHANGELOG
# section). Used by .github/workflows/release.yml; safe to run locally.
#
# Usage: bin/build-zip.sh [tag]   e.g. bin/build-zip.sh v0.3.1
#        With no tag, the plugin header version is used and no tag check runs.
#        PLUGIN_DIR=<path> packages a different checkout (e.g. an older tag that
#        predates this script) using this checkout's .distignore. Output still
#        goes to <this repo>/build.

set -euo pipefail

SLUG="ai-by-roadmap"
TOOLING="$(cd "$(dirname "$0")/.." && pwd)"
ROOT="$(cd "${PLUGIN_DIR:-$TOOLING}" && pwd)"
BUILD="$TOOLING/build"
cd "$ROOT"

header_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$SLUG.php" | tr -d '[:space:]')"
const_version="$(sed -n "s/^const VERSION *= *'\([^']*\)';/\1/p" "$SLUG.php")"

if [ "$header_version" != "$const_version" ]; then
    echo "::error::Version header ($header_version) and const VERSION ($const_version) differ in $SLUG.php" >&2
    exit 1
fi

version="$header_version"
if [ -n "${1:-}" ]; then
    tag_version="${1#v}"
    if [ "$tag_version" != "$version" ]; then
        echo "::error::Tag $1 does not match plugin version $version — bump $SLUG.php before tagging" >&2
        exit 1
    fi
fi

rm -rf "$BUILD"
mkdir -p "$BUILD/$SLUG"

rsync -a --exclude-from="$TOOLING/.distignore" --exclude=/.tooling "$ROOT/" "$BUILD/$SLUG/"

# Rebuild vendor/ from the lockfile, without dev dependencies.
composer install --working-dir="$BUILD/$SLUG" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "$BUILD/$SLUG/composer.json" "$BUILD/$SLUG/composer.lock"

# Unversioned name: keeps it distinct from GitHub's auto-generated
# "Source code" ai-by-roadmap-<version>.zip, whose folder is ai-by-roadmap-<version>/.
(cd "$BUILD" && zip -rq "$SLUG.zip" "$SLUG")

# Release notes: the "## <version>" section of CHANGELOG.md, up to the next "## ".
awk -v v="$version" '
    /^## / { if (found) exit; if (index($0, "## " v " ") == 1 || $0 == "## " v) { found = 1; next } }
    found { print }
' CHANGELOG.md > "$BUILD/notes.md"
if ! grep -q '[^[:space:]]' "$BUILD/notes.md"; then
    echo "See [CHANGELOG.md](CHANGELOG.md)." > "$BUILD/notes.md"
fi
printf '\n---\n\n**Install:** download **`%s.zip`** below and upload it in Plugins → Add New → Upload (choose *Replace current with uploaded* when updating). Do not use the "Source code" downloads — they install as a second copy.\n' "$SLUG" >> "$BUILD/notes.md"

echo "version=$version"
echo "zip=build/$SLUG.zip"
