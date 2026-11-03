#!/usr/bin/env bash
# Releases a new version of schemasketch/laravel, in two steps with a review
# in between:
#
#   scripts/release.sh patch     # 0.1.0 -> 0.1.1   bug fixes only
#   scripts/release.sh minor     # 0.1.1 -> 0.2.0   new features, or anything that breaks (while < 1.0)
#   scripts/release.sh major     # 0.2.0 -> 1.0.0   breaking changes (from 1.0 on)
#       Prepares the release on dev: checks, tests, dates the changelog,
#       commits "Release X.Y.Z", pushes dev and opens a pull request to main.
#
#   scripts/release.sh tag
#       After that pull request is merged: tags vX.Y.Z on main, pushes the
#       tag (GitHub then checks it and publishes the release) and brings dev
#       up to date with main.
#
#   add --dry-run to see what would happen without changing anything
#
# Branches: work happens on dev; main only changes through reviewed pull
# requests, and tags are only ever made on main. The version lives only in
# the tag (Packagist reads tags; composer.json has no "version" field), and
# each tag must be exactly one step above the last.
set -euo pipefail

usage() { sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }

mode="" dry=0
for arg in "$@"; do
  case "$arg" in
    patch|minor|major|tag) mode="$arg" ;;
    --dry-run) dry=1 ;;
    *) usage ;;
  esac
done
[ -n "$mode" ] || usage

cd "$(git rev-parse --show-toplevel)"
fail() { echo "release: $*" >&2; exit 1; }
run() { if [ "$dry" = 1 ]; then echo "  would run: $*"; else "$@"; fi; }

[ -z "$(git status --porcelain)" ] || fail "commit or stash your changes first."
git fetch --quiet --tags origin
latest=$(git tag --list 'v[0-9]*.[0-9]*.[0-9]*' --sort=-v:refname | head -n1)
latest=${latest:-v0.0.0}
above() { [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n1)" = "$2" ] && [ "$1" != "$2" ]; }

# ---------------------------------------------------------------------------
# Step 2: tag the merged release on main.
# ---------------------------------------------------------------------------
if [ "$mode" = tag ]; then
  # The newest dated section of main's changelog is the release to tag.
  version=$(git show origin/main:CHANGELOG.md | sed -n 's/^## \([0-9][0-9.]*\) (.*/\1/p' | head -n1)
  [ -n "$version" ] || fail "main's CHANGELOG.md has no dated release section. Has the release pull request been merged?"
  git rev-parse -q --verify "refs/tags/v$version" >/dev/null && fail "main's newest release, v$version, is already tagged. If you prepared a new one, merge its pull request first."
  above "${latest#v}" "$version" || fail "v$version is not above $latest."
  git log --format=%s origin/main | grep -x "Release $version" >/dev/null \
    || fail "main has no \"Release $version\" commit yet. Merge the release pull request first."

  echo "Tagging v$version on main ($(git rev-parse --short origin/main))"
  run git tag -a "v$version" -m "$version" origin/main
  run git push --quiet origin "v$version"

  # dev catches up with main (the merge commit), so the next pull request is clean.
  start=$(git rev-parse --abbrev-ref HEAD)
  run git checkout --quiet dev
  run git merge --quiet --ff-only origin/main
  run git push --quiet origin dev
  [ "$dry" = 1 ] || [ "$start" = dev ] || git checkout --quiet "$start"

  [ "$dry" = 1 ] && echo "Dry run: nothing changed." \
    || echo "Tagged v$version. GitHub checks the tag and publishes the release; Packagist picks it up within a minute."
  exit 0
fi

# ---------------------------------------------------------------------------
# Step 1: prepare the release on dev and open the pull request.
# ---------------------------------------------------------------------------
[ "$(git rev-parse --abbrev-ref HEAD)" = dev ] || fail "switch to the dev branch first."
[ "$(git rev-parse dev)" = "$(git rev-parse origin/dev)" ] || fail "dev differs from origin/dev; pull or push it first."
git merge-base --is-ancestor origin/main dev || fail "main has commits dev doesn't. Run: git merge origin/main (on dev) first."
[ "$(git rev-parse origin/main)" != "$(git rev-parse dev)" ] || fail "dev has nothing main doesn't; nothing new to release."

IFS=. read -r major minor patch <<<"${latest#v}"
case "$mode" in
  patch) patch=$((patch + 1)) ;;
  minor) minor=$((minor + 1)); patch=0 ;;
  major) major=$((major + 1)); minor=0; patch=0 ;;
esac
next="$major.$minor.$patch"
git rev-parse -q --verify "refs/tags/v$next" >/dev/null && fail "v$next already exists."
above "${latest#v}" "$next" || fail "v$next is not above $latest."
if [ "$mode" = major ] && [ "$major" = 1 ]; then
  echo "Note: 1.0.0 promises a stable API; from here on, breaking changes need a major release."
fi

grep -q '^## Unreleased' CHANGELOG.md || fail 'CHANGELOG.md has no "## Unreleased" section.'
notes=$(awk '/^## Unreleased/{f=1;next} /^## /{f=0} f' CHANGELOG.md | sed '/^[[:space:]]*$/d')
[ -n "$notes" ] || fail 'the "## Unreleased" section in CHANGELOG.md is empty. Say what changed.'

echo "Preparing $latest -> v$next ($mode), $(git rev-list --count origin/main..dev) commit(s) on dev"
echo "$notes" | sed 's/^/  /'

run vendor/bin/pest --compact
run vendor/bin/pint --test

if [ "$dry" = 1 ]; then
  echo "  would rename \"## Unreleased\" to \"## $next ($(date +%F))\" in CHANGELOG.md"
else
  awk -v heading="## $next ($(date +%F))" \
    '/^## Unreleased/ && !done {print "## Unreleased\n\n" heading; done=1; next} {print}' \
    CHANGELOG.md > CHANGELOG.md.tmp && mv CHANGELOG.md.tmp CHANGELOG.md
fi
run git commit --quiet -am "Release $next"
run git push --quiet origin dev

body="Release $next. Merge with a merge commit (not squash), then run \`scripts/release.sh tag\`.

$notes"
if [ "$dry" = 1 ]; then
  echo "  would open a pull request dev -> main titled \"Release $next\""
  echo "Dry run: nothing changed."
else
  existing=$(gh pr list --base main --head dev --state open --json number --jq '.[0].number' 2>/dev/null || true)
  if [ -n "$existing" ]; then
    gh pr edit "$existing" --title "Release $next" --body "$body" >/dev/null
    url=$(gh pr view "$existing" --json url --jq .url)
  else
    url=$(gh pr create --base main --head dev --title "Release $next" --body "$body")
  fi
  echo "Pull request ready: $url"
  echo "Review and merge it (merge commit, not squash), then run: scripts/release.sh tag"
fi
