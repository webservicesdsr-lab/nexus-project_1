
# Kingdom Nexus – Engineering Guidelines (NEXUS-GUIDELINES)

> This file is the **canonical engineering guide** for the Kingdom Nexus / Our Local Collective plugin.
> Any AI assistant (Copilot, ChatGPT, etc.) MUST follow these rules when editing this codebase.

---

## 0. How assistants must use this file

1. **Always read and respect these rules** before changing any file.
2. **Never rewrite a whole file** unless the user explicitly says something like:
   - “full rewrite is ok”
   - “replace the entire file”
3. Prefer **small, localized edits**:
   - Modify only the function, block, or section the user is talking about.
   - If a file is already large, propose extracting helpers or modules instead of adding more noise.
4. When in doubt, **ask before creating new files or new folders** that change the architecture.

---

## 1. Project architecture (Kingdom Nexus)

High-level structure (WordPress plugin):

- Root file: `kingdom-nexus.php` (bootstrap, defs, hooks).
- `inc/core/` → REST APIs, core services, security, helpers.
- `inc/modules/` → UI modules, CRUD screens, shortcodes.
- `inc/functions/` → generic helpers, utilities, small shared functions.
- `uploads/` (outside plugin if needed) → media / temp storage (created automatically if missing).

### Rules

- **APIs** live in `inc/core/` and expose routes under `/wp-json/knx/v1/...`.
- **UI / dashboards / shortcodes** live in `inc/modules/`.
- Shared logic must NEVER be duplicated. Prefer:
  - `require_once` of a small helper in `inc/functions/` or
  - a single reusable function in `inc/core/`.

---

## 2. File size & modularity

To keep the project maintainable:

1. **Target file size**
   - Ideal: **< 400 lines** per file.
   - Hard limit: avoid going over **700–800 lines**. If necessary, propose splitting.
2. If a file is growing too much:
   - Extract reusable pieces to:
     - `inc/functions/{something}-helpers.php` for generic logic.
     - `inc/core/api-{area}.php` for related endpoints.
     - `inc/modules/{area}/{component}.php` for new UI components.
3. **Never dump big CSS/JS/HTML blocks** inline into PHP unless explicitly requested.
   - Prefer separate `.css` / `.js` files already used by the project.

---

## 3. Update strategy (VERY IMPORTANT)

When the user asks for a change:

1. **Local changes by default**  
   Edit only the specific function / block mentioned. Avoid touching unrelated areas.
2. **Preserve existing behavior**  
   If refactoring, behavior must stay identical unless the user clearly wants a change.
3. **Backward compatibility**
   - Do not rename tables, routes, or option keys unless the user explicitly approves a migration.
   - When adjusting SQL, always consider dynamic table prefixes (`$wpdb->prefix`).
4. **No experimental debug code**
   - No `var_dump`, `print_r`, `die`, `exit` in production code.
   - No random `console.log` left in JS for production modules.
   - No `WP_DEBUG` toggling from code.

---

## 4. Coding style – Global rules

1. **Comments language**
   - All block comments (`/** ... */`) MUST be **in English**.
2. **Naming**
   - PHP functions: `knx_{area}_{action}` (e.g., `knx_hubs_get_list`).
   - REST routes: `/wp-json/knx/v1/{area}-{action}`.
   - JS: camelCase for functions and variables.
3. **Security**
   - Always escape output with `esc_html`, `esc_attr`, `esc_url` etc.
   - Use nonces and capability checks for POST / mutation endpoints.
   - Never trust `$_GET` / `$_POST` directly, always sanitize.

---

## 5. Frontend: HTML & CSS

### General

- **Mobile first**. Layout must work perfectly on small screens before desktop.
- Avoid utility frameworks (e.g. Tailwind) inside Nexus unless explicitly authorized.
- Respect existing scoping:
  - Example: Explore Hubs page is scoped to `#olc-explore-hubs`.

### HTML rules

- Use **semantic structure** (`<main>`, `<section>`, `<header>`, `<footer>`) when possible.
- For cards / components, keep class naming consistent:
  - Example: `hub-card`, `hub-img`, `hub-bottom`, `vend-grid`, etc.
- Don’t over-nest elements. Depth > 5 levels is a smell.

### CSS rules

- Each major UI module has its own CSS file (e.g., `explore-hubs.css`) and is **scoped**:
  - `#olc-explore-hubs .hub-card { ... }`
- Avoid inline styles except for minimal dynamic tweaks; prefer CSS rules in the file.
- Use the existing **color system** for Our Local Collective:
  - `--olc-green: #0b793a;`
  - `--olc-amber: #f39b1f;`
  - And related tokens already present in `explore-hubs.css`.
- Transitions should be subtle and performant (no heavy box-shadows on every element).

---

## 6. JavaScript guidelines

1. **Vanilla JS only** (no React, no jQuery, unless explicitly allowed).
2. Use the pattern:

   ```js
   document.addEventListener('DOMContentLoaded', function () {
     'use strict';
     const root = document.querySelector('#some-scope');
     if (!root) return;

     // State, DOM hooks, helpers, init()
   });
   ```

3. Scope all selectors to the main root when possible (e.g., `root.querySelector(...)`).
4. Use **small pure helper functions** for:
   - escaping (`esc`)
   - filters (`matchesQuery`, `matchesCategory`, etc.)
   - modular pieces like `renderVendors`, `renderSpotlights`, `showTempClosedModal`.
5. For new modals or UI patterns:
   - Reuse existing patterns from Nexus (e.g., overlay + backdrop + ESC key to close).
   - Avoid global variables; if a global is necessary (like `window.openSurpriseModal`), document it clearly.

---

## 7. PHP / WordPress specifics

1. **Shortcodes**
   - Live under `inc/modules/{area}/`.
   - Each shortcode function:
     - Validates user/session if needed.
     - Uses `ob_start()` + `ob_get_clean()` to return HTML.
   - The HTML structure should be clean and minimal; heavy logic must be in JS or helpers.
2. **REST APIs**
   - Register inside `add_action('rest_api_init', ...)`.
   - Route pattern: `/knx/v1/{area}-{action}`.
   - Always:
     - Validate permissions.
     - Validate and sanitize request params.
     - Return `WP_REST_Response` or `wp_send_json` style payloads with clear `success`, `data`, `message`.

---

## 8. Logging, errors & UX

1. Errors should be **user-friendly** in the UI:
   - Cards like “Unable to load vendors. Please try again later.”
2. Internal errors:
   - Logged via WordPress tools if needed, not dumped to the browser.
3. No “raw” error messages from SQL or PHP should be exposed to end users.

---

## 9. Performance expectations

1. Use **debounce/throttle** for high-frequency events like search inputs or scroll listeners.
2. Paginate long lists in admin dashboards; do not load hundreds of rows at once if avoidable.
3. For public pages:
   - Keep DOM light.
   - Avoid unnecessary re-renders.
   - Fetch only what is needed (e.g., separated endpoints for featured vs full list if required).

---

## 10. Good vs bad assistant behavior

### ✅ Good

- “Add a new ‘Open now’ filter toggle to the Explore Hubs page”  
  → Only touch `explore-hubs.js` and `explore-hubs.css`, and only in the relevant small sections.

- “Fix the temp closed countdown format”  
  → Only adjust `showTempClosedModal(...)`, without touching unrelated functions.

- “Improve the Surprise Me modal visuals”  
  → Reuse existing HTML structure and classes, tweak CSS, avoid renaming IDs.

### ❌ Bad

- Rewriting the entire `explore-hubs.js` when the user only asked to change one function.
- Adding new global CSS classes at `body` level that can clash with other modules.
- Injecting inline `<script>` or `<style>` tags directly inside shortcode PHP without a clear reason.

---

## 11. When large changes are REALLY needed

If a file is already messy and the user explicitly approves a refactor, assistants should:

1. Propose a **short plan**:
   - What to extract.
   - New file names and locations.
2. Keep behavior identical unless the user wants feature changes.
3. Split work into **small, reviewable steps**, not a single 1,000-line diff.

---
