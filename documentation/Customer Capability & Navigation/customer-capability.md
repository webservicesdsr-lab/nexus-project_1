# Customer — Capability (EN)

Purpose
- Role: `customer` — authenticated shopper who can create and manage their own orders and profile. This document records, from audited code evidence, customer-facing capabilities and constraints.

What `customer` can do (evidence-only)
- Create orders: `inc/core/knx-orders/api-create-order-mvp.php` — handlers exist for creating orders (evidence of order creation endpoints accessible to authenticated sessions).
- Quotes and totals: `inc/core/knx-orders/api-quote-totals.php` — endpoints to compute quote/totals exist and are part of the order creation flow.
- View own orders: `inc/core/knx-orders/api-get-order.php` and `inc/core/knx-orders/api-list-orders.php` — handlers check session and role; customers can retrieve orders associated with their session/user.

What `customer` cannot access (evidence-only)
- Customers do not receive administrative capabilities; server-side handlers for admin operations require admin roles or session permissions (see `knx_rest_permission_roles(...)` usage in admin endpoints). Admin-level endpoints are registered separately and include `manager` and/or `super_admin`.

Notes on scoping and privacy (evidence-only)
- Order handlers use session and role checks; non-admin roles are treated differently in code paths (some handlers return `404 'order-not-found'` for unauthorized viewers to avoid leaking existence).

VERDICT (evidence-only)
- Customers can create and manage their own orders and see own order data; admin-level actions are available only to admin roles per route registration and handler checks.
