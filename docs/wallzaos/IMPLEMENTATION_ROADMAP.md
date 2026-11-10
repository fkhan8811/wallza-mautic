# WallzaOS Mautic — Implementation Roadmap

## Current baseline

- Upstream project: Mautic 7.2.2.
- Source tag commit: `f28a0120d715a441b7a6e4a688076bd7e725c862`.
- Working branch: `wallza/marketing-automation-upgrades`.
- BillionMail fork: `fkhan8811/wallza-email-service-feature-upgrades`, default branch `dev`.
- Mautic's native campaigns, contacts, segments, queues, webhooks and GrapesJS editor are retained.
- Custom code remains on the WallzaOS feature branch; no upstream default branch has been changed.

## Status summary

| Workstream | Current status | Notes |
|---|---|---|
| Baseline and feature audit | Implemented; current CI passed | Mautic 7.2.2 remains the base. On PR head `71ca24a8fb46f1e03ae7fcdc9f74da8e01cc45d8` (November 10, 2026), all four PR workflows completed successfully: PHPUnit database jobs on PHP 8.2 against MySQL 8.4/9.2 and MariaDB 10.11/11.4, E2E, PHPStan, Rector, Twig lint, Composer lock/install and scaffold checks. Dedicated plugin, API-library compatibility and JSON workflows also passed. |
| BillionMail SMTP adapter | Implemented; automated validation passed | Symfony ESMTP factory, Mautic bundle extension, Composer package registration and lock hash are committed. Dedicated plugin CI passed DSN/unit tests, Mailpit SMTP delivery, PHP lint, Composer metadata, pinned Oracle image builds for AMD64/ARM64, plugin autoload/IMAP checks, tenant-provisioner smoke tests and Compose/cron validation. API-library plugin reload/container compatibility also passed. No live BillionMail credentials or production send have been tested. |
| Feedback processing | Native IMAP path documented; live routing unverified | Mautic's native `mautic:email:fetch` handles bounce/unsubscribe/FBL mailbox messages. No supported signed BillionMail delivery-webhook contract has been confirmed. |
| Tenant data isolation | Data-plane and Mautic SSO client implemented; WallzaOS control plane pending | Oracle template creates a separate Mautic DB, volumes, network and runtime per tenant. Mautic-side SSO verifies tenant-bound assertions and only logs in existing enabled users. Matching WallzaOS authorization/token endpoints and a protected tenant registry have now been implemented on `fkhan8811/Whatsapp` branch `wallza/mautic-sso-issuer` in draft PR #6 (not merged; CI pending at the time of this update). The integration remains a release gate until that PR's CI passes, the migration is reviewed/applied, and a browser-to-Mautic flow is validated. User provisioning lifecycle, subscription/quota, automated suspension/deletion and tenant API credential management remain outstanding. Oracle deployment/capacity tests remain pending. |
| Oracle deployment | Template implemented; CI validation passed; host deployment pending | Pinned Mautic 7.2.2 image contains both custom plugins; MariaDB 11.4, web/cron/worker services, local-only web port, resource caps, three-consumer pilot pool and guarded provisioner are present. CI built AMD64/ARM64 images, validated Compose, ran provisioner smoke tests and checked both plugins' autoload/IMAP. No Oracle deployment or capacity test has been performed. |
| Journey and campaign enhancements | Not started | Audit native capabilities first; avoid duplicating Mautic's existing engine. |
| Segmentation and analytics enhancements | Not started | Need realistic data profiling before optimization. |
| AI authoring and extra channels | Not started | Defer until identity, quotas, delivery and audit boundaries exist. |
## Workstreams

### W0 — Baseline and reproducible development
- Record source tag and dependency requirements.
- Run Composer validation, PHP syntax checks, static analysis and focused PHPUnit suites in a compatible environment.
- Establish a repeatable local/staging test environment.
- Preserve upstream test and build workflows.

### W1 — WallzaOS identity and tenant boundary
- Use the inspected `fkhan8811/Whatsapp` product backend contract and live `Wallza-Connect` Supabase schema: Supabase `getUser(token)`, then organization membership (`organization_members` / `agents`), then `integrations.manage` for privileged integration operations. The connected Lovable `WallzaOS` project is the public website/admin CMS.
- **Initial data-plane isolation choice:** one Mautic stack and one MariaDB database per WallzaOS organization. The Oracle deployment template and manual tenant provisioner are committed. Do not put multiple organizations in one Mautic database.
- The matching WallzaOS backend is now in draft PR [#6](https://github.com/fkhan8811/Whatsapp/pull/6), branch `wallza/mautic-sso-issuer`. It adds a protected tenant registry, callback allowlisting, per-tenant secret rotation, a browser Supabase-session handoff, single-use auth codes and the server-side HS256 token issuer. It is not merged and its CI is still running; apply no live migration and enable no tenant until review, CI and isolated end-to-end testing pass. Never accept a target URL or token from the browser.
- [x] Implement the Mautic-side Wallza SSO client with session-bound state, server-to-server code exchange, signed tenant assertion verification, and existing-user-only login.
- [x] Bind the existing tenant-local Mautic user by immutable Supabase UUID (`sub`) in `username` and separately require a matching signed verified email; preserve the locally assigned Mautic role.
- [ ] Merge/release the matching WallzaOS issuer only after review and CI; its draft implementation is in [WhatsApp PR #6](https://github.com/fkhan8811/Whatsapp/pull/6). The code includes active membership checks at issuance/exchange, atomic 60-second one-time codes, tenant-bound callbacks and per-tenant HS256 assertions.
- [ ] Provision Mautic users through tenant-scoped API credentials using Supabase UUID as Mautic `username` and matching verified email; preserve tenant-local Mautic roles.
- [ ] Implement subscription/quota checks, automated tenant provisioning/suspension/deletion, and secure API credential rotation.
- Propagate the organization mapping through queued work, retries, webhooks and support operations.
- Add negative tests for tenant-to-stack routing, guessed organization IDs, revoked memberships and token/URL tampering.
- Do not treat Mautic user ownership permissions as tenant isolation.
### W2 — BillionMail delivery integration
- [x] Add dedicated `billionmail+smtp` and `billionmail+smtps` DSN schemes through a plugin.
- [x] Delegate to Symfony's native ESMTP transport to preserve Mautic's rendered message path.
- [x] Add focused tests for scheme support, credentials, TLS defaults and Mautic's own mailer DSN environment processor.
- [x] Add the Mautic bundle DependencyInjection extension that loads `Config/services.php`, plus a container-registration test.
- [x] Register the bundled plugin in root Composer `replace`, refresh the root lock content hash and add a dedicated CI workflow.
- [x] Document Mautic's native IMAP monitored-inbox flow for bounces, unsubscribe requests and feedback-loop messages, including the correct Unsubscribe Requests folder for abuse FBL reports.
- [x] Add a Mailpit-backed SMTP integration test that sends a real Symfony message through the BillionMail transport and verifies it appears in the SMTP sink.
- [x] Extend dedicated CI to build the pinned Oracle image, verify plugin autoload and PHP IMAP, exercise provisioner safety, and validate Compose/cron configuration.
- [x] Extend API-library CI to reload the local Mautic plugins, rebuild the cache/container and verify the BillionMail transport factory and Wallza SSO integration/subscriber registration.
- [x] Serialize tenant provisioning by slug and host port with atomic locks; smoke tests cover busy tenant/port locks, secret overwrite, orphan Compose resources and invalid ports.
- [x] Obtain successful CI results for the SMTP sink, Wallza Identity assertion/callback tests, plugin/container discovery and Oracle provisioner smoke checks (latest dedicated plugin workflow passed).
- [x] Pass the Mailpit-backed plugin PHPUnit/integration run and current full Mautic database/E2E validation (latest Mautic validation workflow passed; DB matrix: MySQL 8.4/9.2 and MariaDB 10.11/11.4 on PHP 8.2).
- [x] Pass the automated real-Mautic plugin reload/container compile check for both plugins and the pinned Oracle image autoload check (latest API compatibility and plugin workflows passed).
- [ ] Send a controlled test message through the real BillionMail mailbox using its actual SMTP hostname, port, credentials and TLS certificate.
- [ ] Complete isolated end-to-end SSO validation after WhatsApp PR #6 is reviewed and released: actual Supabase session, provisioned tenant-local Mautic user, exact registered callback, replay rejection and role preservation.
- [ ] Verify BillionMail routes hard/soft bounce, unsubscribe and complaint/feedback messages into the configured IMAP folders; assert Mautic updates stats and Do-Not-Contact state.
- [ ] Add provider webhook reconciliation only if a documented signed event contract is confirmed; never invent endpoints or accept unsigned callbacks.
### W3 — Campaign/journey improvements
- Reuse native campaign execution and scheduling.
- Add journey versioning, dry-run, event goals, exits and replay only where gaps are proven.
- Add operational visibility for stuck/retried campaign events.

### W4 — Segmentation and analytics
- Profile segment query execution with realistic data.
- Add incremental refresh only where it improves measured workloads.
- Add tenant-scoped deliverability and campaign analytics.

### W5 — UX and extension integrations
- Extend GrapesJS Builder with reusable components/templates.
- Add AI-assisted campaign authoring behind explicit tenant permissions and quotas.
- Add other channels only after delivery and tenant boundaries are stable.

## Acceptance gates

1. No upstream branch is modified.
2. All custom code lives on WallzaOS branches and is reviewed in pull requests.
3. Tenant isolation has negative tests across API and asynchronous workers.
4. BillionMail events are authenticated, idempotent and tenant-safe before they are enabled.
5. No feature is marked complete based solely on compile success.
6. No production deployment is performed without explicit approval and a rollback plan.
