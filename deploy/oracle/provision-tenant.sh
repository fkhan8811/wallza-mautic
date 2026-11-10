#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
TENANT_SLUG="${1:-}"
HTTP_PORT="${2:-8080}"
WALLZA_MAUTIC_TENANT_ID="${3:-${WALLZA_MAUTIC_TENANT_ID:-}}"
WALLZA_MAUTIC_BASE_URL="${WALLZA_MAUTIC_BASE_URL:-}"
WALLZA_SSO_AUTHORIZE_URL="${WALLZA_SSO_AUTHORIZE_URL:-}"
WALLZA_SSO_TOKEN_URL="${WALLZA_SSO_TOKEN_URL:-}"
WALLZA_SSO_ISSUER="${WALLZA_SSO_ISSUER:-https://api.wallza.in}"
TENANTS_DIR="${WALLZA_MAUTIC_TENANTS_DIR:-$HOME/.config/wallzaos-mautic/tenants}"

usage() {
  cat <<'USAGE'
Usage:
  ./provision-tenant.sh <tenant-slug> [localhost-port] [wallza-organization-uuid]

Creates one isolated Mautic stack with its own database, configuration,
logs and media volumes. The web port binds to 127.0.0.1 only.

Examples:
  ./provision-tenant.sh pilot 8080
  ./provision-tenant.sh customer-blue 8081 00000000-0000-4000-8000-000000000001

The generated tenant env file is stored outside the Git repository with
permissions restricted to the current operating-system user.
USAGE
}

if [[ -z "$TENANT_SLUG" || "$TENANT_SLUG" == "--help" || "$TENANT_SLUG" == "-h" ]]; then
  usage
  exit 0
fi

if [[ ! "$TENANT_SLUG" =~ ^[a-z0-9][a-z0-9-]{0,38}$ ]]; then
  echo "Invalid tenant slug. Use 1-39 lowercase letters, numbers or hyphens; start with a letter/number." >&2
  exit 2
fi

if [[ -n "$WALLZA_MAUTIC_TENANT_ID" && ! "$WALLZA_MAUTIC_TENANT_ID" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]]; then
  echo "Wallza organization ID must be a UUID when supplied." >&2
  exit 2
fi

validate_https_url() {
  local name="$1"
  local value="$2"
  local allow_query="$3"
  local authority

  [[ -z "$value" ]] && return 0

  if [[ "$value" != https://* || "$value" =~ [[:space:]] || "$value" == *'#'* || "$value" == *'$'* ]]; then
    echo "$name must be an HTTPS URL without whitespace, fragments or Compose interpolation characters." >&2
    exit 2
  fi

  authority="${value#https://}"
  authority="${authority%%/*}"
  authority="${authority%%\?*}"
  if [[ -z "$authority" || "$authority" == *'@'* ]]; then
    echo "$name must contain a host and must not include URL credentials." >&2
    exit 2
  fi

  if [[ "$allow_query" != "true" && "$value" == *'?'* ]]; then
    echo "$name must not contain a query string." >&2
    exit 2
  fi
}

validate_https_url "WALLZA_MAUTIC_BASE_URL" "$WALLZA_MAUTIC_BASE_URL" false
validate_https_url "WALLZA_SSO_AUTHORIZE_URL" "$WALLZA_SSO_AUTHORIZE_URL" true
validate_https_url "WALLZA_SSO_TOKEN_URL" "$WALLZA_SSO_TOKEN_URL" true
validate_https_url "WALLZA_SSO_ISSUER" "$WALLZA_SSO_ISSUER" false

if [[ ! "$HTTP_PORT" =~ ^[0-9]{1,5}$ ]]; then
  echo "Port must contain 1-5 decimal digits." >&2
  exit 2
fi

# Force base-10 interpretation so inputs such as 08080 are not treated as octal.
HTTP_PORT=$((10#$HTTP_PORT))
if (( HTTP_PORT < 1024 || HTTP_PORT > 65535 )); then
  echo "Port must be an integer between 1024 and 65535." >&2
  exit 2
fi

for command in docker openssl ss; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Required command not found: $command" >&2
    exit 2
  fi
done

if ! docker compose version >/dev/null 2>&1; then
  echo "Docker Compose v2 is required." >&2
  exit 2
fi

if ss -H -ltn "sport = :$HTTP_PORT" | grep -q .; then
  echo "Port $HTTP_PORT is already listening on this host." >&2
  exit 2
fi

PROJECT_NAME="wallza-mautic-$TENANT_SLUG"
TENANT_ENV_DIR="$TENANTS_DIR/$TENANT_SLUG"
TENANT_ENV_FILE="$TENANT_ENV_DIR/.env"

# Serialize tenant and host-port provisioning. mkdir is atomic on the local
# filesystem, so two simultaneous requests cannot race while generating DB
# credentials or creating Compose resources for the same tenant/port.
umask 077
LOCK_ROOT="$TENANTS_DIR/.provisioning-locks"
TENANT_LOCK="$LOCK_ROOT/tenant-$TENANT_SLUG.lock"
PORT_LOCK="$LOCK_ROOT/port-$HTTP_PORT.lock"
mkdir -p "$LOCK_ROOT"
chmod 700 "$TENANTS_DIR" "$LOCK_ROOT"

if ! mkdir "$TENANT_LOCK" 2>/dev/null; then
  echo "Tenant '$TENANT_SLUG' is already being provisioned by another process." >&2
  echo "Check for an active provisioner or a stale lock at '$TENANT_LOCK'." >&2
  exit 3
fi

if ! mkdir "$PORT_LOCK" 2>/dev/null; then
  rmdir "$TENANT_LOCK" 2>/dev/null || true
  echo "Host port $HTTP_PORT is already reserved by another tenant provisioner." >&2
  echo "Check for an active provisioner or a stale lock at '$PORT_LOCK'." >&2
  exit 3
fi

cleanup_locks() {
  rmdir "$PORT_LOCK" "$TENANT_LOCK" 2>/dev/null || true
}
trap cleanup_locks EXIT

if [[ -e "$TENANT_ENV_FILE" ]]; then
  echo "Tenant env already exists: $TENANT_ENV_FILE" >&2
  echo "Refusing to overwrite secrets or replace an existing tenant stack." >&2
  exit 3
fi

# A port belongs to a tenant for as long as its private env file exists, even
# when that tenant's containers are stopped. Checking only listening sockets
# would allow two stopped/running stacks to be assigned the same localhost port.
while IFS= read -r -d '' existing_env; do
  [[ "$existing_env" == "$TENANT_ENV_FILE" ]] && continue

  existing_port="$(sed -n 's/^MAUTIC_HTTP_PORT=//p' "$existing_env" | head -n 1)"
  [[ "$existing_port" =~ ^[0-9]{1,5}$ ]] || continue
  existing_port=$((10#$existing_port))

  if (( existing_port == HTTP_PORT )); then
    existing_slug="$(basename "$(dirname "$existing_env")")"
    echo "Host port $HTTP_PORT is already assigned to tenant '$existing_slug'." >&2
    echo "Stop and explicitly reassign that tenant before reusing its port." >&2
    exit 3
  fi
done < <(find "$TENANTS_DIR" -mindepth 2 -maxdepth 2 -type f -name .env -print0 2>/dev/null)

# A deleted env file must not allow a new set of passwords to be generated
# for an old Compose project that still contains customer data/volumes.
existing_resources="$(
  {
    docker ps -aq --filter "label=com.docker.compose.project=$PROJECT_NAME"
    docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT_NAME"
    docker network ls -q --filter "label=com.docker.compose.project=$PROJECT_NAME"
  } | sed '/^$/d' | sort -u
)"
if [[ -n "$existing_resources" ]]; then
  echo "Docker resources already exist for Compose project '$PROJECT_NAME'." >&2
  echo "Refusing to generate new database passwords for an existing tenant stack." >&2
  echo "Restore the tenant env file or perform an explicit, reviewed recovery." >&2
  exit 3
fi

umask 077
mkdir -p "$TENANT_ENV_DIR"
chmod 700 "$TENANT_ENV_DIR"

DB_PASSWORD="$(openssl rand -hex 24)"
DB_ROOT_PASSWORD="$(openssl rand -hex 32)"
WALLZA_SSO_HMAC_SECRET="$(openssl rand -hex 32)"

cat > "$TENANT_ENV_FILE" <<ENV
COMPOSE_PROJECT_NAME=$PROJECT_NAME
MAUTIC_IMAGE=wallzaos/mautic:7.2.2-wallza1
MAUTIC_HTTP_BIND=127.0.0.1
MAUTIC_HTTP_PORT=$HTTP_PORT
MARIADB_DATABASE=mautic
MARIADB_USER=mautic
MARIADB_PASSWORD=$DB_PASSWORD
MARIADB_ROOT_PASSWORD=$DB_ROOT_PASSWORD
MAUTIC_DB_MEMORY_LIMIT=2g
MAUTIC_WEB_MEMORY_LIMIT=2g
MAUTIC_CRON_MEMORY_LIMIT=512m
MAUTIC_WORKER_MEMORY_LIMIT=768m
MAUTIC_DB_CPUS=0.45
MAUTIC_WEB_CPUS=0.65
MAUTIC_CRON_CPUS=0.15
MAUTIC_WORKER_CPUS=0.25
MAUTIC_WORKERS_CONSUME_EMAIL=1
MAUTIC_WORKERS_CONSUME_HIT=1
MAUTIC_WORKERS_CONSUME_FAILED=1
WALLZA_MAUTIC_TENANT_ID=$WALLZA_MAUTIC_TENANT_ID
WALLZA_MAUTIC_BASE_URL=$WALLZA_MAUTIC_BASE_URL
WALLZA_SSO_AUTHORIZE_URL=$WALLZA_SSO_AUTHORIZE_URL
WALLZA_SSO_TOKEN_URL=$WALLZA_SSO_TOKEN_URL
WALLZA_SSO_ISSUER=$WALLZA_SSO_ISSUER
WALLZA_SSO_HMAC_SECRET=$WALLZA_SSO_HMAC_SECRET
ENV
chmod 600 "$TENANT_ENV_FILE"

echo "Provisioning isolated Mautic tenant stack: $TENANT_SLUG"
echo "Private env file: $TENANT_ENV_FILE"
echo "Local-only web endpoint: http://127.0.0.1:$HTTP_PORT"
echo "Compose project: $PROJECT_NAME"

docker compose \
  --project-directory "$SCRIPT_DIR" \
  --project-name "$PROJECT_NAME" \
  --env-file "$TENANT_ENV_FILE" \
  -f "$SCRIPT_DIR/compose.yaml" \
  up -d --build

cat <<'NEXT'

Next:
  1. Put this stack behind your HTTPS reverse proxy; the Mautic port is intentionally bound to loopback.
  2. Complete Mautic's first-run installer for this tenant.
  3. Configure this tenant's own BillionMail SMTP mailbox and IMAP feedback mailbox in Mautic.
  4. Configure the Wallza organization UUID, trusted HTTPS SSO endpoints, tenant URL and generated HMAC secret in the private env file.
  5. Provision existing Mautic users through the tenant API; SSO never creates users or changes Mautic roles.
  6. Keep the tenant's Mautic API credentials server-side in WallzaOS.
  7. Back up this project's database and Mautic volumes before upgrades.

Do not use "docker compose down -v" unless you intend to erase this tenant's database and uploaded files.
NEXT
