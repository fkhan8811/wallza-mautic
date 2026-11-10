# WallzaOS Mautic — Tenant Isolation Design

**Status:** Per-tenant Oracle Compose data-plane template and Mautic-side Wallza SSO plugin implemented; not yet deployed, capacity-tested or connected to the WallzaOS authorization/token endpoints  
**Baseline:** Mautic 7.2.2  
**Identity discovery:** The connected Lovable project named `WallzaOS` is the public website/admin CMS. The connected `fkhan8811/Whatsapp` repo is the actual product backend reference: Supabase Auth user identity; `organizations` as tenant; `organization_members` and `agents` membership; `user_roles`, `permissions`, `role_permissions` and `user_permissions_override` for role/permission checks. Its server-side `requireOrganizationAccess` validates a bearer token with `supabase.auth.getUser(token)` and checks membership in the requested organization. Its `authorizeMetaOrganization` adds the stricter `integrations.manage` permission check. The website CMS has a separate content-team RBAC model; it is not the product-app tenant model.

## 1. Security objective

Every customer workspace must be unable to read, mutate, infer, export, send to, or administer another workspace's data. Tenant context must be enforced server-side. A client-supplied tenant ID is never an authorization decision.

Mautic's existing user ownership and own/other permissions are useful but do not constitute tenant isolation.

## 2. Initial deployment isolation decision

For the first WallzaOS rollout, do **not** run multiple organizations in one Mautic database. Each organization receives a separate Mautic stack with its own MariaDB database, Mautic configuration volume, logs, uploaded media, private network and API credential. The image/code is shared, but customer data and runtime state are not.

The repeatable template is in `deploy/oracle/`:
- The image is pinned to the official Mautic 7.2.2 Apache release and copies both the BillionMail SMTP and Wallza Identity plugins into the official Docker plugin directory.
- MariaDB is the only Mautic application database; Supabase PostgreSQL remains the WallzaOS control plane.
- Web, cron and Messenger worker containers run separately within the tenant's stack. The initial worker pool is capped at one consumer each for email, hit and failed queues to avoid the official image's six-process default overwhelming the 2-vCPU pilot host.
- The Mautic web listener is bound to loopback and must sit behind the host HTTPS reverse proxy.
- The tenant provisioner creates a unique Compose project, random DB credentials and separate named volumes/network for each tenant slug; it refuses to overwrite an existing tenant env file and uses atomic locks to prevent simultaneous provisioning for the same slug or host port.

This template is a manual data-plane isolation mechanism, not the finished SaaS control plane. It has not yet been deployed on the Oracle host, load-tested alongside the other WallzaOS services, or wired to Supabase self-service provisioning. The safe initial policy is one stack per authorized organization and no shared Mautic DB.
## 3. WallzaOS identity and tenant mapping

- WallzaOS remains the system of record for SaaS accounts, subscriptions and workspace membership.
- A Mautic workspace maps to an immutable WallzaOS tenant identifier.
- A Mautic user identity is mapped to a WallzaOS user and membership.
- Tenant membership and role claims are resolved from trusted server-side identity, not accepted from arbitrary request parameters.
- Every tenant-bound credential is scoped to one tenant and revocable.

The WallzaOS reference backend has now been inspected. The Mautic-side SSO client is now implemented in `plugins/MauticWallzaIdentityBundle` using Mautic's native `sso_service` hook, session-bound state, a server-to-server authorization-code exchange, and a tenant-bound HS256 assertion. The corresponding WallzaOS authorization-code/token endpoints and protected tenant registry are still a separate backend task. Mautic does not decode browser-supplied Supabase JWTs or trust client-provided tenant IDs. The WallzaOS server must validate the Supabase access token and active organization membership before issuing a one-time code and assertion. Do not trust custom headers from public clients.

## 4. Current WallzaOS identity and tenant contract

The existing WhatsApp backend establishes the following source-of-truth contract:

1. Authenticate the bearer token with Supabase Auth `getUser(token)`.
2. Require the authenticated user to be present in `organization_members` or `agents` for the requested organization. Membership rows may have an `is_active` flag; use active membership where the contract requires it.
3. For privileged integration operations, require the organization owner or a valid role/permission grant (`integrations.manage`) through organization-scoped roles, role permissions and user permission overrides.
4. Record tenant/user context server-side and retain it through asynchronous work. The `organization_id` in a request does not grant access by itself.

The existing backend's database migrations include older broad `auth.uid() IS NOT NULL` policies as well as later org-scoped RLS migrations. Do not assume every table is tenant-safe merely because RLS is enabled. Mautic integration must independently enforce authorization on every request and worker path.

Mautic has not yet been connected to this identity contract: no Supabase SSO, Mautic-user mapping, tenant-context resolver or organization-scoped Mautic entity filter has been implemented in this branch.

## 5. Enforcement points

Tenant context must be established and enforced at all of these boundaries:

1. Browser routes and controllers.
2. API Platform collection and item operations.
3. Doctrine reads and writes, including repositories and custom SQL/DBAL.
4. Campaign execution, real-time events and scheduled commands.
5. Messenger consumers and retries.
6. Webhook queue creation and delivery.
7. Imports, exports, bulk actions and contact merges.
8. Cache keys, temporary files, uploaded assets and generated exports.
9. Audit logs, analytics and reporting.
10. Admin/support operations with explicit, audited break-glass access.

A filter alone is insufficient: background jobs and custom SQL can bypass ORM-level filters. Prefer explicit tenant-aware repository/service boundaries and fail closed if tenant context is missing for a tenant-bound operation.

## 6. Data model direction

Before adding tenant columns, inventory every entity and table that can contain customer-owned or customer-derived data. Classify each as:

- tenant-owned,
- globally shared reference/configuration,
- platform operations,
- derived/aggregate data.

For tenant-owned records, define the tenant key, uniqueness scope, indexes, foreign-key strategy and migration/backfill plan. Avoid indiscriminately adding a tenant column to every table.

## 7. Required negative tests

- Tenant A cannot GET, PATCH, DELETE or enumerate Tenant B contacts, segments, campaigns, emails or assets.
- Tenant A cannot use guessed object IDs to access Tenant B objects.
- Tenant A API token cannot access Tenant B data.
- A background job created for Tenant A cannot process Tenant B recipients.
- A webhook retry retains its original tenant and cannot be redirected to another tenant.
- Missing tenant context fails closed for tenant-bound work.
- Cache keys and exports cannot collide or leak across tenants.
- Imports, bulk operations and contact merges cannot cross tenant boundaries.
- Platform support access is explicit, time-limited and audited.

## 8. Rollout sequence

1. Map current WallzaOS authentication, tenant and user model.
2. Keep customer data separated using one Mautic stack/database per organization for the initial rollout.
3. Implement a protected server-side tenant registry mapping authenticated organizations to Mautic URLs and API credentials.
4. Implement membership-aware SSO/user provisioning and subscription/quota checks.
5. Propagate the authorized tenant mapping through WallzaOS jobs, retries and webhook workflows.
6. Add negative tests proving one organization cannot resolve or call another organization's Mautic stack.
7. Capacity-test each stack on the actual Oracle host before expanding the number of tenants.
8. Revisit shared-database tenant enforcement only as a separate architecture project; a Doctrine filter alone is insufficient.

## 9. Release gate

Do not enable multi-customer production onboarding until every tenant-owned data path has a documented enforcement point and the negative test suite passes. Single-tenant mode remains the safe fallback while this work is incomplete.
