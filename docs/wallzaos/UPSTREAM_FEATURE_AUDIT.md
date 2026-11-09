# WallzaOS Mautic — Upstream Feature Audit

**Audited baseline:** Mautic 7.2.2  
**Upstream:** https://github.com/mautic/mautic  
**Baseline commit:** `f28a0120d715a441b7a6e4a688076bd7e725c862`  
**WallzaOS branch:** `wallza/marketing-automation-upgrades`

## Existing capabilities to reuse

| Area | Existing Mautic implementation | WallzaOS decision |
|---|---|---|
| Campaigns | CampaignBundle executioners, event collector, action/condition/decision dispatchers, event scheduler | Extend; do not replace |
| Contacts | LeadBundle contact/company entities, custom fields, ownership and permissions | Add tenant boundary around existing entities |
| Segments | ContactSegmentService and query builders with batching and limiters | Extend incremental refresh and performance monitoring |
| Email | EmailBundle, Symfony Mailer, MauticMessage metadata, email events | Keep Mautic campaign semantics; route delivery through BillionMail SMTP/API contract |
| Queue | Symfony Messenger email and hit transports; Channel message queue | Reuse, measure and configure worker isolation |
| Webhooks | WebhookBundle event definitions, persistent queue entity, queue processor and health checks | Reuse native webhook engine; add WallzaOS event mapping and tenant signing |
| API | API Platform resources, OAuth2, ownership checks and collection extension | Add tenant-aware authorization; ownership is not tenant isolation |
| Email editor | GrapesJS Builder plugin | Retain and extend; avoid replacing editor |
| Reporting | ReportBundle and campaign/email statistics | Add tenant-scoped operational and deliverability metrics |

## Key source locations

- `app/bundles/CampaignBundle/Executioner/`
- `app/bundles/LeadBundle/Segment/`
- `app/bundles/EmailBundle/Mailer/`
- `app/bundles/EmailBundle/EventListener/`
- `app/bundles/WebhookBundle/`
- `app/bundles/ApiBundle/Extension/OwnershipScopedCollectionExtension.php`
- `plugins/GrapesJsBuilderBundle/`

## Gaps requiring implementation or validation

1. Complete tenant isolation across HTTP, API, CLI, workers, scheduled tasks, imports, exports, webhooks, caches, files and logs.
2. Tenant-aware identity, roles, credentials, quotas and lifecycle.
3. BillionMail delivery integration with authenticated event reconciliation, bounce/complaint synchronization and suppression enforcement.
4. Reproducible open/click tracking validation, including privacy proxies and asynchronous hit processing.
5. Journey versioning, dry-run, replay, event-based exit/goals and execution observability.
6. Incremental segment refresh and measurable performance under large contact populations.
7. Tenant-scoped analytics and operational dashboards.

## Non-goals

- Do not deploy a second mail server to replace BillionMail.
- Do not replace Postfix/Dovecot/Roundcube in BillionMail.
- Do not rewrite Mautic's campaign engine or GrapesJS editor.
- Do not copy code from other projects without checking the source licence and dependency notices.
- Do not claim tenant isolation until cross-tenant negative tests pass.
