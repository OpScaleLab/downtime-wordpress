#!/usr/bin/env bash
# Builds the installable plugin ZIP from a git ref (default HEAD):
# a single top-level downtime-uptime-monitoring/ folder, matching the
# WordPress.org slug, without the export-ignore'd dev files.
set -euo pipefail
ref="${1:-HEAD}"
slug=downtime-uptime-monitoring
cd "$(dirname "$0")/.."
git archive --format=zip --prefix="$slug/" -o "$slug.zip" "$ref"
echo "$slug.zip"
