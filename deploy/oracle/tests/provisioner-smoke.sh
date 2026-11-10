#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

MOCK_BIN="$TMP_DIR/bin"
TENANTS_DIR="$TMP_DIR/tenants"
mkdir -p "$MOCK_BIN"
chmod 700 "$TMP_DIR"

cat > "$MOCK_BIN/docker" <<'DOCKER'
#!/usr/bin/env bash
set -Eeuo pipefail

if [[ "$1" == "compose" && "${2:-}" == "version" ]]; then
  exit 0
fi

case "${1:-}:${2:-}" in
  ps:-aq|volume:ls|network:ls)
    if [[ -n "${MOCK_DOCKER_RESOURCE_OUTPUT:-}" ]]; then
      printf '%s\n' "$MOCK_DOCKER_RESOURCE_OUTPUT"
    fi
    exit 0
    ;;
  compose:*)
    printf '%s\n' "$*" >> "$MOCK_DOCKER_CALL_LOG"
    exit 0
    ;;
  *)
    echo "Unexpected mocked Docker invocation: $*" >&2
    exit 90
    ;;
esac
DOCKER
chmod +x "$MOCK_BIN/docker"

cat > "$MOCK_BIN/openssl" <<'OPENSSL'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "${1:-}" == "rand" && "${2:-}" == "-hex" ]] || exit 91
case "${3:-}" in
  24) printf '%048d\n' 0 | tr '0' 'a' ;;
  32) printf '%064d\n' 0 | tr '0' 'b' ;;
  *) exit 92 ;;
esac
OPENSSL
chmod +x "$MOCK_BIN/openssl"

cat > "$MOCK_BIN/ss" <<'SS'
#!/usr/bin/env bash
exit 0
SS
chmod +x "$MOCK_BIN/ss"

export PATH="$MOCK_BIN:$PATH"
export WALLZA_MAUTIC_TENANTS_DIR="$TENANTS_DIR"
export MOCK_DOCKER_CALL_LOG="$TMP_DIR/docker-calls.log"

# Port values are normalized as decimal (08080 -> 8080).
bash "$SCRIPT_DIR/provision-tenant.sh" pilot 08080 > "$TMP_DIR/provision.log"
ENV_FILE="$TENANTS_DIR/pilot/.env"
[[ -f "$ENV_FILE" ]]
grep -Fxq 'MAUTIC_HTTP_PORT=8080' "$ENV_FILE"
grep -Fxq 'COMPOSE_PROJECT_NAME=wallza-mautic-pilot' "$ENV_FILE"
[[ "$(stat -c '%a' "$TENANTS_DIR/pilot")" == "700" ]]
[[ "$(stat -c '%a' "$ENV_FILE")" == "600" ]]
grep -Fq 'wallza-mautic-pilot' "$MOCK_DOCKER_CALL_LOG"

# Existing tenant secrets must never be overwritten.
if bash "$SCRIPT_DIR/provision-tenant.sh" pilot 8081 > "$TMP_DIR/overwrite.log" 2>&1; then
  echo "Expected existing tenant env to be protected from overwrite." >&2
  exit 1
else
  status=$?
  [[ "$status" == "3" ]]
fi
grep -Fxq 'MAUTIC_HTTP_PORT=8080' "$ENV_FILE"

# If the env file is lost but Compose resources remain, provisioning must stop.
rm "$ENV_FILE"
export MOCK_DOCKER_RESOURCE_OUTPUT="orphaned-volume"
if bash "$SCRIPT_DIR/provision-tenant.sh" pilot 8080 > "$TMP_DIR/orphan.log" 2>&1; then
  echo "Expected orphan Docker resources to block tenant reprovisioning." >&2
  exit 1
else
  status=$?
  [[ "$status" == "3" ]]
fi
[[ ! -e "$ENV_FILE" ]]
grep -Fq "Docker resources already exist" "$TMP_DIR/orphan.log"

# Invalid public ports must be rejected before Docker is invoked.
if bash "$SCRIPT_DIR/provision-tenant.sh" invalid-port 80 > "$TMP_DIR/port.log" 2>&1; then
  echo "Expected invalid port to be rejected." >&2
  exit 1
else
  status=$?
  [[ "$status" == "2" ]]
fi

echo "Tenant provisioner smoke tests passed."
