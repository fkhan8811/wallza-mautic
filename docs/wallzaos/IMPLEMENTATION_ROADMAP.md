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
| Baseline and feature audit | Complete as documentation | Runtime build and CI have not yet been run. |
| BillionMail SMTP adapter | Implemented in source; syntax CI added, runtime unverified | Native Symfony ESMTP transport plugin, Composer package metadata, focused PHPUnit tests and GitHub syntax/metadata workflow are committed. |
| Delivery event reconciliation | Native IMAP path documented; provider webhooks blocked | Mautic already has native IMAP bounce/unsubscribe/FBL processing via `mautic:email:fetch`; BillionMail mailbox routing and live IMAP processing still need validation. No supported provider webhook/signature contract was identified in the inspected BillionMail fork. |
| Tenant isolation | Auth contract identified; Mautic enforcement not implemented | `fkhan8811/Whatsapp` provides Supabase Auth + organizations + organization_members/agents + organization-scoped roles/permissions. Mautic SSO/user mapping/entity scoping remain required. |
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
- Use the inspected `fkhan8811/Whatsapp` product backend contract and the live `Wallza-Connect` Supabase schema: Supabase `getUser(token)`, then organization membership (`organization_members` / `agents`), then `integrations.manage` permission for privileged integration operations. The connected Lovable `WallzaOS` project is the public website/admin CMS.
- Inventory Mautic entities and data ownership.
- Implement trusted tenant context and authorization for one vertical slice.
- Propagate context to API, CLI, Messenger and webhook workers.
- Add cross-tenant negative tests before expanding coverage.
- Do not treat Mautic user ownership permissions as tenant isolation.

### W2 — BillionMail delivery integration
- [x] Add dedicated `billionmail+smtp` and `billionmail+smtps` DSN schemes through a plugin.
- [x] Delegate to Symfony's native ESMTP transport to preserve Mautic's rendered message path.
- [x] Add focused transport-factory PHPUnit test cases.
- [x] Add the Mautic bundle DependencyInjection extension that loads `Config/services.php`, plus a container-registration test.
- [x] Register the bundled plugin in root Composer `replace` and refresh `composer.lock` content hash.
- [x] Dedicated PR plugin CI passed on the latest PR head: root/plugin Composer metadata checks and PHP syntax lint for every plugin PHP file.
- [ ] Full Mautic PHPUnit matrix, root Composer lock validation, Twig lint and static-analysis checks are still pending on GitHub.
- [ ] Complete Mautic PHPUnit matrix, root Composer lock validation and static-analysis checks; upstream jobs were still queued at the last status check.
- [x] Register the bundled plugin in the root Composer `replace` map and add a focused PHP/Composer CI workflow.
- [ ] Confirm the new CI workflow runs successfully; run PHPUnit and Mautic container/plugin discovery in a complete Mautic environment.
- [ ] Execute tests, compile the Mautic container, enable/discover plugin and validate a test send against deployed BillionMail.
- [ ] Add authenticated delivery-event reconciliation if a supported provider webhook contract is confirmed.
- [x] Document Mautic's native IMAP monitored-inbox flow for bounces, unsubscribe requests and feedback-loop messages.
- [ ] Verify BillionMail delivers the relevant feedback messages to the configured IMAP folders and test Mautic DNC/stat updates end-to-end.
- [ ] Add provider webhook reconciliation only if a supported signed webhook contract is confirmed.

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
