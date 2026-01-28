# Super Admin — Capability & Navigation (EN)

Purpose
- Role: `super_admin` — the canonical, system-wide authority for the Kingdom Nexus plugin. This document records, from audited code evidence, what `super_admin` can view and do in UI and backend.

Role and purpose (evidence only)
- The `super_admin` role is used in route permission callbacks and handler-level checks to grant exclusive system-level actions. See examples of sealed route registrations and handler checks in the codebase.

Endpoints explicitly exclusive to `super_admin` (file → function → route → behavior)
- `inc/core/resources/knx-cities/add-city.php` → `knx_v2_add_city()`
  - `POST /knx/v2/cities/add` — route registered with `knx_rest_permission_roles(['super_admin'])`; handler enforces `super_admin` and a nonce; inserts a city row.
- `inc/core/resources/knx-cities/delete-city.php` → `knx_v2_delete_city()`
  - `POST /knx/v2/cities/delete` — route registered as `super_admin` only; handler calls `knx_rest_require_role($session, ['super_admin'])`, requires nonce, blocks delete if city has hubs, performs soft-delete.
- `inc/core/resources/knx-cities/get-delivery-rates.php` → (getter)
  - `GET /knx/v2/cities/get-delivery-rates` — route-level permission requires `super_admin`.
- `inc/core/resources/knx-cities/update-delivery-rates.php` → (updater)
  - `POST /knx/v2/cities/update-delivery-rates` — route-level permission requires `super_admin`; handler requires nonce and upserts delivery rates.
 - (legacy ops force-status endpoint removed from repository)

Endpoints shared with other admin roles where `super_admin` acts globally (file → behavior)
- Hubs CRUD and delete
  - `inc/core/resources/knx-hubs/api-hubs-core.php`, `inc/core/resources/knx-hubs/api-delete-hub.php`, `inc/core/resources/knx-hubs/api-update-hub-identity.php` — route registrations include `super_admin` and `manager` (and sometimes `hub_management`). Handler code (e.g., `knx_api_delete_hub_v3()`) performs cascade deletes and accepts super_admin without owner checks.
- Drivers CRUD
  - `inc/core/resources/knx-drivers/api-drivers-crud.php` — drivers list/create/update/toggle/reset-password endpoints include `super_admin` in permission arrays. `drivers-list` returns global driver rows (no hub filter).
  - OPS assign/unassign/cancel (legacy endpoints removed from repository)
  - Orders lists: some older handlers returned orders across hubs by default; live proxy evidence removed.

Explicit bypasses and their justification (evidence-only)
- `force-status` (ops): registered and enforced as `super_admin` only — evidence: route registration uses `knx_rest_permission_roles(['super_admin'])`.
- OPS assignment: `super_admin` path sets `$allowed_hubs` to all hubs in code (explicit global authority in handler).
- Hubs/Drivers lists: server-side queries return global rows (no manager scoping) — thus `super_admin` can act on any returned row.

Differences vs `manager` (evidence-only)
- UI visibility: many admin shortcodes and navigation items are visible to both `manager` and `super_admin` (see `inc/functions/navigation-engine.php` and shortcodes in `inc/modules/*`).
- Server-side enforcement: some endpoints are sealed to `super_admin` (cities add/delete and delivery-rates, ops force-status). Other endpoints include `manager` in permission arrays but allow `super_admin` a global code path (assign, drivers, hubs delete).
- Manager-scoped endpoints: `GET /knx/v2/cities/get` and `POST /knx/v2/cities/operational-toggle` contain manager-scoping logic when DB column `knx_hubs.manager_user_id` exists; several other admin endpoints do not perform per-manager ownership checks in handler code.

VERDICT (evidence-only)
- CANONICAL — GLOBAL AUTHORITY
