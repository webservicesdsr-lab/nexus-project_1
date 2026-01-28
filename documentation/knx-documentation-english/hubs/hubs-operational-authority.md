1. File Role

This document records the audited, observable authority and enforcement behavior of the HUBS domain in Kingdom Nexus. It describes what a hub is, where hub data is stored, who owns and may mutate hubs, how territory relationships (hub → city) are applied today, and where enforcement is explicit or permissive. All statements are evidence-based and reference behavior present in the codebase (see listed files in scope).

2. What a Hub Is (System Definition)

- A hub is a canonical operational location and business unit in Kingdom Nexus. Hubs contain identity (name, email, phone), location (address, latitude, longitude), operational configuration (delivery_radius, delivery_zone_type, delivery_zones), affiliation to a city (`city_id`), status flags (`status`, `is_featured`, `delivery_available`, `pickup_available`) and temporary closure metadata (`closure_start`, `closure_until`, `closure_reason`).
- Hubs act as the structural bridge between cities, managers, drivers, items/menus, and orders (evidence: hub_id used across `knx_hubs` operations, orders query by `hub_id`, delivery zones and driver mapping tables referenced in hub APIs).

3. Canonical Storage

- Canonical table: `{prefix}knx_hubs` is the single table for hub records (evidence: all hub APIs read/write `$wpdb->prefix . 'knx_hubs'`).
- Related canonical tables referenced by hub flows: `{prefix}knx_cities`, `{prefix}knx_delivery_zones`, and `{prefix}knx_hub_categories`.
- Some hub-related artifacts persist to filesystem (hub logos saved under `wp_upload_dir()/knx-uploads/{hub_id}`) and their URLs are stored in `knx_hubs.logo_url` (evidence: `api-upload-logo.php`).

4. Identity & Ownership Model

- Hub identity is owned by the `knx_hubs` record. The hub record stores `city_id` to express territorial association; `city_id` validation occurs on updates (evidence: `api-edit-hub-identity.php` checks `knx_cities` for `id` and `status`).
- There is no persistent owner field linking a single user `user_id` to a hub in the hub table itself. Ownership and operational scoping are derived from mapping tables and role mappings (evidence: manager ↔ hub mapping is resolved via `hub_managers`, `hub_admins`, `hub_users` candidate tables in multiple endpoints).

5. Role-Based Access

- Permission model sources: REST permission helpers (`knx_rest_permission_roles`, `knx_rest_permission_session`) govern route-level access; endpoints apply additional checks and nonces as needed (evidence: `knx-rest-guard.php` and hub API permission_callback usages).
- Public read access:
  - `GET /wp-json/knx/v1/hubs` (`api-hubs.php`) is public (`permission_callback => __return_true`) and returns hubs where `status = 'active'`.
  - `GET /wp-json/knx/v1/get-hub` (`api-get-hub.php`) is public (`permission_callback => __return_true`) and returns full hub details including `city_id` and `city_name`.
- Mutations and role restrictions (per-endpoint evidence):
  - Create Hub: `POST /knx/v1/add-hub` (`api-hubs-core.php`) uses `permission_callback => knx_rest_permission_session()` — any authenticated session can call this endpoint (nonce required in body). The handler validates optional `city_id` exists when provided.
  - Update Hub Identity: `POST /knx/v1/update-hub-identity` (`api-edit-hub-identity.php`) restricts to roles `['super_admin','manager','hub_management','menu_uploader','vendor_owner']` and requires a valid `knx_edit_hub_nonce`.
  - Update Hub Location / Polygon: `POST /knx/v1/update-hub-location` (`api-edit-hub-location.php`) restricts to roles `['super_admin','manager','hub_management','menu_uploader','vendor_owner']` and requires `knx_edit_hub_nonce`.
  - Save Hours: `POST /knx/v1/save-hours` (`api-hub-hours.php`) uses `permission_callback => knx_rest_permission_session()` (authenticated required) and nonce validation for edits.
  - Update Closure: `POST /knx/v1/update-closure` (`api-update-closure.php`) uses `knx_rest_permission_session()` and requires `knx_edit_hub_nonce`.
  - Update Settings: `POST /knx/v1/update-hub-settings` (`api-update-settings.php`) restricts to roles `['super_admin','manager']` and requires `knx_edit_hub_nonce`.
  - Upload Logo: `POST /knx/v1/upload-logo` (`api-upload-logo.php`) restricts to roles `['super_admin','manager','hub_management']` and requires `knx_edit_hub_nonce`.
  - Toggle Featured: `POST /knx/v1/toggle-featured` (`api-toggle-featured.php`) restricts to roles `['super_admin','manager']` and verifies WP nonce in header.
  - Delete Hub: `POST /knx/v1/delete-hub` (`api-delete-hub.php`) restricts to roles `['super_admin','manager','hub_management']` and requires `knx_edit_hub_nonce`.

6. Role-by-Role (Observed)

super_admin
- Treated as global: `super_admin` bypasses mapping restrictions where endpoints grant global access (evidence: code paths enumerate all hubs for `super_admin` when needed, and `super_admin` appears in allowed role lists across hub mutation endpoints).
- Full mutation rights: `super_admin` is included in all role-based permission lists for sensitive hub operations.

manager
- Role frequently permitted to mutate hubs: managers appear in the allowed role lists for identity update, location update, update settings, toggle featured, upload logo, and delete-hub endpoints.
- Effective scope: Manager operations are subject to per-endpoint checks (some endpoints apply additional hub mapping checks, many do not). As implemented, managers can invoke deletion and other mutations without an ownership check in the hub deletion handler (evidence: `api-delete-hub.php` performs no check that the acting manager is mapped to the hub being deleted).
- Create hub: managers may create hubs via `add-hub` because that route requires only an authenticated session; the manager role is not uniquely required.

driver
- Read access: drivers (and anonymous clients) can read hub details via public `get-hub` and `hubs` routes.
- Driver-specific enforcement: driver operational flows rely on driver-to-hub mapping (`driver_hubs`) and `knx_get_driver_context()` for driver-only behaviors; hubs themselves are visible publicly but driver-scoped mutation or ops require explicit mapping checks elsewhere (evidence: drivers are validated via `knx_get_driver_context()` and mapping in driver ops assign flow).
- Driver visibility depends on mapping only for driver-specific actions; general hub read endpoints are public.

others (hub_management, menu_uploader, vendor_owner, authenticated users)
- `hub_management`, `menu_uploader`, and `vendor_owner` are present in allowed role lists for identity/location edits and upload-logo; behavior is per-endpoint.
- Authenticated users (any role) can call `add-hub` because it uses `knx_rest_permission_session()`.

7. City Binding

- Hub → City: `knx_hubs.city_id` is the canonical binding between a hub and a city. `api-edit-hub-identity.php` validates `city_id` against `{prefix}knx_cities` and requires `status = 'active'` when `city_id > 0`.
- Enforcement is indirect: city scoping is enforced by validating `city_id` values on writes (prevents linking to invalid city), but role-based enforcement against city boundaries depends on per-endpoint mapping logic rather than a centralized check.
- Hub deletion and other mutations do not validate the acting user's city membership against `hub.city_id` (evidence: `api-delete-hub.php` performs no mapping/ownership check before deletion).

8. Operational State

- `status` (active / inactive): Hubs expose a `status` column; public hub listing returns only hubs with `status = 'active'` (`api-hubs.php` WHERE clause). Many mutation endpoints update `status` (identity update, toggle endpoint, etc.).
- `is_operational` / closures: Hub temporary closures are represented via `closure_start`, `closure_until`, and `closure_reason` (see `api-update-closure.php`). `api-hub-hours.php` stores structured `hours_*` columns. `api-hubs.php` sets `is_open = true` in its response (placeholder behavior) but real runtime availability is determined by combination of `status`, closure fields, and operating hours where consumers check them.
- `is_featured`: `is_featured` is stored and toggled via `api-toggle-featured.php` and used to compute counts of featured hubs.

9. Mutation Rules

- Nonce and REST protections: Sensitive write endpoints require nonce validation (`knx_edit_hub_nonce`) and use REST permission callbacks to ensure session/role presence.
- Validation on writes: Many endpoints validate required fields (email, address, lat/lng), and `api-edit-hub-identity.php` validates `city_id` and `category_id` existence and `status` on the referenced records.
- Cascading cleanup on delete: `api-delete-hub.php` performs transactioned cleanup across hub-related tables (`knx_hub_items`, `knx_orders`, `knx_order_items`, etc.) and deletes hub record and uploaded logo files. It also deletes delivery_rates rows by `city_id` when applicable.

10. Fail-Closed vs Permissive Paths

Fail-Closed (observed):
- City validity on identity updates: `update-hub-identity` returns `invalid_city` when `city_id` references a non-active city.
- Delivery zone polygon updates: `update-hub-location` requires valid lat/lng and at least 3 polygon points for polygon insertion.
- Nonce checks: Most mutation endpoints reject requests with invalid or missing nonces.

Permissive (observed):
- Create hub: `add-hub` requires only an authenticated session (`knx_rest_permission_session()`), not a specific role, enabling broader mutation capability.
- Manager mutation without ownership enforcement: `delete-hub` and several manager-allowed endpoints do not check that the acting manager is mapped to the hub's city/hub; this allows managers to mutate hubs outside their mapped hubs where no per-endpoint mapping checks exist.
- Public reads: Hub details and hub lists are public and do not require session validation.
- Schema-dependent behavior: Several flows check for related tables (e.g., delivery zones, mapping tables) via `SHOW TABLES LIKE` and adapt behavior based on their presence; missing tables or columns can make enforcement more permissive.

11. Cross-City Behavior (Evidence)

- Managers can mutate hubs across cities where endpoints do not enforce per-actor hub/city mapping. Concrete evidence:
  - `api-delete-hub.php`: permission_callback includes `manager`, and the handler performs no check that the actioning manager is mapped to the hub being deleted.
  - `api-edit-hub-identity.php` and `api-update-hub-settings.php`: managers are allowed by role lists and no actor→hub ownership check exists inside those handlers.
- Drivers and public clients can view hubs across cities via public endpoints: `api-hubs.php` and `api-get-hub.php` are public.
- Where strict scope exists, it is implemented in ad-hoc flows (for example, driver ops assign consults mapping tables and enforces allowed hubs), not as a uniform hub ownership enforcement.

12. Known Limits & Gaps

- No owner column on `knx_hubs`: There is no single canonical `owner_user_id` in the hub record; ownership is derived via mapping tables (if present) causing per-endpoint derivation and inconsistency.
- Manager scope not enforced uniformly: Role-level permission lists include `manager` for many mutation endpoints but per-actor hub/city membership checks are missing in many handlers (notably `delete-hub`), enabling cross-city mutations by managers.
- Creation permission is broad: `add-hub` requires only an authenticated session, not a specific role.
- Schema-dependent enforcement: Presence or absence of mapping tables and columns influences enforcement (code branches on `SHOW TABLES LIKE` and `SHOW COLUMNS`), making behavior deployment-dependent.
- Public visibility: Hub read endpoints are public; internal consumers must apply additional checks if they require restricted visibility.

13. Canonical Status

Status: CANONICAL WITH LIMITS

- Why HUBS is canonical: `knx_hubs` is the canonical table for hub data. Hub identity, location, operational flags, delivery zones, and logo URL are persisted in canonical locations and referenced by downstream domains (orders, drivers, delivery rates).
- Why not sealed: Ownership and territory enforcement are inconsistent. Mutations commonly rely on role lists and per-endpoint checks rather than a centralized actor→hub ownership enforcement. Schema-dependent code paths and several permissive endpoints (public reads, authenticated add-hub, manager mutations without mapping checks) prevent classifying HUBS as fully sealed.

14. Architectural Principle

Hubs are canonical operational primitives that persist identity, location, and runtime configuration. Territory affiliation is explicit via `city_id`, but actor-bound enforcement is implemented per-endpoint and depends on mapping tables. Authority for hub mutation combines REST permission helpers, nonce validation, and ad-hoc mapping checks where present; absence of a single owner claim makes per-endpoint enforcement the instrument of control.
