# Driver — Capability & Ops Model (EN)

Purpose
- Role: `driver` — operational actor that receives assignments from OPS and performs deliveries. This document records, from audited code evidence, the driver-facing capabilities, the ops model interactions, and how driver context appears in the codebase.

What drivers can do / Ops interactions (evidence-only)
- Assignment and ops pipeline:
  - `inc/core/resources/knx-ops/api-ops-orders.php` — OPS endpoints include `assign`, `unassign`, `cancel`, and `list`. Client OPS UI (`inc/modules/ops/ops-script.js`) calls these endpoints. Assign/unassign/cancel operations are executed by server handlers which accept `super_admin` and `manager` permissions; drivers are the recipients of assignments.
- Drivers CRUD / list:
  - `inc/core/resources/knx-drivers/api-drivers-crud.php` — drivers list endpoint returns driver rows globally; drivers can be created/updated/toggled/reset by admin endpoints (permission includes `super_admin` and `manager`).

Driver context helper (evidence-only)
- `inc/functions/helpers.php` defines `knx_get_acting_driver_context($as_driver_id = 0)`. The helper exists in the codebase (definition present). A repository search showed the helper definition but no additional call-sites in the audited search results.

Where driver authority is controlled (evidence-only)
- Drivers act as targets of OPS actions. Assignment logic enforces allowed hubs for manager paths; `super_admin` path sets allowed hubs to all hubs (explicit global authority for assignment). The driver receives assignment via the OPS pipeline endpoints.

Integration points in UI (evidence-only)
- OPS UI (`inc/modules/ops/ops-script.js`) loads drivers via configured `endpoints.drivers` and renders driver selection UI for each order.
- Drivers admin UI (`inc/modules/drivers/drivers-admin-shortcode.php` + script) exposes create/update/toggle/reset-password endpoints.

VERDICT (evidence-only)
- Drivers operate within the OPS pipeline and are assigned/unassigned by admin roles; `super_admin` has global assignment authority in code paths.
