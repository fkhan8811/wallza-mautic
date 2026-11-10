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
bash "$SCRIPT_DIR/provision-tenant.sh" pilot 08080 "e48b9f6f-9c4c-4c3d-aeab-c6ed3f0ef999" > "$TMP_DIR/provision.log"
ENV_FILE="$TENANTS_DIR/pilot/.env"
[[ -f "$ENV_FILE" ]]
grep -Fxq 'MAUTIC_HTTP_PORT=8080' "$ENV_FILE"
grep -Fxq 'COMPOSE_PROJECT_NAME=wallza-mautic-pilot' "$ENV_FILE"
grep -Fxq 'MAUTIC_WORKERS_CONSUME_EMAIL=1' "$ENV_FILE"
grep -Fxq 'MAUTIC_WORKERS_CONSUME_HIT=1' "$ENV_FILE"
grep -Fxq 'MAUTIC_WORKERS_CONSUME_FAILED=1' "$ENV_FILE"
grep -Fxq 'WALLZA_MAUTIC_TENANT_ID=e48b9f6f-9c4c-4c3d-aeab-c6ed3f0ef999' "$ENV_FILE"
grep -Fxq 'WALLZA_SSO_ISSUER=https://api.wallza.in' "$ENV_FILE"
grep -Eq "^WALLZA_SSO_HMAC_SECRET=[a-f0-9]{64}$" "$ENV_FILE"
[[ "$(stat -c '%a' "$TENANTS_DIR/pilot")" == "700" ]]
[[ "$(stat -c '%a' "$ENV_FILE")" == "600" ]]
grep -Fq 'wallza-mautic-pilot' "$MOCK_DOCKER_CALL_LOG"
! grep -Fq "$(printf '%064d' 0 | tr '0' 'b')" "$TMP_DIR/provision.log"

LOCK_ROOT="$TENANTS_DIR/.provisioning-locks"
[[ ! -e "$LOCK_ROOT/tenant-pilot.lock" ]]
[[ ! -e "$LOCK_ROOT/port-8080.lock" ]]

# A tenant lock prevents a second simultaneous provision request for the same slug.
mkdir -p "$LOCK_ROOT/tenant-busy.lock"
if bash "$SCRIPT_DIR/provision-tenant.sh" busy 8082 > "$TMP_DIR/tenant-lock.log" 2>&1; then
  echo "Expected an active tenant lock to block concurrent provisioning." >&2
  exit 1
else
  status=$?
  [[ "$status" == "3" ]]
fi
[[ ! -e "$TENANTS_DIR/busy/.env" ]]
rmdir "$LOCK_ROOT/tenant-busy.lock"

# A port lock prevents two distinct tenants claiming the same localhost port.
mkdir -p "$LOCK_ROOT/port-8083.lock"
if bash "$SCRIPT_DIR/provision-tenant.sh" port-busy 8083 > "$TMP_DIR/port-lock.log" 2>&1; then
  echo "Expected an active port lock to block concurrent provisioning." >&2
  exit 1
else
  status=$?
  [[ "$status" == "3" ]]
fi
[[ ! -e "$TENANTS_DIR/port-busy/.env" ]]
rmdir "$LOCK_ROOT/port-8083.lock"

# Existing tenant secrets must never be overwritten.
if bash "$SCRIPT_DIR/provision-tenant.sh" pilot 8081 > "$TMP_DIR/overwrite.log" 2>&1; then
  echo "Expected existing tenant env to be protected from overwrite." >&2
  exit 1
else
  status=$?
  [[ "$status" == "3" ]]
fi
grep -Fxq 'MAUTIC_HTTP_PORT=8080' "$ENV_FILE"

# A stopped tenant still reserves its configured port through its private env file.
if bash "$SCRIPT_DIR/provision-tenant.sh" another-tenant 8080 > "$TMP_DIR/duplicate-port.log" 2>&1; then
  echo "Expected a stopped tenant's configured port to remain reserved." >&2
  exit 1
else
  status=$?
  [[ "$status" == "3" ]]
fi
[[ ! -e "$TENANTS_DIR/another-tenant/.env" ]]
grep -Fq "already assigned to tenant 'pilot'" "$TMP_DIR/duplicate-port.log"

# An existing env file with an unknown port must fail closed rather than letting
# Compose's default port be accidentally reused.
mkdir -p "$TENANTS_DIR/legacy"
printf 'COMPOSE_PROJECT_NAME=wallza-mautic-legacy\\n' > "$TENANTS_DIR/legacy/.env"
chmod 600 "$TENANTS_DIR/legacy/.env"
if bash "$SCRIPT_DIR/provision-tenant.sh" new-tenant 8088 > "$TMP_DIR/unknown-port.log" 2>&1; then
  echo "Expected an existing tenant with an unknown port to block provisioning." >&2
  exit 1
else
  status=$?
  [[ "$status" == "3" ]]
fi
[[ ! -e "$TENANTS_DIR/new-tenant/.env" ]]
grep -Fq "Cannot safely determine the reserved port for tenant 'legacy'" "$TMP_DIR/unknown-port.log"
rm -rf "$TENANTS_DIR/legacy"

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

# A malformed Wallza organization identifier must be rejected before locks or Docker resources are touched.
if bash "$SCRIPT_DIR/provision-tenant.sh" invalid-tenant 8084 not-a-uuid > "$TMP_DIR/tenant-id.log" 2>&1; then
  echo "Expected malformed Wallza organization UUID to be rejected." >&2
  exit 1
else
  status=$?
  [[ "$status" == "2" ]]
fi

# Non-HTTPS endpoints and query strings in the canonical Mautic base URL must be rejected before tenant resources are created.
if WALLZA_SSO_AUTHORIZE_URL="http://auth.wallza.in/authorize" bash "$SCRIPT_DIR/provision-tenant.sh" invalid-auth 8085 "e48b9f6f-9c4c-4c3d-aeab-c6ed3f0ef999" > "$TMP_DIR/sso-url.log" 2>&1; then
  echo "Expected non-HTTPS Wallza SSO URL to be rejected." >&2
  exit 1
else
  status=$?
  [[ "$status" == "2" ]]
fi
if WALLZA_MAUTIC_BASE_URL="https://mautic.example.test?next=unsafe" bash "$SCRIPT_DIR/provision-tenant.sh" invalid-base 8086 "e48b9f6f-9c4c-4c3d-aeab-c6ed3f0ef999" > "$TMP_DIR/base-url.log" 2>&1; then
  echo "Expected query string in Mautic base URL to be rejected." >&2
  exit 1
else
  status=$?
  [[ "$status" == "2" ]]
fi

# Invalid public ports must be rejected before Docker is invoked.
if bash "$SCRIPT_DIR/provision-tenant.sh" invalid-port 80 > "$TMP_DIR/port.log" 2>&1; then
  echo "Expected invalid port to be rejected." >&2
  exit 1
else
  status=$?
  [[ "$status" == "2" ]]
fi

echo "Tenant provisioner smoke tests passed."
