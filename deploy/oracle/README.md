# WallzaOS Mautic — isolated Oracle deployment

This folder builds the bundled BillionMail plugin into the official Mautic 7.2.2 Apache image and provides a repeatable **one-tenant-per-stack** deployment.

## Isolation model

Do not run multiple WallzaOS customer organizations in one shared Mautic database. Mautic's native user ownership and permissions are not a complete SaaS tenant boundary.

Each tenant provisioned by this template gets a separate Docker Compose project with its own:

- Mautic web, cron and Messenger worker containers
- MariaDB database container and persistent database volume
- Mautic configuration and secret volume
- logs, uploaded files and images volumes
- private Docker network and loopback-only web port

The code/image is shared; the database and runtime state are not. Docker Compose prefixes named volumes and networks with the project name, so different tenant slugs use different resources. Never reuse a project name for a different customer.

This is the isolation boundary for the initial SaaS rollout. A shared multi-tenant Mautic database with tenant columns/Doctrine filters is deliberately not attempted.

## Requirements

- Docker Engine and Docker Compose v2.
- A host with enough CPU/RAM/disk for the current Mautic workload.
- An HTTPS reverse proxy (Caddy or Nginx) to route each tenant hostname to its loopback port.
- A separate BillionMail mailbox/submission credential set for each tenant.
- The PHP IMAP extension is included in the official Mautic image; verify it is enabled before relying on monitored-email bounce processing.

Mautic 7.2.2 supports PHP 8.2–8.5 and requires at least MySQL 8.4 or MariaDB 10.11. This stack uses the official dated Mautic 7.2.2 Apache image and MariaDB 11.4. See the Mautic release requirements before changing either pinned version. The Dockerfile pins the official multi-platform image manifest by digest (linux/amd64 and linux/arm64) so the same reviewed base image is used across supported Oracle architectures.

## Provision one tenant

From the repository root:

```bash
bash deploy/oracle/provision-tenant.sh pilot 8080
```

Use a unique lowercase slug and unique host port for every customer. The provisioner generates separate random database and root passwords, stores them outside the Git checkout under:

```text
~/.config/wallzaos-mautic/tenants/<tenant-slug>/.env
```

The file and directory are created with restrictive permissions. The script refuses to overwrite an existing tenant env file. Do not copy these secrets into Git, Lovable, browser code or logs.

The web container binds to `127.0.0.1` only. It is not publicly reachable by default. Configure your host reverse proxy to route the tenant's HTTPS hostname to its assigned local port. Do not expose MariaDB or the web port directly to the public internet.

A sample Caddy site is provided at `deploy/oracle/Caddyfile.example`. For the `pilot` example, replace `mautic.example.com` with the tenant hostname and keep `127.0.0.1:8080` as the upstream. Caddy can then terminate HTTPS and proxy to the loopback-only Mautic port. For a proxy running in a container, do not assume it can reach the host's loopback address; configure an explicit private network/host-gateway route and keep the Mautic port firewalled from public ingress.

After the first boot, open the tenant through the reverse proxy and complete Mautic's installer. The tenant has its own admin account and own Mautic database. Create a server-side API credential for WallzaOS and keep it in the WallzaOS backend secret store.

After the installer has created the Mautic database, reload plugins and clear the application cache:

```bash
docker compose \
  --project-directory deploy/oracle \
  --project-name wallza-mautic-pilot \
  --env-file ~/.config/wallzaos-mautic/tenants/pilot/.env \
  -f deploy/oracle/compose.yaml \
  exec --user www-data --workdir /var/www/html mautic_web php bin/console mautic:plugins:reload

docker compose \
  --project-directory deploy/oracle \
  --project-name wallza-mautic-pilot \
  --env-file ~/.config/wallzaos-mautic/tenants/pilot/.env \
  -f deploy/oracle/compose.yaml \
  exec --user www-data --workdir /var/www/html mautic_web php bin/console cache:clear
```

Replace `pilot` with the tenant's actual slug. These commands are tenant-local; run them only after Mautic's installer completes and before configuring the custom transport.

## BillionMail SMTP verification

In each tenant's Mautic **Settings → Configuration → Email Settings**, set the DSN to the actual submission host and mailbox credentials issued by that tenant's BillionMail deployment.

STARTTLS pattern:

```text
billionmail+smtp://URL_ENCODED_MAILBOX_USER:URL_ENCODED_PASSWORD@mail.example.com:587?auto_tls=true&require_tls=true
```

Implicit TLS pattern:

```text
billionmail+smtps://URL_ENCODED_MAILBOX_USER:URL_ENCODED_PASSWORD@mail.example.com:465?require_tls=true
```

BillionMail's inspected fork uses the following default ports (the Compose environment can override the host-side ports):

| Service | Default port | Mautic usage |
|---|---:|---|
| SMTP submission | 587 | Recommended; STARTTLS is enforced |
| SMTPS | 465 | Alternative; implicit TLS |
| SMTP | 25 | Server-to-server; not the mailbox submission port |
| IMAPS | 993 | Recommended for monitored-email folders |

The mailbox username is its full address, such as `marketing@example.com`. URL-encode `@` as `%40` in the DSN username and URL-encode reserved password characters. Use your deployed `BILLIONMAIL_HOSTNAME`, the actual mailbox password and the valid TLS certificate for that hostname. These are source-verified defaults, not a guarantee that your deployment has not overridden them.

TLS is required by default and certificate verification remains enabled.

### Optional SMTP sink test

To test the plugin before entering live BillionMail credentials, start Mailpit:

```bash
docker compose \
  --project-directory deploy/oracle \
  --project-name wallza-mautic-pilot \
  --env-file ~/.config/wallzaos-mautic/tenants/pilot/.env \
  -f deploy/oracle/compose.yaml \
  --profile test-smtp up -d mailpit
```

Configure the Mautic DSN temporarily as:

```text
billionmail+smtp://mailpit:1025?auto_tls=false&require_tls=false
```

Open the Mailpit inbox at `http://127.0.0.1:8025` on the server or through a trusted SSH tunnel. This verifies the Mautic-to-plugin-to-SMTP send path; it does **not** prove authentication, TLS, delivery or reputation with a real BillionMail server. Restore the live BillionMail DSN after this test.

## Bounce and unsubscribe ingestion

Configure each tenant's BillionMail IMAP mailbox in **Settings → Configuration → Email Settings → Monitored Inbox Settings** using the deployed host, port `993`, SSL/TLS (`/ssl`), full mailbox address and mailbox password. Point the Bounces and optional Unsubscribe Requests folders at folders that actually receive those messages.

The supplied `mautic_cron` service mounts `deploy/oracle/mautic-cron`, which preserves Mautic's segment/campaign schedules and enables `mautic:email:fetch --message-limit=100` every minute. The command skips unconfigured folders, so the schedule is safe before IMAP credentials are entered. Check execution with:

```bash
docker compose --project-directory deploy/oracle --project-name wallza-mautic-pilot --env-file ~/.config/wallzaos-mautic/tenants/pilot/.env -f deploy/oracle/compose.yaml logs --tail=100 mautic_cron
```

To run one bounded fetch manually after IMAP is configured:

```bash
docker compose --project-directory deploy/oracle --project-name wallza-mautic-pilot --env-file ~/.config/wallzaos-mautic/tenants/pilot/.env -f deploy/oracle/compose.yaml exec --user www-data --workdir /var/www/html mautic_cron php bin/console mautic:email:fetch --message-limit=100
```

This uses Mautic's native IMAP feedback handling. It is not a BillionMail webhook and depends on BillionMail routing feedback messages into the configured folders.

## WallzaOS routing contract

The WallzaOS backend—not the browser—must map an authenticated Supabase user and authorized organization to exactly one tenant stack:

1. Validate the Supabase bearer token server-side with Supabase Auth `getUser(token)`.
2. Check active membership in `organization_members` or the authorized `agents` row for the requested organization.
3. Resolve the organization's Mautic base URL and API credential from a protected server-side tenant registry.
4. Call only that tenant's Mautic API. Never accept a client-provided Mautic URL or API credential.
5. Keep API tokens and mailbox passwords out of frontend responses and browser storage.
6. Carry the organization/tenant mapping through queued work and retries.

The provisioning template enforces **data-plane isolation** by deploying a separate Mautic database and runtime. The WallzaOS control-plane mapping, SSO/user provisioning, subscription quotas and automated tenant lifecycle still need to be implemented in the WallzaOS backend before public self-service onboarding.

## Resource limits and capacity

The Compose file starts with conservative per-tenant caps (web 2 GB, database 2 GB, worker 768 MB, cron 512 MB; CPU caps total 1.5 vCPU). The initial worker pool is one consumer each for email, hit and failed queues; the official image otherwise defaults to two per queue (six total). Counts are configurable with `MAUTIC_WORKERS_CONSUME_EMAIL`, `MAUTIC_WORKERS_CONSUME_HIT` and `MAUTIC_WORKERS_CONSUME_FAILED`. These are initial limits, not measured capacity or a promise that the Oracle host can run multiple tenants alongside Supabase, BillionMail, Postiz and the other WallzaOS services.

Start with one pilot tenant. Measure host memory, CPU, database I/O, queue depth and delivery rate under realistic load before provisioning another tenant. Do not remove limits or increase the tenant count without capacity testing.

## Backups and lifecycle

- Back up the tenant's MariaDB volume and Mautic config/media volumes before upgrades or image changes.
- Keep the tenant env file backed up separately with encrypted access; it contains database secrets.
- Rebuild the image from a reviewed Mautic release and plugin commit; do not use the floating `latest` tag.
- `docker compose down` stops containers but retains named volumes.
- `docker compose down -v` deletes the tenant database and volumes. Treat it as destructive and do not run it as routine maintenance.

This template is deployable infrastructure, not evidence that the tenant's live SMTP/IMAP credentials or WallzaOS SSO have been tested.
