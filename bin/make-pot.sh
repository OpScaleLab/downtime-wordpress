#!/usr/bin/env bash
# Regenerates languages/downtime-uptime-monitoring.pot from the source, then
# merges it into every bundled .po and recompiles the .mo files. The
# block editor's languages/*-<md5 of blocks/status/index.js>.json files are
# not rebuilt here; update them by hand when the block's strings change.
set -euo pipefail
cd "$(dirname "$0")/.."
domain=downtime-uptime-monitoring
xgettext --language=PHP --from-code=UTF-8 --add-comments=translators \
	-k__ -k_e -kesc_html__ -kesc_html_e -kesc_attr__ -kesc_attr_e -k_n:1,2 -k_x:1,2c \
	-o /tmp/$domain-php.pot downtime.php includes/*.php
xgettext --language=JavaScript --from-code=UTF-8 --add-comments=translators \
	-k__ -k_n:1,2 -k_x:1,2c -o /tmp/$domain-js.pot blocks/status/index.js
msgcat --no-wrap --use-first /tmp/$domain-php.pot /tmp/$domain-js.pot -o languages/$domain.pot
rm -f /tmp/$domain-php.pot /tmp/$domain-js.pot
for po in languages/*.po; do
	msgmerge --quiet --update --backup=none --no-wrap "$po" languages/$domain.pot
	msgfmt --check -o "${po%.po}.mo" "$po"
	msgfmt --statistics -o /dev/null "$po"
done
