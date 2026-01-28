# User Capability & Territory Model (EN)

Purpose
- This master document unifies the audited role capability documents into a single canonical mapping. All statements below are evidence-only and reference audited code paths and files.

Canonical statement
- CANONICAL WITH LIMITS: The system defines a canonical global authority (`super_admin`) that holds explicit, sealed powers for system-level actions; other admin roles (`manager`) have local intent but, in places, the codebase grants global access or leaves scoping incomplete.

What is sealed to `super_admin` (evidence-only)
- Sealed endpoints (route registration or handler-level checks):
  - City management add/delete: `inc/core/resources/knx-cities/add-city.php`, `inc/core/resources/knx-cities/delete-city.php` (`POST /knx/v2/cities/add`, `POST /knx/v2/cities/delete`) — registered as `super_admin` only.
  - Delivery rates getter/updater: `inc/core/resources/knx-cities/get-delivery-rates.php`, `inc/core/resources/knx-cities/update-delivery-rates.php` — route-level `super_admin` permission.
  - OPS `force-status`: (historical endpoint removed from repository)

Where `super_admin` is global (evidence-only)
- Handler code intentionally implements global semantics for `super_admin` in multiple endpoints:
  - OPS assign: `super_admin` branch sets `$allowed_hubs` to all hubs.
  - Hubs/Drivers lists: server queries in shortcodes and driver endpoints return global rows (no manager filter), enabling `super_admin` to operate on all rows.
  - Hub delete/update handlers accept `super_admin` and perform global cascade deletes (e.g., `knx_api_delete_hub_v3()`).

Manager vs Super Admin (evidence-only)
- `manager` is an intended local role and some endpoints enforce manager scoping when DB migration columns exist (e.g., `knx_hubs.manager_user_id` used in `GET /knx/v2/cities/get` and `POST /knx/v2/cities/operational-toggle`).
- However, many admin endpoints include `manager` in permission arrays while handler code does not enforce manager ownership, and some order-list code explicitly contains `TEMPORARY` comments allowing all hubs.

Territory model summary (evidence-only)
- Sealed territory: city CRUD and delivery rate operations are sealed to `super_admin` (system-level territory control).
- Intended territory: `manager` is intended to operate on assigned hubs/cities when `knx_hubs.manager_user_id` is present — evidence: city endpoints perform manager-scoped joins and checks.
- Actual implemented territory: several endpoints return global data or permit manager-permitted calls without handler-level owner checks (drivers list, hubs list, orders list), and `super_admin` code paths explicitly act globally.

VERDICT (evidence-only)
- CANONICAL WITH LIMITS — `super_admin` is the canonical global authority; `manager` is intended local but scoping is incomplete in parts of the codebase.
