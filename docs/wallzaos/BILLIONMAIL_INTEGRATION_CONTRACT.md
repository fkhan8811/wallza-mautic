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

TLS is required by default and certificate verification remains enabled. The adapter delegates to Symfony's native ESMTP transport. It uses Mautic's regular per-contact SMTP send path; it does not implement Mautic's optional `TokenTransportInterface` provider-side recipient batching or asynchronous provider callbacks. It does not implement a BillionMail HTTP API client or claim delivery-event synchronization.

**Implementation status:** source files, Composer package metadata, the bundle DependencyInjection extension and focused PHPUnit tests are committed. The dedicated push CI completed successfully for root/plugin Composer metadata and PHP syntax checks. The full Mautic PHPUnit matrix, the actual installed Mautic container/plugin discovery check, and SMTP delivery against the deployed BillionMail mailbox have not yet passed. Do not mark this feature production-verified until all three are completed.

## 3. WallzaOS backend identity contract discovered

The connected `fkhan8811/Whatsapp` backend uses the following tenant/auth model as the current reference contract:

- The authenticated identity is a Supabase Auth user. Server routes validate the bearer token with `supabase.auth.getUser(token)`; they do not accept a decoded client claim as proof of identity.
- The organization is selected by an organization ID, but that ID is only a requested resource scope. Authorization is checked against `organization_members` (active membership where applicable) and/or `agents` rows matching both organization and authenticated user.
- Privileged integration access is stricter: `authorizeMetaOrganization` resolves organization owner, `user_roles`, `organization_members`, and `agents`, then checks the `integrations.manage` permission through `permissions`, `role_permissions`, and `user_permissions_override`.
- OAuth state in the backend uses an HMAC-signed payload plus a persisted one-time nonce and a short expiry. Meta webhooks are verified against the exact raw body using `X-Hub-Signature-256`.
- The live `Wallza-Connect` Supabase schema contains organization-scoped `email_mailboxes` and `email_credentials`; the SMTP/IMAP credential columns are encrypted, and the credential table is RLS-scoped through its parent mailbox's organization. Decryption and SMTP use must stay on a trusted server.

This is a reference contract for the WallzaOS integration layer, not proof that Mautic already enforces tenant boundaries. The Mautic plugin currently does not establish Supabase SSO, map Mautic users to organizations, or filter Mautic entities by organization. Do not expose a shared Mautic instance to multiple customer workspaces until that enforcement is implemented and tested across UI, API, CLI, Messenger workers, imports/exports and webhook delivery. In particular, do not pass a browser-supplied `organization_id` into a Mautic request and treat it as authorization.

## 4. Delivery event reconciliation

No supported, documented provider webhook/signature contract was identified in the inspected BillionMail fork for mapping delivered, deferred, bounced, complained and rejected events back into Mautic. Do not invent an endpoint or accept unsigned public event callbacks.

WallzaOS should normalize delivery provider events into a stable internal event schema when a supported provider contract is available. Before forwarding an event to Mautic or WallzaOS:

1. Verify the original provider signature using the exact raw request body and provider-specific verification rules.
2. Validate timestamp/replay window where supported.
3. Enforce idempotency using provider plus provider event ID.
4. Normalize accepted, delivered, deferred, bounced, complained and rejected outcomes.
5. Minimize personal data in logs and event payloads.
6. Persist before acknowledging the provider where the webhook contract requires durable processing.

Never trust a client-supplied tenant identifier as proof of tenant ownership.

## 5. Suppression policy

- Unsubscribe, hard bounce and complaint events must update a central suppression decision before future marketing sends.
- Suppression is scoped by tenant and channel, with an explicit global block policy only where legally and operationally appropriate.
- A suppression lookup failure must fail closed for marketing sends.
- Suppressed recipients must be excluded both when a campaign is prepared and immediately before each send batch.
- Preserve reason, source, timestamp and audit evidence.
- Define a controlled, audited reinstatement process; do not silently clear complaints.

This policy is a requirement, not a completed feature in the current branch.

## 6. Required tests

- Plugin discovery and service-container compilation.
- SMTP authentication and TLS negotiation against the deployed BillionMail endpoint.
- Single sends and campaign sends through Mautic's per-contact SMTP path (this adapter does not implement provider-side tokenized batch sends).
- Mautic message/campaign attribution remains intact.
- Delivery, deferred, bounce and rejection reconciliation once a provider event contract is confirmed.
- Duplicate provider event delivery.
- Forged signature and replay rejection.
- Complaint and unsubscribe suppression before campaign creation and at send time.
- Suppression database unavailable: marketing send fails closed.
- Tenant A events cannot mutate Tenant B recipient status.
- Transient SMTP failure retry and permanent SMTP failure handling.

## 7. Render deployment inspection

The connected Render `My Workspace` was inspected. It currently contains the production Node service `Whatsapp` and the Node staging service `wallza-connect-staging`, both backed by the `fkhan8811/Whatsapp` repository. No Mautic service or Wallza Mautic database is present in that workspace. The only listed Render PostgreSQL database belongs to Seasons of Lawn and is suspended; it must not be reused for Mautic.

The production WhatsApp service is live, on its `main` branch, Singapore region and Free plan; auto-deploy is disabled. The staging service is also in Singapore and tracks the Meta security-fix branch. No service configuration, secrets, production databases or deployment state were changed during this inspection.

Mautic deployment must be validated on its actual target (the planned Oracle host or a separately provisioned service). The WhatsApp Node service and Supabase PostgreSQL are not substitutes for Mautic's MySQL/MariaDB application database.

## 8. Deployment dependency

Mautic 7.2.2's database configuration uses MySQL/MariaDB. WallzaOS's Supabase PostgreSQL remains separate for SaaS control-plane data. Do not point Mautic's Doctrine database at Supabase PostgreSQL.

## 9. Remaining inputs and blockers

- Actual deployed BillionMail SMTP mailbox/submission hostname, port, TLS mode and credentials (credentials must never be committed to Git or exposed to browsers).
- A supported BillionMail provider delivery-event/webhook contract, including signature verification rules, if available in the deployed version.
- Mautic-specific tenant enforcement and SSO/user mapping are still required. The `Whatsapp` backend provides the identity/organization contract above, but it is not yet wired to Mautic.
- Sending-domain and per-tenant credential strategy.

Until tenant identity and enforcement are implemented end-to-end, this Mautic instance must not be exposed as a shared multi-tenant SaaS service.
