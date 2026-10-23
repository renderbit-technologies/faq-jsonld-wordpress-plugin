#!/usr/bin/env bash
# Verify a release zip contains only runtime plugin files.
# Usage: bin/check-zip.sh [renderbit-faq-structured-data.zip]
set -euo pipefail

zip_file="${1:-renderbit-faq-structured-data.zip}"
prefix="renderbit-faq-structured-data/"

if [ ! -f "$zip_file" ]; then
	echo "Zip not found: $zip_file" >&2
	exit 1
fi

entries="$(unzip -Z1 "$zip_file")"
echo "$entries"

status=0

# Every entry must be inside the plugin folder and match the allow-list.
while IFS= read -r entry; do
	case "$entry" in
		"$prefix" | "${prefix}renderbit-faq-structured-data.php" | "${prefix}uninstall.php" | "${prefix}README.md" | "${prefix}readme.txt" | "${prefix}LICENSE.md" | "${prefix}includes/"* | "${prefix}assets/"*) ;;
		*)
			echo "Unexpected path in zip: $entry" >&2
			status=1
			;;
	esac
done <<<"$entries"

# Required files must be present.
for required in renderbit-faq-structured-data.php readme.txt uninstall.php includes/admin.php assets/js/rfsd-admin.js assets/vendor/select2/select2.min.js assets/vendor/select2/select2.js assets/vendor/select2/LICENSE.md LICENSE.md; do
	if ! grep -qx "${prefix}${required}" <<<"$entries"; then
		echo "Missing from zip: ${required}" >&2
		status=1
	fi
done

exit "$status"
