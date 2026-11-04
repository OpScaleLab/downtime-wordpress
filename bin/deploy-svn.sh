#!/usr/bin/env bash
# Pushes a tagged release to the plugin's WordPress.org SVN repo: syncs
# trunk/ to the tag's exported tree (the same files as the release ZIP) and
# copies trunk/ to tags/X.Y.Z/. Run it after the GitHub release.
#
#   bin/deploy-svn.sh v0.2.0 [--dry-run]
#
# SVN_USERNAME defaults to the readme's first Contributor. svn asks for the
# password (your WordPress.org SVN password, set under Account & Security on
# profiles.wordpress.org).
set -euo pipefail
tag="${1:?usage: bin/deploy-svn.sh vX.Y.Z [--dry-run]}"
dry=""; [ "${2:-}" = "--dry-run" ] && dry=1
slug=downtime-uptime-monitoring
version="${tag#v}"
cd "$(dirname "$0")/.."

stable=$(sed -n 's/^Stable tag: *//p' readme.txt)
[ "$stable" = "$version" ] || { echo "readme.txt Stable tag is $stable, not $version" >&2; exit 1; }
user="${SVN_USERNAME:-$(sed -n 's/^Contributors: *//p' readme.txt | cut -d, -f1 | tr -d ' ')}"

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
svn checkout --depth immediates "https://plugins.svn.wordpress.org/$slug" "$work/svn"
svn update --set-depth infinity "$work/svn/trunk"
svn update --set-depth immediates "$work/svn/tags"
[ ! -d "$work/svn/tags/$version" ] || { echo "tags/$version already exists in SVN" >&2; exit 1; }

mkdir -p "$work/export"
git archive --format=tar "$tag" | tar -x -C "$work/export"
rsync -a --delete --exclude=.svn "$work/export/" "$work/svn/trunk/"
(
	cd "$work/svn"
	svn status trunk | awk '/^\?/ {print $2}' | xargs -r svn add
	svn status trunk | awk '/^!/ {print $2}' | xargs -r svn rm
	svn copy trunk "tags/$version"
	svn status
	if [ -n "$dry" ]; then
		echo "dry run: not committing"
	else
		svn commit --username "$user" -m "Release $version"
	fi
)
