#!/usr/bin/env bash
# Docker environment matrix: installs the BUILT plugin zip into real WordPress sites
# across PHP x WordPress x database x host-profile combinations (tests/matrix/profiles.tsv).
#
#   tests/matrix/run.sh                    # all profiles, sequentially
#   tests/matrix/run.sh php81 multisite    # only these profiles
#   tests/matrix/run.sh --jobs 2           # run 2 profiles in parallel (more can overload Docker Desktop)
#   tests/matrix/run.sh --keep restricted  # leave containers up for debugging
#
# Needs Docker. Logs: dist/matrix-logs/<profile>.log
# Registry / Docker-engine hiccups (pull EOF, HTTP 500 from the daemon) are retried automatically.
set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)
export MSYS_NO_PATHCONV=1 # Git Bash on Windows: keep /src style paths intact
MOUNT=$ROOT
command -v cygpath >/dev/null 2>&1 && MOUNT=$(cygpath -m "$ROOT")

KEEP=0
JOBS=1
SELECT=()
while [ $# -gt 0 ]; do
	case "$1" in
		--keep) KEEP=1 ;;
		--jobs) shift; JOBS=${1:-1} ;;
		all) ;;
		-*) echo "unknown option $1" >&2; exit 2 ;;
		*) SELECT+=("$1") ;;
	esac
	shift
done

command -v docker >/dev/null 2>&1 || { echo "run.sh: Docker is required." >&2; exit 1; }
docker info >/dev/null 2>&1 || { echo "run.sh: the Docker daemon is not running." >&2; exit 1; }

echo "== building the plugin zip"
sh "$ROOT/bin/build-zip.sh" || exit 1

LOGS="$ROOT/dist/matrix-logs"
rm -rf "$LOGS"
mkdir -p "$LOGS"

RUN_ID=$$
# Real S3 server for the destination checks (step 4b). MinIO no longer publishes pullable images, so SeaweedFS is used: it
# verifies SigV4 signatures and implements multipart upload and ListParts. Test credentials only, never reachable from outside.
S3_IMAGE=chrislusf/seaweedfs:3.97
S3_ACCESS=ovbmatrixaccess
S3_SECRET=ovbmatrixsecret0123456789
S3_JSON='{"identities":[{"name":"ovb","credentials":[{"accessKey":"'$S3_ACCESS'","secretKey":"'$S3_SECRET'"}],"actions":["Admin","Read","Write","List","Tagging"]}]}'
CREATED=()
INFRA_ERRORS='error pulling image|unexpected EOF|request returned 500|TLS handshake timeout|connection reset by peer|failed to copy|i/o timeout'

cleanup() {
	[ "$KEEP" = 1 ] && return
	for name in "${CREATED[@]:-}"; do
		[ -n "$name" ] && { docker rm -f "$name" >/dev/null 2>&1; docker network rm "$name" >/dev/null 2>&1; }
	done
}
trap cleanup EXIT

# docker pull with retries (the public registry drops connections now and then).
pull_retry() {
	local image=$1 i
	docker image inspect "$image" >/dev/null 2>&1 && return 0
	for i in 1 2 3 4 5; do
		docker pull -q "$image" >/dev/null 2>&1 && return 0
		echo "--- pull of $image failed (attempt $i), retrying"
		sleep $((i * 5))
	done
	return 1
}

# One attempt at one profile. Writes its report to stdout.
run_once() {
	local id=$1 php=$2 wp=$3 db=$4 variant=$5 info=$6 base=$7
	local image="ovb-matrix-php$php" ini="default" lower=""
	[ "$variant" = restricted ] && ini=restricted
	[ "$variant" = lowmem ] && ini=lowmem
	# Informational profiles test WordPress versions below the supported minimum: lower the minimum in a copy of the zip.
	[ "$info" = yes ] && lower=$wp

	echo "### profile=$id php=$php wp=$wp db=$db variant=$variant"
	if ! docker image inspect "$image" >/dev/null 2>&1; then
		echo "--- building image $image"
		docker build -q -t "$image" --build-arg PHP_VERSION="$php" "$MOUNT/tests/matrix" || { echo "CONTAINER FAIL: image build"; return 1; }
	fi
	pull_retry "$db" || { echo "CONTAINER FAIL: could not pull $db (error pulling image)"; return 1; }
	pull_retry "$S3_IMAGE" || { echo "CONTAINER FAIL: could not pull $S3_IMAGE (error pulling image)"; return 1; }
	docker network create "$base" >/dev/null || return 1
	# OVB_MATRIX_DOCKER_ARGS (e.g. "--cpus=0.5") slows containers down to reproduce slow-host timing bugs.
	# shellcheck disable=SC2086
	docker run -d --name "$base-db" --network "$base" --network-alias db ${OVB_MATRIX_DOCKER_ARGS:-} \
		-e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=wp "$db" >/dev/null || { echo "CONTAINER FAIL: db start"; return 1; }
	# The S3 server may start slowly; container-run.sh waits for it. A server that does not work shows up as failing s3 checks, never as a skip.
	docker run -d --name "$base-s3" --network "$base" --network-alias s3srv -e S3_CONFIG="$S3_JSON" --entrypoint sh "$S3_IMAGE" -c \
		'printf "%s" "$S3_CONFIG" > /tmp/s3.json && exec weed server -dir=/data -s3 -s3.config=/tmp/s3.json -master.volumeSizeLimitMB=128 -ip=s3srv' \
		>/dev/null || { echo "CONTAINER FAIL: s3 server start"; return 1; }
	# shellcheck disable=SC2086
	docker run -d --name "$base-web" --network "$base" ${OVB_MATRIX_DOCKER_ARGS:-} \
		-e WP_VERSION="$wp" -e VARIANT="$variant" -e DB_HOST=db -e LOWER_MIN_WP="$lower" \
		-e OVB_S3_ENDPOINT=http://s3srv:8333 -e OVB_S3_ACCESS="$S3_ACCESS" -e OVB_S3_SECRET="$S3_SECRET" \
		-v "$MOUNT:/src:ro" \
		-v "$MOUNT/tests/matrix/php-$ini.ini:/usr/local/etc/php/conf.d/zz-profile.ini:ro" \
		"$image" sleep infinity >/dev/null || { echo "CONTAINER FAIL: web start"; return 1; }
	docker exec "$base-web" bash /src/tests/matrix/container-run.sh
}

run_profile() {
	local id=$1 php=$2 wp=$3 db=$4 variant=$5 info=$6
	local base="ovb-$id-$RUN_ID" log="$LOGS/$id.log" attempt status=1

	for attempt in 1 2 3; do
		run_once "$id" "$php" "$wp" "$db" "$variant" "$info" "$base" >"$log" 2>&1
		status=$?
		[ "$status" = 0 ] && break
		docker rm -f "$base-web" "$base-db" "$base-s3" >/dev/null 2>&1
		docker network rm "$base" >/dev/null 2>&1
		if grep -qE "$INFRA_ERRORS" "$log"; then
			echo "attempt $attempt hit a Docker/registry error, retrying" >>"$LOGS/$id.retries"
			sleep 15
			continue
		fi
		break
	done

	echo "$status" >"$LOGS/$id.status"
	if [ "$KEEP" = 1 ] && [ "$status" != 0 ]; then
		return "$status" # leave the failing containers up for inspection (already removed above only on retry)
	fi
	docker rm -f "$base-web" "$base-db" "$base-s3" >/dev/null 2>&1
	docker network rm "$base" >/dev/null 2>&1
	return "$status"
}

declare -a IDS INFO
while read -r id php wp db variant info; do
	case "$id" in ''|\#*) continue ;; esac
	if [ ${#SELECT[@]} -gt 0 ]; then
		match=0
		for s in "${SELECT[@]}"; do [ "$s" = "$id" ] && match=1; done
		[ "$match" = 1 ] || continue
	fi
	IDS+=("$id")
	INFO+=("$info")
	CREATED+=("ovb-$id-$RUN_ID-web" "ovb-$id-$RUN_ID-db" "ovb-$id-$RUN_ID-s3")
	echo "== starting $id (PHP $php, WP $wp, $db, $variant)"
	while [ "$(jobs -rp | wc -l)" -ge "$JOBS" ]; do wait -n 2>/dev/null || true; done
	run_profile "$id" "$php" "$wp" "$db" "$variant" "$info" &
done <"$HERE/profiles.tsv"
wait

echo
echo "== results"
printf '%-16s %-8s %-6s %-6s %s\n' PROFILE RESULT PASS FAIL NOTE
overall=0
for i in "${!IDS[@]}"; do
	id=${IDS[$i]}
	status=$(cat "$LOGS/$id.status" 2>/dev/null || echo 99)
	pass=$(grep -c '^PASS ' "$LOGS/$id.log" 2>/dev/null)
	nfail=$(grep -c '^FAIL \|^CONTAINER FAIL' "$LOGS/$id.log" 2>/dev/null)
	version=$(grep -m1 '^--- WordPress ' "$LOGS/$id.log" | sed 's/^--- //')
	retries=""
	[ -f "$LOGS/$id.retries" ] && retries=" (retried $(wc -l <"$LOGS/$id.retries" | tr -d ' ')x after infra errors)"
	if [ "$status" = 0 ]; then
		result=PASS
	elif [ "${INFO[$i]}" = yes ]; then
		result="FAIL*"
	else
		result=FAIL
		overall=1
	fi
	printf '%-16s %-8s %-6s %-6s %s%s\n' "$id" "$result" "$pass" "$nfail" "$version" "$retries"
done
echo "(* = informational profile, does not fail the run)"
echo "logs: dist/matrix-logs/"

# Show WHY a profile failed right in the output (CI keeps the full logs only as a downloadable artifact).
for i in "${!IDS[@]}"; do
	id=${IDS[$i]}
	[ "$(cat "$LOGS/$id.status" 2>/dev/null || echo 99)" = 0 ] && continue
	[ "${INFO[$i]}" = yes ] && continue
	echo
	echo "== why $id failed (first failing lines, then the end of its log)"
	grep -E '^FAIL |^CONTAINER FAIL|Fatal error|PHP Fatal|Allowed memory|Maximum execution time' "$LOGS/$id.log" | head -25
	echo "-- last 15 log lines"
	tail -n 15 "$LOGS/$id.log"
done
exit "$overall"
