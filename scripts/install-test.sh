#!/usr/bin/env bash
# Usage: scripts/install-test.sh [ref]
#
# Installs the archive of a commit (default HEAD; what a tag ships, so commit first) and checks that
# it works the way a user runs it:
#
#   1. The archive holds only the paths a release is meant to ship (export-ignore in .gitattributes).
#   2. From the extracted archive itself: composer install --no-dev, then verse init and verse build.
#   3. As a dependency: a fresh Composer project requires astrophp/verse from the archive through
#      a path repository and runs vendor/bin/verse init and build.
#
# Verse reads its version from Composer. The archive has no git history, so both installs are given
# the tag of the commit (v0.0.0 when it has none), as Packagist gives the tag, and `verse --version`
# has to print it.
#
# Everything happens in a temporary folder that is removed at the end (INSTALL_KEEP=1 keeps it).
set -euo pipefail

ref=${1:-HEAD}
root=$(git rev-parse --show-toplevel)
work=$(mktemp -d "${TMPDIR:-/tmp}/verse-install.XXXXXX")
# macOS reports /var as /private/var; keep one spelling
work=$(cd "$work" && pwd -P)

cleanup() {
    if [ "${INSTALL_KEEP:-0}" = 1 ]; then
        printf 'Kept %s\n' "$work"
    else
        rm -rf "$work"
    fi
}
trap cleanup EXIT

fail() {
    printf 'install test: %s\n' "$*" >&2
    exit 1
}

step() {
    printf '\n==> %s\n' "$*"
}

# The top-level paths a release ships. Anything else in the archive is a mistake: add it to
# .gitattributes as export-ignore, or here when it really has to ship.
allowed=(LICENSE README.md bin composer.json composer.lock resources src themes)

step "Archive of $(git -C "$root" rev-parse --short "$ref")"
mkdir -p "$work/package"
git -C "$root" archive --format=tar "$ref" | tar -x -C "$work/package"

unexpected=()
for entry in "$work/package"/* "$work/package"/.[!.]*; do
    [ -e "$entry" ] || continue
    name=$(basename "$entry")
    found=0
    for allow in "${allowed[@]}"; do
        [ "$name" = "$allow" ] && found=1
    done
    [ "$found" = 1 ] || unexpected+=("$name")
done
[ "${#unexpected[@]}" -eq 0 ] || fail "the archive ships paths that are not on the allowlist: ${unexpected[*]}"

for required in bin/verse src/Verse.php resources/scaffold/verse.yaml themes/default/theme.yaml \
    themes/default/dist/.vite/manifest.json themes/default/src/head-theme.js; do
    [ -f "$work/package/$required" ] || fail "the archive is missing $required"
done
printf 'The archive ships: %s\n' "$(cd "$work/package" && ls -A | tr '\n' ' ')"

version=$(git -C "$root" describe --tags --exact-match "$ref" 2> /dev/null || printf 'v0.0.0')

# Fails unless the given verse command prints the version Composer was given, without its "v".
check_version() {
    local printed
    printed=$("$@" --version) || fail "$* --version failed"
    [ "$printed" = "Verse ${version#v}" ] || fail "$* --version printed \"$printed\", expected \"Verse ${version#v}\""
}

# Builds a new website with the given verse command and checks the output.
check_site() {
    local site=$1
    shift

    (cd "$work" && "$@" init "$site") || fail "verse init $site failed"
    [ -f "$work/$site/verse.yaml" ] || fail "verse init did not create $site/verse.yaml"

    (cd "$work/$site" && "$@" build) || fail "verse build failed in $site"

    for file in index.html articles/index.html articles/hello-verse/index.html about/index.html \
        tags/index.html tags/verse/index.html 404.html search.json .verse-output; do
        [ -f "$work/$site/dist/$file" ] || fail "verse build did not write $site/dist/$file"
    done
    grep -q 'Hello, Verse' "$work/$site/dist/articles/hello-verse/index.html" \
        || fail "$site/dist/articles/hello-verse/index.html does not contain the article"
    ls "$work/$site/dist/assets/theme/"app-*.css > /dev/null 2>&1 || fail "$site/dist has no theme stylesheet"
}

step 'Run from the extracted archive'
(cd "$work/package" && COMPOSER_ROOT_VERSION=$version composer install --no-dev --prefer-dist --no-interaction --no-progress)
check_version php "$work/package/bin/verse"
check_site from-archive php "$work/package/bin/verse"

step 'Install as a Composer dependency'
mkdir -p "$work/project"
cat > "$work/project/composer.json" <<JSON
{
    "repositories": [
        {"type": "path", "url": "$work/package", "options": {"symlink": false, "versions": {"astrophp/verse": "$version"}}}
    ],
    "minimum-stability": "dev",
    "prefer-stable": true
}
JSON
# The package archive keeps its own vendor/ from the step above; a path repository copies the folder,
# so give it a clean one.
rm -rf "$work/package/vendor"
(cd "$work/project" && composer require "astrophp/verse:$version" --prefer-dist --no-interaction --no-progress)
[ -x "$work/project/vendor/bin/verse" ] || fail 'composer did not install vendor/bin/verse'
check_version "$work/project/vendor/bin/verse"
check_site from-dependency "$work/project/vendor/bin/verse"

printf '\nInstall test passed for Verse %s.\n' "${version#v}"
