**File Role**

- **Primary files referenced:** `inc/core/knx-orders/api-quote-totals.php`, `inc/core/knx-orders/api-create-order-mvp.php`, `inc/core/knx-orders/api-get-order.php`, `inc/core/knx-orders/api-list-orders.php`, `inc/core/knx-orders/api-update-order-status.php`.
- **Supporting dependency referenced:** `inc/functions/helpers.php` (session and role authority; previously classified as NOT SAFE / CANONICAL WITH LIMITS).

**Orders Phase 1 Scope**

This document records the audited behavior for Orders — Phase 1 (Foundation) as implemented by the files listed above. It describes the operational contract for quoting, order creation, read/list access, and status updates. It does not propose changes or re-audit code; it restates concrete behaviors observed in the codebase.

**Quote Authority**

- Quotes are server-authoritative. The quote endpoint (`api-quote-totals.php`) computes subtotal from `knx_cart_items.line_total`, computes delivery coverage/distance/fee, resolves fees and taxes, constructs a sealed delivery snapshot (`delivery_snapshot_v46`), and returns a server-calculated `order_snapshot` to be used by create-order.
- The quote endpoint is the canonical source of totals and delivery data used by the create-order path.

**Order Snapshot Creation**

- Create-order (`api-create-order-mvp.php`) requires a `snapshot` object in the request body and fails closed when the snapshot is missing or incomplete (`SNAPSHOT_REQUIRED`, `SNAPSHOT_INCOMPLETE`).
- Create-order extracts monetary values from the provided snapshot (`subtotal`, `tax_amount`, `tax_rate`, `delivery_fee`, `software_fee`, `tip_amount`, `discount_amount`, `total`) and persists them in `knx_orders` columns plus a serialized `totals_snapshot` (named `breakdown_v5` / `KNX_ORDER_SNAPSHOT_VERSION`).
- Create-order constructs and writes an immutable `cart_snapshot` and `totals_snapshot` within an atomic DB transaction and marks the originating cart as `converted`.

**Snapshot Immutability**

- The persisted totals and snapshots are immutable after creation. Create-order sets `'is_snapshot_locked' => true` in the stored snapshot and writes `totals_snapshot` and `cart_snapshot` as the canonical, persisted records.
- The create-order code contains explicit gates that forbid recalculation during order creation (comments and enforcement: do NOT call coverage/distance/fee engines during create; rely on sealed delivery snapshot produced by quote).

**Order Status Model**

- Status is the only allowed mutable field post-creation. The status update endpoint (`api-update-order-status.php`) performs controlled, append-only transitions and writes an entry to the status history table in the same DB transaction.
- The status update path enforces a transition matrix (e.g., `pending -> confirmed|cancelled`, `confirmed -> preparing|cancelled`, `preparing -> ready|cancelled`, `ready -> out_for_delivery|completed`, `out_for_delivery -> completed`) and treats `completed` and `cancelled` as terminal states.

**Role-Based Control**

- Session and role resolution for access control and authorization rely on helpers in `inc/functions/helpers.php` (e.g., `knx_get_session()`, `knx_require_role()` and related permission callbacks used by the REST endpoints).
- Read/list endpoints (`api-get-order.php`, `api-list-orders.php`) enforce ownership and role-based scoping: `customer` and `guest` access is restricted to user-owned or session-owned orders (return 404 when the order exists but is not accessible to that session to avoid enumeration); `manager` and `super_admin` have elevated scopes (manager is currently city/hub-scoped in code comments and pragmatic checks).
- Status updates are allowed only for `super_admin` and `manager` roles; customers, guests, and drivers are forbidden from invoking status transitions.

**Fail-Closed vs Permissive Paths**

- The order creation and quote flows adopt fail-closed behavior for critical checks: missing engines, missing snapshot fields, snapshot mismatches, and negative taxes produce error responses (409 or 503 as implemented). Create-order rejects inconsistent snapshots (e.g., subtotal mismatch with cart items, delivery snapshot mismatch).
- Read/list endpoints are read-only and do not perform totals recalculation; they operate on persisted DB fields.

**Cross-City / Cross-Hub Behavior**

- Manager scoping is implemented as city/hub-scoped in policy and comments. In practice, the list and get endpoints perform hub lookup checks; manager scoping is documented by comments and enforced conservatively where implemented, with some code paths using best-effort hub validation.
- Orders persist `hub_id` and `city_id` (via `hub.city_id` at creation) and status updates validate hub existence when performed by manager roles.

**Known Limits & Dependencies**

- Orders Phase 1 depends on `inc/functions/helpers.php` for session resolution and role enforcement. That helpers file is previously classified as NOT SAFE / CANONICAL WITH LIMITS; Orders Phase 1 relies on it for all session-based access control and permission callbacks used by the REST routes.
- Because helpers.php is CANONICAL WITH LIMITS / NOT SAFE, Orders Phase 1 cannot be fully sealed solely by its internal order logic; its effective security and role isolation inherit limitations from the helpers authority surface.
- The totals and delivery authority depend on auxiliary engines invoked by the quote endpoint (coverage, distance, delivery fee, tax, coupons). Quote fails closed when required engines or fields are missing.

**Canonical Status**

- Status: NEAR-CLOSED

- Why it is not CLOSED: Orders Phase 1 depends on the session and role authority implemented in `inc/functions/helpers.php`, which is classified as NOT SAFE / CANONICAL WITH LIMITS. This external authority surface introduces an unresolved trust boundary; because session and role resolution are necessary for access control and idempotency checks, Orders cannot be declared fully CLOSED.

- Why it is not NOT CLOSED: The Orders Phase 1 codebase enforces server-authoritative quote calculation, refuses recalculation at create, persists immutable snapshots within atomic transactions, restricts post-create mutations to status transitions with transactionally appended history, and uses idempotency guards. These behaviors demonstrate that, within the application code, Orders act as a server-authoritative, snapshot-locked system for Phase 1 functionality.

**Justification**

- Quotes are server-authoritative: `api-quote-totals.php` computes all monetary values and returns a sealed `order_snapshot` and `delivery_snapshot_v46` intended for create-order.
- Create-order refuses recalculation: `api-create-order-mvp.php` requires a snapshot, enforces sealed delivery snapshot fields, explicitly documents and enforces that it does not call coverage/distance/fee engines during creation, and rejects snapshots that are incomplete or inconsistent.
- Snapshot is immutable after creation: create-order persists `totals_snapshot` and `cart_snapshot` and sets `'is_snapshot_locked' => true` in the stored snapshot (`breakdown_v5` / `KNX_ORDER_SNAPSHOT_VERSION`).
- Status updates are the only allowed mutation: `api-update-order-status.php` is the only endpoint that writes post-create state (order.status + status history). It enforces role restrictions and a transition matrix and performs updates inside a DB transaction.
- No endpoint recalculates totals after creation: Read/list endpoints return DB-stored totals and snapshots; status update endpoint updates only `status` and `status_history`; there is no code path in the audited files that recalculates or overwrites persisted totals/snapshots for existing orders.
- Frontend is not authoritative: The system requires a server-produced `order_snapshot` (quote) and enforces its use at create-order; frontend-supplied totals are not trusted by create-order and are rejected when inconsistent.
- Dependency on helpers for session and role enforcement: All REST routes listed use the permission callbacks and session resolution that depend on `inc/functions/helpers.php` (previously classified as NOT SAFE / CANONICAL WITH LIMITS), creating the trust dependency noted in Canonical Status.
