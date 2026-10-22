# usage: chrome.sh script.js   (runs in ovb-chrome on the demo network)
export MSYS_NO_PATHCONV=1
S="$(cd "$(dirname "$0")" && pwd -W 2>/dev/null || pwd)"
docker run --rm --network ${OVB_DEMO:-ovb-w3r}-net -e NODE_PATH=/nm -v "$S/../../node_modules:/nm:ro" -v "$S:/w3" -w /w3 ovb-chrome node "$@"
