# WallzaOS Mautic — BillionMail Integration Contract

**Status:** A native SMTP transport adapter has been committed to the Mautic feature branch. Runtime integration, delivery-event reconciliation, suppression synchronization and tenant isolation are not yet verified or complete.

## 1. Architecture decision

Mautic remains the marketing automation application. BillionMail remains the mail delivery platform. Mautic renders and personalizes campaign content, controls campaign scheduling and queues, and records its normal message activity. BillionMail acts as the SMTP delivery hop.

The inspected BillionMail HTTP Send API is template-bound: an API key is associated with a saved BillionMail template, and the send request supplies a recipient, sender and template attributes. It does not accept Mautic's complete rendered HTML body. It must not be substituted for Mautic's campaign renderer.

BillionMail's SMTP Relay settings configure how BillionMail forwards its own outbound messages through a third-party provider. They are not necessarily the mailbox SMTP credentials that Mautic must use. Use the SMTP hostname, port, TLS mode and credentials issued for a real BillionMail mailbox/submission endpoint in the deployed installation.

Keep the delivery boundary replaceable through configuration. Do not modify Postfix, Dovecot or Roundcube merely to connect Mautic.

## 2. Implemented Mautic SMTP adapter

A plugin has been added at `plugins/MauticBillionMailBundle` on `wallza/marketing-automation-upgrades`.

It registers these Symfony Mailer DSN schemes:

- `billionmail+smtp://` — ESMTP with STARTTLS negotiation.
- `billionmail+smtps://` — ESMTP with implicit TLS.

Configuration patterns (replace all placeholders with the actual mailbox details shown by the deployed BillionMail installation; ports are examples, not assumed defaults):

```text
billionmail+smtp://MAILBOX_USER:URL_ENCODED_PASSWORD@mail.example.com:587?auto_tls=true&require_tls=true
billionmail+smtps://MAILBOX_USER:URL_ENCODED_PASSWORD@mail.example.com:465?require_tls=true
```

Optional controls supported by the adapter:

- `max_per_second` — Symfony SMTP send-rate limit.
- `restart_threshold` and `restart_threshold_sleep` — connection recycle threshold and pause.
- `ping_threshold` — idle connection ping interval.
- `local_domain` — explicit EHLO/HELO name.
- `source_ip` — optional SMTP socket source IP.

TLS is required by default and certificate verification remains enabled. The adapter delegates to Symfony's native ESMTP transport. It does not implement a BillionMail HTTP API client or claim delivery-event synchronization.

**Implementation status:** source files and focused PHPUnit tests have been committed. Tests have not yet been executed in a Mautic runtime or CI environment. Do not mark this feature production-verified until the plugin is discovered by Mautic, the service container compiles, SMTP authentication/TLS succeeds, and a controlled test email is delivered.

## 3. Delivery event reconciliation

No supported, documented provider webhook/signature contract was identified in the inspected BillionMail fork for mapping delivered, deferred, bounced, complained and rejected events back into Mautic. Do not invent an endpoint or accept unsigned public event callbacks.

WallzaOS should normalize delivery provider events into a stable internal event schema when a supported provider contract is available. Before forwarding an event to Mautic or WallzaOS:

1. Verify the original provider signature using the exact raw request body and provider-specific verification rules.
2. Validate timestamp/replay window where supported.
3. Enforce idempotency using provider plus provider event ID.
4. Normalize accepted, delivered, deferred, bounced, complained and rejected outcomes.
5. Minimize personal data in logs and event payloads.
6. Persist before acknowledging the provider where the webhook contract requires durable processing.

Never trust a client-supplied tenant identifier as proof of tenant ownership.

## 4. Suppression policy

- Unsubscribe, hard bounce and complaint events must update a central suppression decision before future marketing sends.
- Suppression is scoped by tenant and channel, with an explicit global block policy only where legally and operationally appropriate.
- A suppression lookup failure must fail closed for marketing sends.
- Suppressed recipients must be excluded both when a campaign is prepared and immediately before each send batch.
- Preserve reason, source, timestamp and audit evidence.
- Define a controlled, audited reinstatement process; do not silently clear complaints.

This policy is a requirement, not a completed feature in the current branch.

## 5. Required tests

- Plugin discovery and service-container compilation.
- SMTP authentication and TLS negotiation against the deployed BillionMail endpoint.
- Single and batch campaign delivery.
- Mautic message/campaign attribution remains intact.
- Delivery, deferred, bounce and rejection reconciliation once a provider event contract is confirmed.
- Duplicate provider event delivery.
- Forged signature and replay rejection.
- Complaint and unsubscribe suppression before campaign creation and at send time.
- Suppression database unavailable: marketing send fails closed.
- Tenant A events cannot mutate Tenant B recipient status.
- Transient SMTP failure retry and permanent SMTP failure handling.

## 6. Deployment dependency

Mautic 7.2.2's database configuration uses MySQL/MariaDB. WallzaOS's Supabase PostgreSQL remains separate for SaaS control-plane data. Do not point Mautic's Doctrine database at Supabase PostgreSQL.

## 7. Remaining inputs and blockers

- Actual deployed BillionMail SMTP mailbox/submission hostname, port, TLS mode and credentials (credentials must never be committed to Git or exposed to browsers).
- A supported BillionMail provider delivery-event/webhook contract, including signature verification rules, if available in the deployed version.
- The populated WallzaOS product-app/backend repository and its trusted auth/tenant claims. The connected Lovable project named `WallzaOS` is the public website/admin CMS (TanStack Start + Supabase) with CMS/RBAC tables, not the product-app tenant model. Its inspected schema contains no tenant/workspace memberships or subscription entitlements; `WallzaOS-Website` and `Wallza-2.0` GitHub repositories are empty, and `wallza-ops-hub` is a separate painting operations CRM.
- Sending-domain and per-tenant credential strategy.

Until tenant identity and enforcement are implemented end-to-end, this Mautic instance must not be exposed as a shared multi-tenant SaaS service.
