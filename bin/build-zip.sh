#!/usr/bin/env sh
# Builds the installable plugin zip: dist/openvault-backup-<version>.zip (+ dist/openvault-backup.zip, + .sha256).
#
# What goes in the zip is controlled by .distignore. Needs `zip` and GNU `tar`;
# when they are missing (e.g. Windows) the script re-runs itself inside Docker (alpine).
#
#   sh bin/build-zip.sh
set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd)
SLUG=openvault-backup

if ! command -v zip >/dev/null 2>&1 || ! tar --version 2>/dev/null | grep -qi 'gnu tar'; then
	command -v docker >/dev/null 2>&1 || { echo "build-zip: need zip + GNU tar, or Docker." >&2; exit 1; }
	MOUNT=$ROOT
	command -v cygpath >/dev/null 2>&1 && MOUNT=$(cygpath -m "$ROOT")
	MSYS_NO_PATHCONV=1 exec docker run --rm -v "$MOUNT:/src" -w /src alpine:3 \
		sh -c 'apk add --no-cache zip tar >/dev/null && sh bin/build-zip.sh'
fi

VERSION=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$ROOT/$SLUG.php" | head -n1 | tr -d '\r')
[ -n "$VERSION" ] || { echo "build-zip: could not read Version from $SLUG.php" >&2; exit 1; }

DIST="$ROOT/dist"
STAGE="$DIST/stage"
rm -rf "$STAGE"
mkdir -p "$STAGE/$SLUG"

tar -C "$ROOT" --exclude-from="$ROOT/.distignore" -cf - . | tar -C "$STAGE/$SLUG" -xf -

ZIP="$DIST/$SLUG-$VERSION.zip"
rm -f "$ZIP" "$DIST/$SLUG.zip"
(cd "$STAGE" && zip -qr -X "$ZIP" "$SLUG")
cp "$ZIP" "$DIST/$SLUG.zip"
rm -rf "$STAGE"

# Sanity checks: dev files must not ship, runtime files must.
LISTING=$(unzip -l "$ZIP")
for forbidden in tests vendor node_modules website bin .github docs/plan; do
	if printf '%s\n' "$LISTING" | grep -q " $SLUG/$forbidden/"; then
		echo "build-zip: FAIL, '$forbidden/' is in the zip" >&2
		exit 1
	fi
done
for forbidden in composer.json CLAUDE.md phpstan.neon .phpcs.xml; do
	if printf '%s\n' "$LISTING" | grep -q " $SLUG/$forbidden\$"; then
		echo "build-zip: FAIL, '$forbidden' is in the zip" >&2
		exit 1
	fi
done
for required in "$SLUG.php" readme.txt LICENSE includes/Core/Plugin.php lib/action-scheduler/action-scheduler.php; do
	if ! printf '%s\n' "$LISTING" | grep -q " $SLUG/$required\$"; then
		echo "build-zip: FAIL, '$required' is missing from the zip" >&2
		exit 1
	fi
done

(cd "$DIST" && sha256sum "$SLUG-$VERSION.zip" > "$SLUG-$VERSION.zip.sha256")
echo "build-zip: OK  dist/$SLUG-$VERSION.zip  ($(wc -c < "$ZIP" | tr -d ' ') bytes)"
