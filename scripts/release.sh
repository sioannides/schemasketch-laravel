#!/usr/bin/env bash
# Releases a new version of schemasketch/laravel from the dev branch.
#
#   scripts/release.sh patch     # 0.1.0 -> 0.1.1   bug fixes only
#   scripts/release.sh minor     # 0.1.1 -> 0.2.0   new features, or anything that breaks (while < 1.0)
#   scripts/release.sh major     # 0.2.0 -> 1.0.0   breaking changes (from 1.0 on)
#   add --dry-run to see what would happen without changing anything
#
# Branches: work happens on dev; main only ever holds released versions.
# This script fast-forwards main to dev, dates the changelog, tags vX.Y.Z on
# main, pushes, and brings dev up to date with the release commit.
#
# The version lives only in the git tag (Packagist reads tags; composer.json
# has no "version" field). Each tag must be exactly one step above the last
# one, tests must pass, and CHANGELOG.md's "## Unreleased" section must say
# what changed.
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

# 1. Clean tree; dev and main both pushed, and main can fast-forward to dev.
[ -z "$(git status --porcelain)" ] || fail "commit or stash your changes first."
git fetch --quiet --tags origin
for b in dev main; do
  git rev-parse -q --verify "refs/heads/$b" >/dev/null || fail "there's no local $b branch."
  [ "$(git rev-parse "$b")" = "$(git rev-parse "origin/$b")" ] || fail "$b differs from origin/$b; pull or push it first."
done
git merge-base --is-ancestor main dev || fail "main has commits dev doesn't. Merge main into dev first."
[ "$(git rev-parse main)" != "$(git rev-parse dev)" ] || fail "dev has nothing main doesn't; nothing new to release."

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
if [ "$bump" = major ] && [ "$major" = 1 ]; then
  echo "Note: 1.0.0 promises a stable API; from here on, breaking changes need a major release."
fi

# 3. The changelog on dev must say what's in this release.
changelog=$(git show dev:CHANGELOG.md)
grep -q '^## Unreleased' <<<"$changelog" || fail 'CHANGELOG.md on dev has no "## Unreleased" section.'
notes=$(awk '/^## Unreleased/{f=1;next} /^## /{f=0} f' <<<"$changelog" | sed '/^[[:space:]]*$/d')
[ -n "$notes" ] || fail 'the "## Unreleased" section in CHANGELOG.md is empty. Say what changed, on dev.'

echo "Releasing $latest -> v$next ($bump), $(git rev-list --count main..dev) commit(s) from dev"
echo "$notes" | sed 's/^/  /'

# 4. Move main to dev, then tests and style must pass there.
start=$(git rev-parse --abbrev-ref HEAD)
done=0
# If anything below fails before the push, put main back where origin has
# it (it matched origin/main at the start) and return to the start branch.
undo() {
  [ "$done" = 1 ] && return
  echo "release: stopped; nothing was pushed. Putting main back." >&2
  git tag -d "v$next" >/dev/null 2>&1 || true
  git checkout --quiet main && git reset --quiet --hard origin/main && git checkout --quiet "$start"
}
[ "$dry" = 1 ] || trap undo EXIT
run git checkout --quiet main
run git merge --quiet --ff-only dev
run vendor/bin/pest --compact
run vendor/bin/pint --test

# 5. Date the changelog section (leaving a fresh, empty Unreleased), commit, tag, push.
if [ "$dry" = 1 ]; then
  echo "  would rename \"## Unreleased\" to \"## $next ($(date +%F))\" in CHANGELOG.md"
else
  awk -v heading="## $next ($(date +%F))" \
    '/^## Unreleased/ && !done {print "## Unreleased\n\n" heading; done=1; next} {print}' \
    CHANGELOG.md > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md
fi
run git commit --quiet -am "Release $next"
run git tag -a "v$next" -m "$next"
run git push --quiet origin main "v$next"
done=1

# 6. Bring dev up to date with the release commit.
run git checkout --quiet dev
run git merge --quiet --ff-only main
run git push --quiet origin dev
[ "$dry" = 1 ] || [ "$start" = dev ] || git checkout --quiet "$start"

[ "$dry" = 1 ] && echo "Dry run: nothing changed." || echo "Released v$next. GitHub publishes the release and Packagist picks it up within a minute."
