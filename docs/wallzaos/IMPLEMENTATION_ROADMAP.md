# WallzaOS Mautic — Implementation Roadmap

## Current baseline

- Upstream project: Mautic 7.2.2.
- Source tag commit: `f28a0120d715a441b7a6e4a688076bd7e725c862`.
- Working branch: `wallza/marketing-automation-upgrades`.
- BillionMail remains the delivery platform.
- Mautic's native campaigns, contacts, segments, queues, webhooks and GrapesJS editor are retained.

## Workstreams

### W0 — Baseline and reproducible development
- Record source tag and dependency requirements.
- Run Composer validation, PHP syntax checks, static analysis and focused PHPUnit suites.
- Establish a repeatable local/staging test environment.
- Preserve upstream test and build workflows.

### W1 — WallzaOS identity and tenant boundary
- Inspect WallzaOS auth/tenant contract.
- Inventory Mautic entities and data ownership.
- Implement trusted tenant context and authorization for one vertical slice.
- Propagate context to API, CLI, Messenger and webhook workers.
- Add cross-tenant negative tests before expanding coverage.

### W2 — BillionMail delivery integration
- Verify deployed BillionMail capabilities and SMTP configuration.
- Configure SMTP transport without replacing Mautic's mailer.
- Add event reconciliation and suppression contract.
- Validate deliverability and event attribution end-to-end.

### W3 — Campaign/journey improvements
- Reuse native campaign execution and scheduling.
- Add journey versioning, dry-run, event goals, exits and replay where gaps are proven.
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
4. BillionMail events are authenticated, idempotent and tenant-safe.
5. No feature is marked complete based solely on compile success.
6. No production deployment is performed without explicit approval and a rollback plan.
