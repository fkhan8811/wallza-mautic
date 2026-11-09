# WallzaOS Mautic — Tenant Isolation Design

**Status:** Design baseline; implementation not yet certified  
**Baseline:** Mautic 7.2.2  
**Identity discovery:** The connected Lovable project named `WallzaOS` is the public website/admin CMS. The connected `fkhan8811/Whatsapp` repo is the actual product backend reference: Supabase Auth user identity; `organizations` as tenant; `organization_members` and `agents` membership; `user_roles`, `permissions`, `role_permissions` and `user_permissions_override` for role/permission checks. Its server-side `requireOrganizationAccess` validates a bearer token with `supabase.auth.getUser(token)` and checks membership in the requested organization. Its `authorizeMetaOrganization` adds the stricter `integrations.manage` permission check. The website CMS has a separate content-team RBAC model; it is not the product-app tenant model.

## 1. Security objective

Every customer workspace must be unable to read, mutate, infer, export, send to, or administer another workspace's data. Tenant context must be enforced server-side. A client-supplied tenant ID is never an authorization decision.

Mautic's existing user ownership and own/other permissions are useful but do not constitute tenant isolation.

## 2. Proposed identity model

- WallzaOS remains the system of record for SaaS accounts, subscriptions and workspace membership.
- A Mautic workspace maps to an immutable WallzaOS tenant identifier.
- A Mautic user identity is mapped to a WallzaOS user and membership.
- Tenant membership and role claims are resolved from trusted server-side identity, not accepted from arbitrary request parameters.
- Every tenant-bound credential is scoped to one tenant and revocable.

The WallzaOS reference backend has now been inspected. The exact Mautic bridge (OIDC/Supabase JWT validation, trusted reverse-proxy assertion, or server-to-server API gateway) still must be implemented. Mautic must validate the Supabase identity and organization membership through a trusted server-side path; the caller-supplied organization ID is a selector, never authorization. Do not trust custom headers from public clients.

## 3. Current WallzaOS identity and tenant contract

The existing WhatsApp backend establishes the following source-of-truth contract:

1. Authenticate the bearer token with Supabase Auth `getUser(token)`.
2. Require the authenticated user to be present in `organization_members` or `agents` for the requested organization. Membership rows may have an `is_active` flag; use active membership where the contract requires it.
3. For privileged integration operations, require the organization owner or a valid role/permission grant (`integrations.manage`) through organization-scoped roles, role permissions and user permission overrides.
4. Record tenant/user context server-side and retain it through asynchronous work. The `organization_id` in a request does not grant access by itself.

The existing backend's database migrations include older broad `auth.uid() IS NOT NULL` policies as well as later org-scoped RLS migrations. Do not assume every table is tenant-safe merely because RLS is enabled. Mautic integration must independently enforce authorization on every request and worker path.

Mautic has not yet been connected to this identity contract: no Supabase SSO, Mautic-user mapping, tenant-context resolver or organization-scoped Mautic entity filter has been implemented in this branch.

## 4. Enforcement points

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

## 5. Data model direction

Before adding tenant columns, inventory every entity and table that can contain customer-owned or customer-derived data. Classify each as:

- tenant-owned,
- globally shared reference/configuration,
- platform operations,
- derived/aggregate data.

For tenant-owned records, define the tenant key, uniqueness scope, indexes, foreign-key strategy and migration/backfill plan. Avoid indiscriminately adding a tenant column to every table.

## 6. Required negative tests

- Tenant A cannot GET, PATCH, DELETE or enumerate Tenant B contacts, segments, campaigns, emails or assets.
- Tenant A cannot use guessed object IDs to access Tenant B objects.
- Tenant A API token cannot access Tenant B data.
- A background job created for Tenant A cannot process Tenant B recipients.
- A webhook retry retains its original tenant and cannot be redirected to another tenant.
- Missing tenant context fails closed for tenant-bound work.
- Cache keys and exports cannot collide or leak across tenants.
- Imports, bulk operations and contact merges cannot cross tenant boundaries.
- Platform support access is explicit, time-limited and audited.

## 7. Rollout sequence

1. Map current WallzaOS authentication, tenant and user model.
2. Produce Mautic entity/table ownership inventory.
3. Add tenant context resolver and trusted identity mapping.
4. Add tenant-aware repository/API enforcement for one vertical slice.
5. Add background worker and webhook propagation.
6. Add migrations and backfill strategy.
7. Add cross-tenant negative tests.
8. Expand entity coverage only after tests prove the first slice.

## 8. Release gate

Do not enable multi-customer production onboarding until every tenant-owned data path has a documented enforcement point and the negative test suite passes. Single-tenant mode remains the safe fallback while this work is incomplete.
