```markdown
# Manager — Capability & Navigation (EN)

Purpose
- Role: `manager` — local admin for one or more hubs; this document records, from audited code evidence, manager-facing capabilities and constraints.

What `manager` can do (evidence-only)
- Hub edit/create UI: `inc/modules/hubs/edit-hub-template.php` and `inc/modules/hubs/hubs-shortcode.php` — modules expose hub edit UX.
- Hubs API: `inc/core/resources/knx-hubs/api-hubs-core.php` — route registrations include `manager` in permission arrays for hub CRUD operations.
- City operational toggle: `inc/core/resources/knx-cities/post-operational-toggle.php` — handler contains manager-scoping logic when `knx_hubs.manager_user_id` is present.
- Ops assignments (scoped): `inc/core/resources/knx-ops/api-ops-orders.php` — endpoints accept `manager` and the handler attempts to enforce allowed hubs for manager paths (code contains `TEMPORARY` notes where scoping is incomplete).

What `manager` cannot do (evidence-only)
- City add/delete and delivery-rates management: these are registered `super_admin` only (see `inc/core/resources/knx-cities/add-city.php` / `delete-city.php` / `update-delivery-rates.php`).
- Some hub delete/update handlers include `manager` in permission arrays but do not consistently enforce ownership in handler body (observed in `inc/core/resources/knx-hubs/api-delete-hub.php`).

Notes on scoping and ownership (evidence-only)
- Manager scoping exists but is inconsistently enforced in handlers. Where the DB schema lacks `knx_hubs.manager_user_id`, manager actions may be blocked or behave differently.

VERDICT (evidence-only)
- Managers have local admin capabilities for hubs they own in many UI flows; code contains temporary exceptions where global access is permitted until scoping is finalized.

```