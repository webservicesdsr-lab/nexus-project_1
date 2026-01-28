1. File Role

This document records the audited, observable behavior of the USERS / ROLES domain in Kingdom Nexus. It describes how identity is represented, how roles are resolved and enforced, how territory binding is attempted, and where enforcement is centralized or distributed. This file does not propose changes; it records current implementation and limits.

2. Identity Model

- Identity SSOT: `knx_users` (database). User identity and credential data are stored in the `knx_users` table and joined into sessions when a session is resolved.
- Session token: `knx_session` cookie is the canonical session bearer token. `knx_get_session()` reads this cookie, validates the session row in `knx_sessions`, joins `knx_users`, and returns a session object containing at least `user_id`, `username`, `email`, `role`, and `status`.
- No persistent `city_id` on session: The session object returned by `knx_get_session()` does not include an explicit `city_id` or `managed_cities` attribute.

3. Role Hierarchy

- Roles are stored in `knx_users.role` and provided on the session object (evidence: `knx_get_session()` SELECT includes `u.role`).
- Canonical hierarchy: `knx_require_role()` implements a numeric hierarchy used across the codebase: `user/customer (1) < menu_uploader (2) < hub_management (3) < manager (4) < super_admin (5)`.
- REST permission helpers use the session role server-side (`knx_rest_permission_roles`, `knx_rest_permission_driver_or_roles`) to accept or reject requests based on allowed role lists.

4. Session Authority

- Canonical session resolver: `knx_get_session()` (in `inc/functions/helpers.php`) is the single source of truth for session resolution and is used by REST guard wrappers (`knx_rest_get_session()` delegates to it).
- Session enforcement points: REST permission callbacks call `knx_rest_get_session()` and return `401` when no valid session exists; `knx_require_role()` and `knx_rest_permission_roles()` return `403` when role checks fail.
- Session contents: Sessions include `user_id`, `role`, `token`, and other user metadata. There is no embedded territory claim in the session.

5. Territory Binding (Current Behavior)

- No explicit city in session: The system does not place a `city_id` claim into the session object by default. Territory binding is not part of session SSOT.
- Binding via mapping tables: Territory association is derived at runtime by consulting mapping tables and relationships (e.g., `hub_managers`, `hub_admins`, `hub_users`, `driver_hubs`) and by traversing hub → city relationships when needed.
- Per-endpoint scoping: Territory enforcement is implemented on a per-endpoint basis. Some endpoints compute `allowed_hubs` for a manager by querying mapping tables and then enforce hub membership; other endpoints perform role-only checks and do not examine mappings.
- Hub → city relationship: When enforced, city scoping is enacted indirectly via hub membership (hub records include `city_id`), not by a direct `managed_cities` claim on the user/session.

6. Role-by-Role Scope Analysis

super_admin
- Global authority: `super_admin` is treated as a global role in multiple places (e.g., order listing, ops assign). Where code computes allowed hubs, `super_admin` is allowed all hubs by enumerating `knx_hubs`.
- Cross-city access: `super_admin` has unrestricted cross-city visibility and operations.

manager
- Intended scope: Managers are documented as city-scoped via hub relationships (comments in order listing and other files).
- Actual enforcement: Mixed. Some endpoints enforce hub membership for managers (example: `knx_api_ops_assign()` resolves `allowed_hubs` from mapping tables and denies operations when the target `hub_id` is outside `allowed_hubs`). Many administrative endpoints validate only role membership (`knx_rest_permission_roles(['super_admin','manager'])`) and do not perform hub/city mapping checks; examples include drivers CRUD and hub deletion endpoints.
- Result: Managers can perform cross-city operations on endpoints that do not implement hub/city checks after role authorization.

driver
- Driver identity: Driver sessions are validated by `knx_get_session()` and driver-specific context is resolved by `knx_get_driver_context()` which requires `session.role === 'driver'` and verifies the presence of an active driver profile in `knx_drivers`.
- Hub scoping: `knx_get_driver_context()` attempts to resolve mapped hubs via `driver_hubs` mapping table (if present) and returns an object with `hubs` array. The canonical driver resolver enforces fail-closed for missing or inactive driver profiles; however, `hubs` may be empty and some flows permit empty arrays.
- Enforcement: Driver-specific permission guards (`knx_rest_permission_driver_context`) delegate to `knx_get_driver_context()`; when used, driver flows are scope-limited by the returned `hubs` array, except where callers accept empty hub lists.

other roles
- hub_management, menu_uploader, customer, user, etc. follow role-only checks by default. Where scoping is required, endpoints implement ad-hoc mapping checks (candidate tables), otherwise role membership alone determines access.

7. Enforcement Patterns

- Centralized role checks: `knx_require_role()` and `knx_rest_permission_roles()` centralize role membership enforcement; those helpers gate many protected endpoints.
- Decentralized territory checks: Territory enforcement is decentralized. Endpoints that require territory scoping compute `allowed_hubs` or query mapping tables and then perform membership checks; there is no single territory-enforcement abstraction applied globally.
- Schema-aware behavior: Enforcement logic often checks for the existence of mapping tables or columns (`SHOW TABLES LIKE`, `SHOW COLUMNS`) and adapts behavior accordingly; this creates environment-dependent enforcement.

8. Cross-City Access (Observed)

- Non-super_admin cross-city operations observed (evidence):
  - `inc/core/resources/knx-drivers/api-drivers-crud.php` — manager role can list, create, update, toggle driver records; handlers do not check hub or city mappings.
  - `inc/core/resources/knx-hubs/api-delete-hub.php` — manager role is permitted by role check to call delete-hub; handler does not verify that the acting manager is mapped to the hub being deleted.
  - `inc/core/knx-orders/api-list-orders.php` — manager listing code explicitly allows all hubs temporarily (commented as "TEMPORARY: Allow all hubs until city-scoping is fully implemented"), enabling cross-city visibility.

9. Fail-Closed vs Permissive Paths

Fail-Closed (observed):
- Session resolution: `knx_get_session()` returns false when cookie missing, session expired, or user not active.
- Driver context resolution: `knx_get_driver_context()` fails when driver profile missing or inactive.
- Writes requiring a valid mapping: Some writes (e.g., delivery-rate writes, driver-ops assign when mapping applies) validate mappings and deny when mappings are absent.

Permissive (observed):
- Many admin endpoints rely on role membership only and do not validate hub/city mapping, allowing cross-city operations for managers.
- Enforcement that depends on mapping tables falls back permissively when mapping tables or columns are absent (schema-dependent permissiveness).
- Driver `hubs` may be empty; some flows accept empty arrays rather than denying, producing permissive behavior.

10. Known Limits & Risks

- No `city_id` claim in session: Lack of a persistent territory claim in the session requires per-endpoint derivation and increases risk of inconsistent enforcement.
- Inconsistent scoping: Territory binding is implemented ad-hoc per endpoint. This leads to endpoints that enforce hub/city scoping and endpoints that do not.
- Schema-dependent behavior: Enforcement checks for mapping table/column existence and changes behavior accordingly; a missing mapping table can create permissive behavior in that deployment.
- Partial driver binding: `knx_get_driver_context()` enforces driver profile existence, but its allowance of empty `hubs` creates ambiguity in whether drivers are effectively scoped.

11. Canonical Status

Status: CANONICAL WITH LIMITS

- Why canonical: Identity, sessions, and roles are canonical. `knx_users` and `knx_sessions` are SSOTs for identity and session resolution. Role checks are enforced server-side via centralized helpers used by many endpoints.
- Why not sealed: Territory binding is inconsistent, implemented per-endpoint, and often schema-dependent. Multiple administrative endpoints do not enforce hub/city scoping after role authorization. These limits are observable and documented here.

12. Architectural Principle

Identity and role are canonical server-side primitives; territory is derived via hub mappings and is enforced where implemented. Visibility and control depend on both role membership and explicit mapping checks; absence of a session-level territory claim makes territory enforcement a per-endpoint responsibility.
