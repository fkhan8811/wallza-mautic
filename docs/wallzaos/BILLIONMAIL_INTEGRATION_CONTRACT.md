# WallzaOS Mautic — BillionMail Integration Contract

**Status:** Contract and validation plan; provider-specific API details must be verified against the deployed BillionMail version.

## 1. Architecture decision

Mautic remains the marketing automation application. BillionMail remains the mail delivery platform. Use BillionMail's supported SMTP service for outbound delivery unless a documented, versioned HTTP send API is confirmed. Do not assume an undocumented endpoint exists.

Keep the delivery boundary replaceable through configuration. Do not modify Postfix, Dovecot or Roundcube merely to connect Mautic.

## 2. Outbound delivery

- Configure Mautic's Symfony Mailer DSN to use a dedicated BillionMail SMTP credential.
- Use a tenant-appropriate sending identity/domain policy. Do not expose shared SMTP credentials to browsers.
- Keep transactional and marketing traffic policy explicit.
- Apply suppression before a message is handed to the SMTP transport; SMTP rejection alone is not a sufficient suppression control.
- Preserve Mautic's message metadata and campaign attribution.
- Define rate limits, connection limits, timeouts, retry policy and maximum batch size based on measured BillionMail capacity.
- Do not retry permanent recipient or policy failures as if they were transient network failures.

## 3. Delivery event reconciliation

WallzaOS should normalize delivery provider events into a stable internal event schema. Before forwarding an event to Mautic or WallzaOS:

1. Verify the original provider signature using the exact raw request body and provider-specific verification rules.
2. Validate timestamp/replay window where supported.
3. Enforce idempotency using provider plus provider event ID.
4. Normalize accepted, delivered, deferred, bounced, complained and rejected outcomes.
5. Minimize personal data in logs and event payloads.
6. Persist before acknowledging the provider where the webhook contract requires durable processing.

Never accept an unsigned public complaint callback. Never trust a client-supplied tenant identifier as proof of tenant ownership.

## 4. Suppression policy

- Unsubscribe, hard bounce and complaint events must update a central suppression decision before future marketing sends.
- Suppression is scoped by tenant and channel, with an explicit global block policy only where legally and operationally appropriate.
- A suppression lookup failure must fail closed for marketing sends.
- Suppressed recipients must be excluded both when a campaign is prepared and immediately before each send batch.
- Preserve reason, source, timestamp and audit evidence.
- Define a controlled, audited reinstatement process; do not silently clear complaints.

## 5. Required tests

- SMTP authentication and TLS negotiation.
- Single and batch campaign delivery.
- Delivery, deferred, bounce and rejection reconciliation.
- Duplicate provider event delivery.
- Forged signature and replay rejection.
- Complaint and unsubscribe suppression before campaign creation and at send time.
- Suppression database unavailable: marketing send fails closed.
- Tenant A events cannot mutate Tenant B recipient status.
- Transient SMTP failure retry and permanent SMTP failure handling.
- Mautic message/campaign attribution remains intact.

## 6. Deployment dependency

Mautic 7.2.2's database configuration uses MySQL/MariaDB. WallzaOS's Supabase PostgreSQL remains separate for SaaS control-plane data. Do not point Mautic's Doctrine database at Supabase PostgreSQL.

## 7. Information still required for implementation

- Exact BillionMail version and deployed SMTP hostname/port/TLS mode.
- Whether BillionMail exposes a documented HTTP send API in the deployed version.
- Provider event/webhook configuration and signature format.
- Existing WallzaOS tenant identity and authentication claims.
- Sending domains and per-tenant credential strategy.
