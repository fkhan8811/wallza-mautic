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
| Baseline and feature audit | Documented; upstream baseline retained | Mautic 7.2.2 remains the base. Earlier CI passed PHP 8.5 MySQL/MariaDB PHPUnit jobs, Mautic E2E, PHPStan, Twig lint, Composer lock validation and API-library compatibility; the overall run was incomplete/failed at workflow level, so the latest full matrix is being repeated. |
| BillionMail SMTP adapter | Implemented in source; corrected focused CI pending | Symfony ESMTP factory, Mautic bundle extension, Composer package registration, lock hash and focused PHPUnit tests are committed. A prior test failure was caused by missing imports in the new Mautic DSN-parser test; imports are now corrected. No live BillionMail send has been performed. |
| Feedback processing | Native IMAP path documented; live routing unverified | Mautic's native `mautic:email:fetch` handles bounce/unsubscribe/FBL mailbox messages. No supported signed BillionMail delivery-webhook contract has been confirmed. |
| Tenant data isolation | Per-tenant deployment template implemented; control-plane mapping pending | Oracle template creates a separate Mautic database, volumes, network and runtime per tenant. It is not deployed or capacity-tested. WallzaOS backend-to-tenant registry, provisioning lifecycle, SSO and API credential management remain unimplemented. |
| Oracle deployment | Template committed; validation pending | Pinned Mautic 7.2.2 image, MariaDB 11.4, web/cron/worker services, local-only web port, resource caps and provisioner are present. Compose validation is included in dedicated push CI. |
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
- The WallzaOS backend still needs a protected tenant registry mapping an authorized organization to its Mautic URL and server-side API credential. Never accept the target URL or token from the browser.
- Implement Supabase SSO/user mapping, subscription/quota checks, automated tenant provisioning/suspension/deletion, and secure API credential rotation.
- Propagate the organization mapping through queued work, retries, webhooks and support operations.
- Add negative tests for tenant-to-stack routing, guessed organization IDs, revoked memberships and token/URL tampering.
- Do not treat Mautic user ownership permissions as tenant isolation.
### W2 — BillionMail delivery integration
- [x] Add dedicated `billionmail+smtp` and `billionmail+smtps` DSN schemes through a plugin.
- [x] Delegate to Symfony's native ESMTP transport to preserve Mautic's rendered message path.
- [x] Add focused tests for scheme support, credentials, TLS defaults and Mautic's own mailer DSN environment processor.
- [x] Add the Mautic bundle DependencyInjection extension that loads `Config/services.php`, plus a container-registration test.
- [x] Register the bundled plugin in root Composer `replace`, refresh the root lock content hash and add a dedicated CI workflow.
- [x] Document Mautic's native IMAP monitored-inbox flow for bounces, unsubscribe requests and feedback-loop messages.
- [ ] Pass the corrected focused plugin PHPUnit run and complete the current full Mautic CI matrix.
- [ ] Compile a real Mautic container and verify plugin discovery in the Oracle image.
- [ ] Send a controlled test message through the real BillionMail mailbox using its actual SMTP hostname, port, credentials and TLS certificate.
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
