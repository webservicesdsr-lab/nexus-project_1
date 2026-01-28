# Kingdom Nexus — Authority Map

Status: ACTIVE  
Type: Foundational System Contract  
Scope: Backend, REST, Session, Roles, Orders, Ops

---

## 1. Purpose of This Document

This document defines the canonical authority assignments across Kingdom Nexus domains. It is a contract-style, evidence-based map of who (which role or subsystem) holds final decision authority for each domain listed below. This is not a functional how-to nor a technical design specification; it records observed behavior and enforcement locations found in the audited codebase.

---

## 2. Authority Levels

- SEALED AUTHORITY
  - Definition: Actions or decisions that are enforced exclusively by route registration and/or handler checks for a single role; callers without that role are blocked at the route or handler level.
- CANONICAL WITH LIMITS
  - Definition: A canonical authority exists, but implementation contains limits or conditional scoping; code may implement both canonical and constrained paths.
- OPERATIONAL AUTHORITY
  - Definition: Runtime actors (managers/drivers) who perform day-to-day operations subject to server-side checks when present.
- DERIVED / DEPENDENT
  - Definition: Decisions computed from other authoritative domains (e.g., totals derived from cart + delivery rates).
- TEMPORARY / LEGACY
  - Definition: Code paths or behaviors marked in-source as temporary or legacy and not representative of intended final scoping.

---

## 3. Global Principles

- Single Source of Truth — Observed behavior: Backend handlers and database are used as authoritative data sources (multiple handlers read/write DB tables such as `knx_hubs`, `knx_orders`, `knx_drivers`).  (See: `inc/core/resources/*`, `inc/core/knx-orders/*`.)
- Fail-Closed — Observed behavior: Route permission callbacks and handler-level role checks return errors or block requests when permission/nonce/session is absent (examples across `inc/core/resources/*`).
- Orders as Immutable Snapshots — Observed behavior: Order creation handlers insert order rows and later status changes occur via controlled handlers (see `inc/core/knx-orders/api-create-order-mvp.php`, `inc/core/knx-orders/api-update-order-status.php`).
- WP as Container Only — Observed behavior: The plugin registers REST routes and uses WordPress as the container for REST dispatch; the plugin enforces domain rules inside its handlers (see `inc/core/rest/*` and `inc/core/resources/*`).
- No Deep-Linking to Checkout — Observed behavior: Checkout and order creation flows are guarded by session checks and nonces in server handlers (see order handlers under `inc/core/knx-orders`).
- Backend Is Authority — Observed behavior: Client UIs call REST endpoints but server handlers perform final validation/authorization (see shortcodes in `inc/modules/*` wiring to `inc/core/resources/*`).

---

## 4. Domain Authority Map

### 4.1 Authentication & Session
- Authority level: SEALED AUTHORITY (backend session enforcement)  
- Canonical files:
  - `inc/functions/helpers.php` (session helper references observed in multiple handlers)
  - `inc/core/rest/knx-rest-guard.php` (route guard utilities; route permission callbacks used across REST registrations)
  - Example consumers: `inc/core/knx-orders/api-create-order-mvp.php`, `inc/core/knx-orders/api-get-order.php` (handlers require valid session)
- What it decides:
  - Observed behavior: Validates session presence and association to user; gates access to order creation, order retrieval, and admin write actions.
  - Enforced at handler level: handlers call session/role requirement helpers (e.g., `knx_rest_require_session()` / `knx_rest_require_role()` patterns observed).
- What it does NOT decide:
  - Observed behavior: It does not directly decide hub ownership or driver assignments; those are separate domain decisions verified in ops/hubs handlers.
- Known limits:
  - Observed behavior: Some endpoints return `404 'order-not-found'` to hide existence for unauthorized requests rather than exposing auth failure details (see `inc/core/knx-orders/api-update-order-status.php`).

### 4.2 Roles & Capabilities
- Authority level: CANONICAL WITH LIMITS  
- Canonical files:
  - `inc/functions/navigation-engine.php` (declares nav items and role visibility)
  - REST registrations in `inc/core/resources/*` files (permission callbacks using `knx_rest_permission_roles([...])`)
  - Examples: `inc/core/resources/knx-cities/*`, `inc/core/resources/knx-ops/api-ops-orders.php`, `inc/core/resources/knx-hubs/*`, `inc/core/resources/knx-drivers/api-drivers-crud.php`
- What it decides:
  - Observed behavior: Route-level guards and handler-level checks gate actions to roles `super_admin`, `manager`, `driver`, `customer`, `guest` as registered in permission callbacks and handler code.
  - Enforced at route-level: Some endpoints are registered `super_admin` only (sealed). Example registrations: `POST /knx/v2/cities/add`, `POST /knx/v2/cities/delete`, `POST /knx/v2/ops/orders/force-status` (see `inc/core/resources/knx-cities/*`, `inc/core/resources/knx-ops/api-ops-orders.php`).
- What it does NOT decide:
  - Observed behavior: Route-level role inclusion does not always imply handler-level owner-scoping; many endpoints include `manager` in permission arrays but handler code does not enforce manager ownership.
- Known limits:
  - Observed behavior: The codebase contains temporary/legacy paths where manager scoping is not applied (see `TEMPORARY` comments in `inc/core/resources/knx-ops/api-ops-orders.php` and related orders handlers).

### 4.3 Cart & Cart Token
- Authority level: OPERATIONAL AUTHORITY (session-bound cart)  
- Canonical files:
  - `inc/functions/*` (cart helpers referenced in project functions folder)
  - Cart resource files under `inc/core/knx-orders/` and `inc/modules/cart/` (cart behavior observed across these handlers/modules)
- What it decides:
  - Observed behavior: Cart token and session binding are used to track items and prepare order creation; order creation handlers rely on session/cart state (see `inc/core/knx-orders/api-create-order-mvp.php`).
- What it does NOT decide:
  - Observed behavior: Cart contents do not decide delivery rates or final totals — those are computed by totals/delivery rate domains.
- Known limits:
  - Observed behavior: Guest cart behavior exists but mutating actions require session/nonce; exact guest token semantics are implemented in session helpers and cart modules (no single-file canonicalization observed during audit).

### 4.4 Checkout & Prevalidation
- Authority level: SEALED AUTHORITY (server-side validation)  
- Canonical files:
  - `inc/core/knx-orders/api-quote-totals.php` (totals/quote computation endpoint)
  - `inc/core/knx-orders/api-create-order-mvp.php` (order creation handler)
- What it decides:
  - Observed behavior: Validates cart, computes totals/fees via totals engine, enforces session and nonce before inserting an order row.
  - Enforced at handler level: create-order handler applies server-side validation prior to DB insert.
- What it does NOT decide:
  - Observed behavior: Client-side form fields or URL params do not have final authority; server handlers perform canonical validation.
- Known limits:
  - Observed behavior: Computation of totals depends on delivery-rate data which is sealed under `super_admin` for city-level delivery rates (see `inc/core/resources/knx-cities/*-delivery-rates.php`).

### 4.5 Orders
- Authority level: CANONICAL WITH LIMITS  
- Canonical files:
  - `inc/core/knx-orders/api-create-order-mvp.php` (create)
  - `inc/core/knx-orders/api-list-orders.php`, `inc/core/resources/knx-ops/api-ops-orders.php` (list, ops pipeline)
  - `inc/core/knx-orders/api-get-order.php`, `inc/core/knx-orders/api-update-order-status.php` (get, update status)
- What it decides:
  - Observed behavior: Order creation inserts canonical order rows; status changes are applied via controlled handlers.
  - Enforced at handler level: Handlers check session and role; non-admin roles may receive `404 'order-not-found'` to avoid revealing existence.
- What it does NOT decide:
  - Observed behavior: Client/UI presentation is not authoritative; server enforces canonical order state.
- Known limits:
  - Observed behavior: Manager-scoping for order lists is incomplete in places; some order-list handlers contain `TEMPORARY` comments allowing all hubs until scoping is implemented (`inc/core/resources/knx-ops/api-ops-orders.php`).

### 4.6 Coverage & Location
- Authority level: DERIVED / DEPENDENT  
- Canonical files:
  - `inc/functions/coverage-engine.php`, `inc/functions/geo-engine.php`, `inc/functions/distance-calculator.php` (coverage and geo logic)
  - Consumers: handlers that compute coverage/use location data in `inc/core/resources/*` and `inc/modules/*`
- What it decides:
  - Observed behavior: Coverage and geography-related decisions are derived from engine computations and applied during quote/totals and hub selection.
  - Enforced at handler level: Handlers call coverage/geo functions and apply outputs to eligibility logic.
- What it does NOT decide:
  - Observed behavior: Coverage engines do not unilaterally change order status or roles; they supply inputs to totals and eligibility decisions.
- Known limits:
  - Observed behavior: Fail-open/closed behavior depends on handler usage; handlers may choose to block or fallback based on the engine outputs.

### 4.7 Totals & Fees
- Authority level: DERIVED / DEPENDENT  
- Canonical files:
  - `inc/functions/totals-engine.php`, `inc/functions/delivery-fee-engine.php`, `inc/functions/taxes-engine.php` (computation engines)
  - Consumers: `inc/core/knx-orders/api-quote-totals.php`, order creation handler
- What it decides:
  - Observed behavior: Totals and fees are computed server-side by the totals engine and applied to quotes and orders during checkout/prevalidation.
  - Enforced at handler level: Quote and create-order endpoints call these engines to compute canonical totals.
- What it does NOT decide:
  - Observed behavior: Client-side display of totals is not authoritative without server confirmation.
- Known limits:
  - Observed behavior: Delivery rates per city are sealed under `super_admin` endpoints (`inc/core/resources/knx-cities/*-delivery-rates.php`), which are inputs to totals computations.

### 4.8 Ops / Dispatch / Drivers
- Authority level: CANONICAL WITH LIMITS  
- Canonical files:
  - `inc/core/resources/knx-ops/api-ops-orders.php` (ops pipeline, assign/unassign/cancel, force-status)
  - `inc/core/resources/knx-ops/api-ops-orders-live.php` (live proxy)
  - `inc/core/resources/knx-drivers/api-drivers-crud.php` (drivers CRUD)
  - UI clients: legacy OPS UI has been removed; drivers admin UI remains under `inc/modules/drivers`.
- What it decides:
  - Observed behavior: OPS handlers execute assignments/unassignments and status changes; `super_admin` path is implemented as global (allowed hubs = all hubs). Assignment logic attempts manager scoping for manager branch.
  - Enforced at handler level: `force-status` is registered and enforced as `super_admin` only (`inc/core/resources/knx-ops/api-ops-orders.php`).
- What it does NOT decide:
  - Observed behavior: Driver availability scheduling and some client-visible state are maintained elsewhere (driver availability tables and helper functions). Drivers themselves do not change order canonical state except via OPS flows.
- Known limits:
  - Observed behavior: Some order list queries used by OPS are global by default; code contains `TEMPORARY` allowances for manager access to all hubs.

### 4.9 Cities & Hubs
- Authority level: CANONICAL WITH LIMITS  
- Canonical files:
  - `inc/core/resources/knx-cities/get-cities.php`, `inc/core/resources/knx-cities/post-operational-toggle.php` (city get / operational toggle)
  - `inc/core/resources/knx-cities/add-city.php`, `inc/core/resources/knx-cities/delete-city.php` (add/delete — sealed `super_admin`)
  - `inc/core/resources/knx-hubs/api-hubs-core.php`, `inc/core/resources/knx-hubs/api-delete-hub.php`, `inc/core/resources/knx-hubs/api-update-hub-identity.php` (hubs CRUD/delete)
  - UI clients: `inc/modules/knx-cities/knx-cities-shortcode.php`, `inc/modules/hubs/hubs-shortcode.php`, `inc/modules/hubs/edit-hub-template.php`
- What it decides:
  - Observed behavior: City-level sealed actions (add/delete/delivery-rates) are `super_admin` only. City GET and operational toggle include manager scoping when `knx_hubs.manager_user_id` column exists (handler-level enforcement in `post-operational-toggle.php`).
  - Hubs CRUD: handlers accept `super_admin` and `manager` in permission arrays; handler-level owner checks for manager are inconsistent (deletes/updates may proceed without verifying manager ownership).
- What it does NOT decide:
  - Observed behavior: Client-side listing of hubs or cities is not authoritative; server handlers perform final checks.
- Known limits:
  - Observed behavior: Where the DB has not been migrated with `manager_user_id`, manager scoping returns 403 in cities endpoints indicating configuration dependency.

---

## 5. Cross-Domain Authority Rules

- When domains disagree, the handler that performs the final write into the database is the final authority for that transaction.  
  - Observed behavior: Server handlers compute totals/coverage and then insert/update DB rows during order creation and hub/driver mutations (see `inc/core/knx-orders/*`, `inc/core/resources/knx-hubs/*`).
- Route-level guards determine whether a caller is permitted to execute a handler; handler-level checks may further constrain or allow actions.  
  - Observed behavior: Some permissions are enforced at registration (route-level `knx_rest_permission_roles([...])`), and some checks are enforced in handler code (`knx_rest_require_role(...)`, DB checks for manager hub ownership).
- If role-level permissions and domain-derived checks conflict, the handler-level enforcement observed in code is the decisive enforcement for that request processing.

---

## 6. Explicit Non-Authorities

 - Frontend JS — Observed behavior: Client scripts call REST endpoints but do not decide canonical state. Legacy OPS UI files have been removed.
- URL params — Observed behavior: Query and path parameters are accepted by handlers but validated/enforced server-side.
- Client-side state — Observed behavior: UI state is not authoritative; server handlers perform final validation and persistence.
- UI navigation — Observed behavior: Navigation visibility is declared in `inc/functions/navigation-engine.php` but does not grant server-side permission unless backed by route-level or handler-level checks.

---

## 7. Known Temporary Exceptions

- Orders listing and manager scoping: Observed behavior: Order-list handlers contain `TEMPORARY` comments such as `"TEMPORARY: Allow all hubs until city-scoping is fully implemented"` in `inc/core/resources/knx-ops/api-ops-orders.php` and related files. This is an observed temporary exception where manager-visible UI may show global orders.
- Manager assignment and hub ownership: Observed behavior: Some hub delete/update handlers accept manager in permission arrays but do not enforce manager ownership in handler body (see `inc/core/resources/knx-hubs/api-delete-hub.php`).

---

## 8. Document Governance

- This file (`/documentation/AUTHORITY_MAP.md`) is the primary authority reference for role and domain authority assignments inside the repository.  
- Observed requirement: Other documentation files in `documentation/` created from this audit must reference this Authority Map as canonical.
- Change control: Changes to this document must be accompanied by code-level evidence and a fresh audit; handlers and route registrations are the evidence sources (files under `inc/core/resources/*`, `inc/core/knx-orders/*`, `inc/functions/*`).

---

Evidence notes (VERY IMPORTANT)

Each domain section above references canonical files and observed behaviors. Statements use the following language where appropriate: "Observed behavior", "Enforced at handler level", "Route-level guard". No behavior is asserted without a referenced file or handler that demonstrates it.

This document is the primary authority reference. All other documentation (roles, orders, ops, etc.) must align with this map.
