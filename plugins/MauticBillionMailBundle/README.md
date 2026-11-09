# WallzaOS BillionMail SMTP transport

This plugin is packaged as a Mautic 7 plugin (`mautic/plugin-wallza-billionmail`) and adds two explicit Symfony Mailer DSN schemes:

- `billionmail+smtp://` for SMTP submission with STARTTLS negotiation.
- `billionmail+smtps://` for implicit TLS (normally port 465).

The plugin delegates message delivery to Symfony's native ESMTP transport. Mautic still renders the email, expands contact tokens, manages campaign queues, applies its own tracking and unsubscribe links, and records its normal send results. BillionMail is the SMTP delivery server.

## Configure

1. In BillionMail, create a domain and mailbox, then retrieve the SMTP submission host, port, username and password made available for that mailbox.
2. Confirm the SMTP submission endpoint is reachable from the Mautic application/container. Use the hostname covered by the server's TLS certificate.
3. In Mautic, open **Settings → Configuration → Email Settings** and set the mailer DSN to one of these patterns.

STARTTLS example:

```text
billionmail+smtp://MAILBOX_USER:URL_ENCODED_PASSWORD@mail.example.com:587?auto_tls=true&require_tls=true
```

Implicit TLS example:

```text
billionmail+smtps://MAILBOX_USER:URL_ENCODED_PASSWORD@mail.example.com:465?require_tls=true
```

Replace the example host, port and credentials with the actual values shown by your BillionMail deployment. The examples are patterns, not default BillionMail ports or credentials. URL-encode reserved characters in the username or password.

Optional throughput controls:

```text
billionmail+smtp://USER:PASSWORD@mail.example.com:587?auto_tls=true&require_tls=true&max_per_second=4&restart_threshold=100
```

- `max_per_second`: per-process send rate limit; omit to leave Symfony's default behavior.
- `restart_threshold`: recycle the SMTP connection after the configured number of messages.
- `restart_threshold_sleep`: optional pause in seconds after recycling.
- `ping_threshold`: ping an idle connection after this many seconds.
- `local_domain`: explicit EHLO/HELO name.
- `source_ip`: bind the SMTP socket to a source IP when the host supports it.

TLS is required by default, and certificate verification is enabled. An administrator can explicitly disable the TLS requirement with `require_tls=false` only when the endpoint is intentionally trusted on a private network. This plugin intentionally does not add an option to turn certificate verification off.

## Important distinction: BillionMail API versus SMTP

BillionMail's documented HTTP Send API is template-based: an API key is associated with a BillionMail email template, and the send request supplies a recipient, sender and template attributes. It does not accept the arbitrary rendered HTML body produced by a Mautic campaign. Therefore this plugin uses SMTP for normal Mautic campaigns rather than silently replacing Mautic email content with a BillionMail-side template.

BillionMail's **SMTP Relay** settings configure how BillionMail itself forwards outbound messages to an external provider such as SES or Mailgun. They are not the SMTP host credentials Mautic should automatically copy. Use the mailbox/submission endpoint and credentials supplied by your actual deployment.

## Install / discover

The bundle is located under `plugins/MauticBillionMailBundle` and includes Composer package metadata. In a source-based Mautic deployment, deploy this plugin folder with the Mautic code, run the normal Composer/autoload and Mautic cache-clear/plugin-discovery procedure for your release, then confirm the bundle and `mailer.transport_factory` service are present in the compiled container. Do this in staging first; do not assume that placing files on disk alone activates the plugin.

## Verify

1. Save the DSN in Mautic and use **Test Connection**.
2. Send a sample email to a controlled mailbox.
3. Confirm the message arrives and that Mautic records the send.
4. Run a small campaign against test contacts before enabling a production segment.
5. Confirm SPF, DKIM and DMARC for the sender domain, and check BillionMail logs/queue for delivery failures.

## Scope and limitations

- This is an SMTP transport adapter, not a BillionMail management API client.
- This adapter deliberately uses Mautic's ordinary per-contact SMTP send path. It does not use Mautic's `TokenTransportInterface` for provider-side batch tokenization and does not add asynchronous provider callback handling.
- BillionMail's public Send API does not currently provide a documented callback contract in the inspected fork for mapping provider delivery/bounce events back into Mautic. Provider-side delivery reconciliation is therefore not claimed as implemented here.
- The plugin does not implement tenant isolation. WallzaOS tenant authorization must be enforced by the actual WallzaOS backend and/or separate Mautic deployment/database boundaries before exposing this service to multiple customer workspaces.
- SMTP credentials must be stored only in Mautic's protected configuration/secret store and must never be sent to browsers or committed to Git.
