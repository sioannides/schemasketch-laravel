#!/usr/bin/env bash
# Releases a new version of schemasketch/laravel.
#
#   scripts/release.sh patch     # 0.1.0 -> 0.1.1   bug fixes only
#   scripts/release.sh minor     # 0.1.1 -> 0.2.0   new features, or anything that breaks (while < 1.0)
#   scripts/release.sh major     # 0.2.0 -> 1.0.0   breaking changes (from 1.0 on)
#   add --dry-run to see what would happen without changing anything
#
# The version lives only in the git tag (Packagist reads tags; composer.json
# has no "version" field). This script makes sure each tag is exactly one
# step above the last one, that tests pass, and that CHANGELOG.md says what
# changed: the "## Unreleased" section becomes "## X.Y.Z (date)".
set -euo pipefail

usage() { sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

bump="" dry=0
for arg in "$@"; do
  case "$arg" in
    patch|minor|major) bump="$arg" ;;
    --dry-run) dry=1 ;;
    *) usage ;;
  esac
done
[ -n "$bump" ] || usage

cd "$(git rev-parse --show-toplevel)"
fail() { echo "release: $*" >&2; exit 1; }
run() { if [ "$dry" = 1 ]; then echo "  would run: $*"; else "$@"; fi; }

# 1. Start from an up-to-date, clean main.
[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || fail "switch to the main branch first."
[ -z "$(git status --porcelain)" ] || fail "commit or stash your changes first."
git fetch --quiet --tags origin
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || fail "main differs from origin/main; pull or push first."

# 2. Work out the next version from the highest existing tag.
latest=$(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -n1)
latest=${latest:-v0.0.0}
IFS=. read -r major minor patch <<<"${latest#v}"
case "$bump" in
  patch) patch=$((patch + 1)) ;;
  minor) minor=$((minor + 1)); patch=0 ;;
  major) major=$((major + 1)); minor=0; patch=0 ;;
esac
next="$major.$minor.$patch"

git rev-parse -q --verify "refs/tags/v$next" >/dev/null && fail "v$next already exists."
[ "$(printf '%s\n%s\n' "${latest#v}" "$next" | sort -V | tail -n1)" = "$next" ] || fail "v$next is not above $latest."
[ "$(git describe --tags --exact-match HEAD 2>/dev/null || true)" = "" ] || fail "this commit is already released as $(git describe --tags --exact-match HEAD); nothing new to release."
if [ "$bump" = major ] && [ "$major" = 1 ]; then
  echo "Note: 1.0.0 promises a stable API; from here on, breaking changes need a major release."
fi

# 3. The changelog must say what's in this release.
grep -q '^## Unreleased' CHANGELOG.md || fail 'CHANGELOG.md has no "## Unreleased" section. Add one listing the changes.'
notes=$(awk '/^## Unreleased/{f=1;next} /^## /{f=0} f' CHANGELOG.md | sed '/^[[:space:]]*$/d')
[ -n "$notes" ] || fail 'the "## Unreleased" section in CHANGELOG.md is empty. Say what changed.'

echo "Releasing $latest -> v$next ($bump)"
echo "$notes" | sed 's/^/  /'

# 4. Tests and style must pass.
run vendor/bin/pest --compact
run vendor/bin/pint --test

# 5. Date the changelog section, commit, tag, push.
if [ "$dry" = 1 ]; then
  echo "  would rename \"## Unreleased\" to \"## $next ($(date +%F))\" in CHANGELOG.md"
else
  sed -i.bak "s/^## Unreleased.*/## $next ($(date +%F))/" CHANGELOG.md && rm -f CHANGELOG.md.bak
  # A fresh, empty Unreleased section for the next round of changes.
  awk -v v="## $next" 'index($0, v)==1 && !done {print "## Unreleased\n"; done=1} {print}' CHANGELOG.md > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md
fi
run git commit --quiet -am "Release $next"
run git tag -a "v$next" -m "$next"
run git push --quiet origin main "v$next"

[ "$dry" = 1 ] && echo "Dry run: nothing changed." || echo "Released v$next. GitHub creates the release and Packagist picks it up within a minute."
