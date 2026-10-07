#!/usr/bin/env bash
#
# Build an installable plugin zip: build/ai-by-roadmap-<version>.zip containing a
# top-level ai-by-roadmap/ folder, plus build/notes.md (this version's CHANGELOG
# section). Used by .github/workflows/release.yml; safe to run locally.
#
# Usage: bin/build-zip.sh [tag]   e.g. bin/build-zip.sh v0.3.1
#        With no tag, the plugin header version is used and no tag check runs.

set -euo pipefail

SLUG="ai-by-roadmap"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/build"
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

rsync -a --exclude-from="$ROOT/.distignore" "$ROOT/" "$BUILD/$SLUG/"

# Rebuild vendor/ from the lockfile, without dev dependencies.
composer install --working-dir="$BUILD/$SLUG" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "$BUILD/$SLUG/composer.json" "$BUILD/$SLUG/composer.lock"

(cd "$BUILD" && zip -rq "$SLUG-$version.zip" "$SLUG")

# Release notes: the "## <version>" section of CHANGELOG.md, up to the next "## ".
awk -v v="$version" '
    /^## / { if (found) exit; if (index($0, "## " v " ") == 1 || $0 == "## " v) { found = 1; next } }
    found { print }
' CHANGELOG.md > "$BUILD/notes.md"
if ! grep -q '[^[:space:]]' "$BUILD/notes.md"; then
    echo "See [CHANGELOG.md](CHANGELOG.md)." > "$BUILD/notes.md"
fi

echo "version=$version"
echo "zip=build/$SLUG-$version.zip"
