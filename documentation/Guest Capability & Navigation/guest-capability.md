# Guest — Capability (EN)

Purpose
- Role: unauthenticated visitor (Guest). This document records, from audited code evidence, what unauthenticated users may view or do.

What guests can view (evidence-only)
- Public hubs list: `inc/core/resources/knx-hubs/api-hubs-core.php` exposes a public `GET` route that returns active hubs (server-side filters by `status = 'active'`). This feeds public shortcodes and pages in `public/`.
- Public pages & shortcodes: the repository contains `public/` shortcodes and templates (e.g., `public/home`, `public/explore-hubs`, `public/menu`, etc.) used to render browsing interfaces for unauthenticated visitors.

What guests cannot do (evidence-only)
- Mutations and admin actions require authenticated sessions and appropriate roles; many write endpoints require `knx_nonce` and `knx_rest_permission_roles(...)` checks. Order creation and cart actions often require a session.

VERDICT (evidence-only)
- Guests can browse public content (active hubs, public pages). Mutating actions require session authentication and appropriate roles.
