# KNX Production Audit — Auth Continuation, Login/Register, Guest Cart, and Redirect Policy

**Date:** April 3, 2026  
**Scope:** All auth, cart, redirect, and navigation flows across PHP backend, REST API, and JS frontend  
**Severity model:** Critical / Medium / Low  

---

## 1. Executive Summary

The Kingdom Nexus auth/cart/continuation system is **fragmented, context-destructive, and not production-safe** in its current form.

The system has **no unified continuation policy**. Post-auth destination decisions are scattered across at least **five independent authorities** that do not communicate with each other. The most dangerous finding is that the `redirect_to` query parameter is **generated but never consumed** — the auth-redirects system appends `?redirect_to=` when sending guests to `/login` from protected pages, but the login handler (`auth-handler.php`) **completely ignores it**, always hardcoding the destination based on role. For customers, this means **every successful login goes to `/`** (home), unconditionally destroying whatever the user was trying to do.

Guest cart visibility is **gated behind auth** in the primary navbar (`navbar.php`). The cart toggle button, cart drawer, cart badge, and sidebar are all rendered only when `$context['is_logged']` is true. This means guests **cannot see, open, or interact with the cart drawer** from the navbar, even though the backend cart system, localStorage cart, and menu "add to cart" logic all work perfectly for guests. The cart *page* (`/cart`) is accessible to guests, but guests have no discoverable way to reach it because the navbar hides the entry point.

The cart data itself is **not lost** during auth transitions — `knx_auto_login_user_by_id()` correctly claims the guest cart by linking `customer_id` to the active cart matched by `session_token`. However, the **frontend state** (localStorage cart, badge, drawer) is never refreshed after a server-side auth transition. The page simply redirects via PHP `wp_safe_redirect`, and whatever page the user lands on will read `knx_cart` from localStorage — which may be stale or correct depending on timing.

**Overall theme:** The system was built with role-based routing as the primary concern, not intent-preservation. Authentication works. Cart data works. But the navigation layer between them is broken — it destroys context, hides shopping tools from guests, and ignores every signal about what the user was trying to accomplish.

**Production risk level: HIGH** — Multiple critical issues that will directly impact conversion, user trust, and shopping continuity.

---

## 2. Intent Model Audit

### A. Browsing Intent

**Sane continuation:** User remains on the same page or continues browsing. Auth should be invisible unless needed.

**Current implementation:** Mostly correct for guests. Guests can browse `/explore-hubs`, view menus at `/menu/{slug}`, and add items to cart (localStorage + DB sync). However, if a guest clicks "Login" from the navbar while browsing, they are sent to `/login` and after successful auth, **always redirected to `/`** (home) — destroying their browsing position entirely.

**Mismatch:** Post-auth destroys browsing context for customers. Navbar login link is a hard `<a href="/login">` with no return URL.

### B. Cart Intent

**Sane continuation:** User returns to cart or continues shopping. Cart state is preserved.

**Current implementation:** Cart data is preserved across auth (backend `knx_auto_login_user_by_id` claims the cart). But the guest **cannot see the cart toggle/drawer** from the navbar — it's rendered only for logged users. The cart *page* (`/cart`) is accessible to guests and works correctly, showing items from the DB via `session_token`. The cart page CTA for guests says "Login to checkout" and links to `/login` — but after login, the customer is sent to `/` (home), not back to `/cart`.

**Mismatch:** Critical. Cart page's "Login to checkout" link does not encode a return URL. Post-login always goes to home. Guest's purchase momentum is shattered.

### C. Checkout Intent

**Sane continuation:** After auth, user should arrive at checkout or the cart page (nearest valid transactional step).

**Current implementation:** `checkout-shortcode.php` has a proper login guard that shows "Please login to continue" with a link to `/login`. But again, no `redirect_to` is passed. After login, user goes to home. The guest would have to manually navigate back to `/cart`, then click "Proceed to checkout" again.

**Mismatch:** Critical. Checkout intent is completely destroyed by the home redirect.

### D. Protected Account Intent

**Sane continuation:** After auth, user arrives at the protected page they originally requested.

**Current implementation:** `auth-redirects.php` correctly generates `?redirect_to=<full_url>` when redirecting guests from restricted pages to `/login`. **But `auth-handler.php` never reads `$_GET['redirect_to']` or `$_POST['redirect_to']`.** The parameter is generated and then thrown away. The user always goes to `/` for customers.

**Mismatch:** Critical. This is a complete broken link in the redirect chain. The protected-route-return mechanism exists at the entry point but is severed at the exit point.

### E. Voluntary Authentication Intent

**Sane continuation:** User remains on the same page, or goes to their account landing.

**Current implementation:** The navbar login link is `<a href="/login">` with no context. After login, customer goes to `/`. This is the home-first fallback, which is acceptable as a *fallback* but is applied universally without distinguishing from other intents.

**Mismatch:** Low-medium. Home is defensible for voluntary login, but the same destination is used for *all* login contexts, which is wrong for B/C/D above.

### F. Recovery / Auxiliary Intent

**Sane continuation:** After password reset or email verification, user arrives at login to re-enter credentials.

**Current implementation:** Correctly handled. Email verification, password reset, and forgot-password flows all redirect to `/login` with appropriate toast messages.

**Mismatch:** None. This is the one area that works correctly.

---

## 3. Current Flow Map

### Guest / Shopping / Auth Scenarios

| # | Scenario | Starting State | User Action | Current Behavior | Redirect/Continuation | Cart State | Expected Behavior | Mismatch | Severity |
|---|----------|---------------|-------------|-----------------|----------------------|------------|-------------------|----------|----------|
| 1 | Guest opens site | No session | Visit `/` | Home page renders | N/A | Empty localStorage | ✅ Correct | None | — |
| 2 | Guest browses stores | No session | Visit `/explore-hubs` | Hub list renders | N/A | Empty | ✅ Correct | None | — |
| 3 | Guest adds first item | On menu page | Click add-to-cart | Item saved to localStorage + DB sync | N/A | 1 item in LS + DB | ✅ Correct (cart data) | Badge invisible (cart toggle hidden for guest) | **Medium** |
| 4 | Guest adds multiple items | On menu page | Multiple adds | Items accumulate in localStorage + DB | N/A | N items | ✅ Data correct | Badge invisible | **Medium** |
| 5 | Guest opens cart drawer | Any page | Click cart toggle | **Cart toggle does not exist for guests** — not rendered in `navbar.php` | N/A | Cart data intact but invisible | Guest should see and open cart drawer | **Critical** |
| 6 | Guest opens cart page | Any page | Navigate to `/cart` | Cart page renders with items from DB (via `session_token` cookie) | N/A | Visible on page | ✅ Correct (but guest can't easily discover this page) | **Medium** |
| 7 | Guest edits quantities | `/cart` page | Attempt edit | **Cart page is read-only** (PHP-rendered, no edit controls in shortcode). Cart drawer has edit controls but is hidden for guests. | N/A | No client-side edit on cart page | Guest should be able to edit from cart page or drawer | **Medium** |
| 8 | Guest removes items | `/cart` page | Attempt remove | **No remove controls on cart page** (shortcode renders static HTML) | N/A | Items persist | Same as above | **Medium** |
| 9 | Guest attempts checkout | `/cart` page | Click CTA | CTA says "Login to checkout" linking to `/login` (no `redirect_to`) | Sent to `/login` | Cart intact in DB | Should link to `/login?redirect_to=/cart` or `/checkout` | **Critical** |
| 10 | Guest blocked from checkout | `/checkout` directly | Visit URL | Checkout shortcode shows "Please login to continue" with link to `/login` | N/A | Cart intact | Should preserve checkout intent via `redirect_to` | **Critical** |
| 11 | Guest clicks login from cart context | `/cart` page | Click "Login to checkout" | Goes to `/login` (no return context) | `/login` | Cart intact | Should carry `/cart` as return URL | **Critical** |
| 12 | Guest clicks register from cart context | `/login` page, switch to register | Register | After register → `/login` (toast: verify email). Even if no-verify, goes to `/login` | `/login` | Cart intact | Should preserve cart return context through register | **Critical** |
| 13 | Guest clicks login from navbar (has cart) | Any page | Click navbar "Login" | Goes to `/login`. After success → `/` (home) | Home page | Cart data intact in DB but user loses position | Should return to same page | **Critical** |
| 14 | Guest clicks register from navbar (has cart) | `/login` page | Switch to register, submit | Register → `/login` → login → `/` | Home page | Cart data intact | Same as 13 | **Critical** |
| 15 | Guest clicks login from navbar (empty cart) | Any page | Click "Login" | → `/login` → login → `/` (home) | Home page | Empty | Acceptable as fallback | **Low** |
| 16 | Guest clicks register from navbar (empty cart) | `/login` | Register | → `/login` → login → `/` | Home page | Empty | Acceptable | **Low** |

### Post-Auth Scenarios

| # | Scenario | Current Behavior | Expected | Mismatch | Severity |
|---|----------|-----------------|----------|----------|----------|
| 17 | Guest logs in after cart-related action | → `/` (home). Cart data in DB is claimed by `knx_auto_login_user_by_id`. Cart drawer now visible. But user is on home, not cart. | Return to `/cart` or previous page | **Critical** | **Critical** |
| 18 | Guest registers after cart-related action | → `/login` (verify email flow). After verification + login → `/` | Return to `/cart` after full auth | **Critical** | **Critical** |
| 19 | Guest logs in from non-cart context | → `/` (home) | Return to previous page or home as fallback | **Medium** | **Medium** |
| 20 | Guest registers from non-cart context | → `/login` → login → `/` | Same as 19 | **Medium** | **Medium** |
| 21 | Logged user lands with existing cart | Page renders, cart drawer visible, badge shows count | ✅ Correct | None | — |
| 22 | Logged user lands with empty cart | Page renders, badge hidden (count=0) | ✅ Correct | None | — |
| 23 | Logged user resumes transaction after auth | Not supported — no continuation state preserved | Should return to intended page | **Critical** | **Critical** |
| 24 | Logged user returns from auth wall | `redirect_to` is generated but **never consumed** | Should honor `redirect_to` | **Critical** | **Critical** |

### Protected-Route Scenarios

| # | Scenario | Current Behavior | Expected | Severity |
|---|----------|-----------------|----------|----------|
| 25 | Guest → `/my-addresses` | Shortcode shows "Login Required" with `/login` link (no redirect_to) | Should preserve return URL | **Medium** |
| 26 | Guest → `/my-orders` | Shortcode shows auth gate with `/login` link (no redirect_to) | Same | **Medium** |
| 27 | Guest → `/profile` | Shortcode shows "Login Required" with `/login` link (no redirect_to) | Same | **Medium** |
| 28 | Guest → restricted admin page | `auth-redirects.php` redirects to `/login?redirect_to=<current_url>` | ✅ Generates redirect_to correctly | — |
| 29 | User logs in from protected-route attempt (via auth-redirects) | `redirect_to` param is **ignored** by `auth-handler.php` → goes to `/` | Should honor `redirect_to` | **Critical** |
| 30 | User registers from protected-route attempt | Register → `/login` → login → `/`. `redirect_to` lost entirely. | Should preserve through register flow | **Critical** |

### Direct URL / Session Scenarios

| # | Scenario | Current Behavior | Expected | Severity |
|---|----------|-----------------|----------|----------|
| 31 | Guest → `/cart` directly | Cart page renders. If `knx_cart_token` cookie exists, shows items. | ✅ Correct | — |
| 32 | Guest → `/checkout` directly | "Please login to continue" message | ✅ Correct guard. Missing return URL. | **Medium** |
| 33 | Logged user → `/cart` directly | Cart page renders with items | ✅ Correct | — |
| 34 | Logged user → `/checkout` directly | Checkout renders if cart has items | ✅ Correct | — |
| 35 | Session expires while cart exists | `knx_session` cookie expires. User becomes guest. Cart data in LS persists. DB cart still linked to old `session_token`. | Cart page still works (reads by `knx_cart_token`). Drawer disappears (guest). | **Medium** |
| 36 | Session expires during auth flow | Toast transient may expire (30s TTL). Redirect loop unlikely. | Acceptable but fragile | **Low** |
| 37 | User refreshes during auth/cart transition | Server redirect is atomic (PHP). LS cart persists. | ✅ Safe | — |
| 38 | User hits back after auth redirect | Goes to `/login`. `auth-redirects.php` redirects logged user away to `/cart` (customer) | Awkward but not broken | **Low** |
| 39 | Multiple tabs | `storage` event syncs badge across tabs. Session cookie shared. | ✅ Badge syncs. Auth state shared. | — |
| 40 | Login in one tab, guest cart UI in another | Other tab still has old navbar (guest). Badge hidden. LS cart events fire on `storage`. | Tab needs reload to show drawer. Badge stays hidden. | **Medium** |

### Logout Scenarios

| # | Scenario | Current Behavior | Expected | Severity |
|---|----------|-----------------|----------|----------|
| 41 | Logout with empty cart | `knx_logout_user()` → deletes session, clears cookie → redirect to `/login` | ✅ Acceptable | — |
| 42 | Logout with active cart | Same as 41. `knx_cart_token` cookie is **NOT cleared** on logout. LS cart persists. DB cart still linked to old customer_id. | Cart data orphaned — `session_token` matches but `customer_id` set. Guest can still see items on `/cart` page (matched by `session_token`), but ownership is inconsistent. | **Medium** |
| 43 | Logout while cart drawer open | Logout is a `<form method="post">` submit → full page redirect. Drawer gone. | ✅ Acceptable (hard redirect clears UI) | — |
| 44 | Logout while on cart page | Same redirect to `/login`. Cart page accessible again as guest (LS + `knx_cart_token` still present) | ✅ Functionally correct but ownership may be wrong | **Low** |
| 45 | Logout on checkout-adjacent path | Redirect to `/login`. Checkout will gate on next visit. | ✅ Correct | — |

---

## 4. Redirect Authority Inventory

### Authority 1: `auth-handler.php` — Login Success (BACKEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/modules/auth/auth-handler.php` lines 278–294 |
| **Function** | `init` hook handler, `knx_login_btn` block |
| **Purpose** | Decides where user goes after successful login |
| **Trigger** | Successful login POST |
| **Current destination** | Customer → `/` (home). Driver → `/driver-ops`. Hub mgmt → `/hub-dashboard`. Admin/Manager → `/live-orders`. Default (fallback) → `/cart`. |
| **Scope** | Global — applies to ALL login attempts regardless of context |
| **Reads redirect_to?** | **NO** — completely ignores `$_GET['redirect_to']` and `$_POST` |
| **Conflicts with** | Authority 2 (redirect_to generator), Authority 3 (shortcode guard) |
| **Safety** | ⚠️ **DANGEROUS** — unconditionally sends customers to home, destroying all intent |

### Authority 2: `auth-redirects.php` — Protected Route Guard (BACKEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/modules/auth/auth-redirects.php` lines 63–69 |
| **Function** | `template_redirect` hook |
| **Purpose** | Redirects unauthenticated users from restricted pages to login |
| **Trigger** | Guest visits any slug in `$restricted_pages` |
| **Current destination** | `/login?redirect_to=<current_full_url>` |
| **Scope** | Broad — covers ~30 restricted slugs |
| **Generates redirect_to?** | **YES** |
| **Conflicts with** | Authority 1 (**which ignores the redirect_to it generates**) |
| **Safety** | ✅ Generation is correct. ⚠️ Consumption is broken. |

### Authority 3: `auth-redirects.php` — Logged User Away From Login (BACKEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/modules/auth/auth-redirects.php` lines 47–61 |
| **Function** | `template_redirect` hook |
| **Purpose** | Prevents logged users from seeing `/login` or `/register` pages |
| **Trigger** | Logged user visits `/login` or `/register` |
| **Current destination** | Admin → `/knx-dashboard`. Hub mgmt → `/hub-dashboard`. Driver → `/driver-ops`. Customer/other → `/cart`. |
| **Scope** | Global |
| **Conflicts with** | Authority 1 (sends customers to `/` on login, then if they hit back to `/login`, this sends them to `/cart`) |
| **Safety** | ⚠️ **Inconsistent** — Authority 1 sends customers to `/`, Authority 3 sends them to `/cart`. Different destinations for the same role. |

### Authority 4: `auth-shortcode.php` — Shortcode Logged Guard (BACKEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/modules/auth/auth-shortcode.php` lines 6–9 |
| **Function** | `knx_auth` shortcode callback |
| **Purpose** | Redirects logged users away from the auth page |
| **Trigger** | Logged user renders the `[knx_auth]` shortcode |
| **Current destination** | `/cart` |
| **Conflicts with** | Authority 1 (which sends customers to `/` on login) and Authority 3 (which also sends customers to `/cart`). This and Authority 3 may both fire on the same request. |
| **Safety** | ⚠️ **Redundant and inconsistent with Authority 1** |

### Authority 5: `auth-redirects.php` — Role-Based Restrictions (BACKEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/modules/auth/auth-redirects.php` lines 73–119 |
| **Function** | `template_redirect` hook |
| **Purpose** | Blocks roles from accessing pages above their permission level |
| **Trigger** | Logged user visits unauthorized dashboard/admin pages |
| **Current destination** | Customer on dashboard → `/cart`. Hub mgmt on admin → `/hub-dashboard`. Driver on admin → `/driver-ops`. Studio unauthorized → `/`. |
| **Safety** | ✅ Mostly correct for role enforcement. Customer fallback to `/cart` is odd (should be `/` or account landing). |

### Authority 6: `helpers.php` — `knx_guard()` (BACKEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/functions/helpers.php` lines 79–86 |
| **Function** | `knx_guard()` |
| **Purpose** | Guards restricted shortcodes/pages |
| **Trigger** | Called programmatically by shortcodes |
| **Current destination** | `/login` (no redirect_to) |
| **Safety** | ⚠️ **No return URL** — intent is lost |

### Authority 7: `knx_logout_user()` — Logout (BACKEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/functions/helpers.php` lines 90–104 |
| **Purpose** | Clears session, redirects after logout |
| **Current destination** | `/login` |
| **Safety** | ✅ Acceptable |

### Authority 8: Shortcode-Level Auth Gates (BACKEND)

Multiple shortcodes implement their own inline auth check and render a "Login Required" message with a link to `/login`:

| File | Link to Login | Includes redirect_to? |
|------|--------------|----------------------|
| `checkout-shortcode.php` | `<a href="/login">` | **No** |
| `profile-shortcode.php` | `<a href="/login">` | **No** |
| `my-orders-shortcode.php` | `<a href="/login">` | **No** |
| `my-addresses-shortcode.php` | `<a href="/login">` | **No** |

**Safety:** ⚠️ **All lose context** — none pass `redirect_to` or `return_url`.

### Authority 9: `auth-handler.php` — Register Success (BACKEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/modules/auth/auth-handler.php` lines 300–441 |
| **Purpose** | Redirect after registration |
| **Current destination** | Always `/login` (via `knx_queue_redirect`) |
| **Safety** | ✅ For verification flow. ⚠️ Any pre-registration continuation context is lost (no forwarding of redirect_to through register). |

### Authority 10: Cart Drawer "Checkout" Link (FRONTEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/modules/cart/cart-drawer.js` lines 355–357 |
| **Purpose** | Links to `/checkout` from cart drawer |
| **Current destination** | Hard-coded `/checkout` |
| **Safety** | ✅ Correct for logged users. Not relevant for guests (drawer hidden). |

### Authority 11: Cart Drawer "Review Cart" Link (FRONTEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/modules/cart/cart-drawer.js` line 350, `navbar.php` line |
| **Purpose** | Links to `/cart` from drawer |
| **Current destination** | `/cart` |
| **Safety** | ✅ Correct |

### Authority 12: `checkout-payment-flow.js` — Post-Payment Redirect (FRONTEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/public/checkout/checkout-payment-flow.js` |
| **Purpose** | Redirects to order status after successful payment |
| **Current destination** | `/order-status?order_id=X` or `cfg.successRedirectUrl` or home |
| **Safety** | ✅ Correct — uses `safeRedirectUrl()` with same-origin check |

### Authority 13: Navbar Login Link (FRONTEND)

| Attribute | Value |
|-----------|-------|
| **File** | `inc/public/navigation/navbar.php` line ~161 |
| **Purpose** | Sends guest to login page |
| **Current destination** | Hard-coded `<a href="/login">` |
| **Includes context?** | **No** — no `redirect_to`, no current-page reference |
| **Safety** | ⚠️ **Context-blind** |

---

## 5. Cart Visibility and Access Audit

### Is cart UI visible to guests today?

**No.** The primary navbar (`inc/public/navigation/navbar.php`) wraps the cart toggle, badge, and drawer in `<?php if ($context['is_logged']): ?>`. Guests see no cart button, no badge, no drawer.

### Is cart drawer openable by guests?

**No.** The drawer HTML (`<aside class="knx-cart-drawer">`) is only rendered for logged users. The `cart-drawer.js` still loads (via the old `navbar-render.php` path on some pages), but without the DOM elements, it silently no-ops.

### Is cart page accessible by guests?

**Yes.** `/cart` renders the `[knx_cart_page]` shortcode, which resolves the cart by `knx_cart_token` cookie — no session required. Guests can see their cart items on this page.

### Can guests see item count badge?

**No.** `#knxCartBadge` is only rendered for logged users. The `navigation-script.js` and `navbar-script.js` both try to update it, but the element doesn't exist in guest DOM.

### Can guests edit cart contents?

**Partially.** The cart *page* shortcode (`cart-shortcode.php`) renders items as **read-only static HTML** — no quantity buttons, no remove buttons. The cart *drawer* has edit controls but is hidden for guests. The menu page's "add to cart" modal works for guests. So guests can add but cannot edit or remove from the cart through the UI.

### Are there conditions where cart UI disappears based on auth state?

**Yes.** Cart toggle, badge, drawer, and sidebar cart link all disappear when `is_logged` is false. This is the primary visibility gate.

### Where is cart visibility decided?

**Multiple places:**
- `inc/public/navigation/navbar.php` — `if ($context['is_logged'])` gates cart toggle, badge, drawer, sidebar
- `inc/modules/navbar/navbar-render.php` (legacy) — similar gating
- `inc/public/navigation/customer-sidebar.php` — only rendered for logged customers
- Cart page shortcode — **no auth gate** (correctly accessible by all)

### Are visibility and permissions incorrectly coupled?

**Yes.** Cart visibility (UI) is being used as a proxy for checkout permission. The correct approach is: cart is visible to all, checkout is gated. Currently, the system hides the entire shopping tool instead of gating only the protected action.

### Are there UX dead-ends caused by guest cart hiding?

**Yes.** A guest adds items on a menu page, then has no visible way to view or manage those items from the navbar. The only path is to manually type `/cart` in the URL bar, which is not a real user path.

---

## 6. Cart Continuity Across Auth

### Where is guest cart stored?

- **Frontend:** `localStorage` key `knx_cart` (array of items with metadata)
- **Backend:** `knx_carts` table (matched by `session_token` from `knx_cart_token` cookie), `knx_cart_items` table

### Where is logged-in cart stored?

- Same as guest. The `session_token` is the correlation key. After login, `knx_auto_login_user_by_id()` also sets `customer_id` on the cart row.

### Are there separate cart concepts?

**No.** Both guest and logged-in use the same `knx_cart_token` cookie → `session_token` → `knx_carts` row. This is good design.

### Is there a merge flow after login?

**No merge.** `knx_auto_login_user_by_id()` simply sets `customer_id` on the existing guest cart. If the user already had a different cart from a previous session, there's no merge logic. The latest active cart by `session_token` is claimed.

### Does register create a new cart state?

**No.** Registration doesn't touch cart state. The user must log in after registration (separate step), and that login triggers the cart claim.

### Can auth wipe the cart?

**Not directly.** `knx_auto_login_user_by_id()` claims the cart, doesn't delete it. However, if the `knx_cart_token` cookie is missing or was cleared, the cart claim silently fails and the DB cart becomes orphaned.

### Can auth duplicate the cart?

**Unlikely.** The claim uses `WHERE customer_id IS NULL` and updates a single row.

### Can the cart drawer show stale state after auth?

**Yes.** After login, the PHP redirect sends the browser to a new page. The LS `knx_cart` data persists and is read on the destination page. If the destination is `/` (home), the cart drawer is now visible (user is logged in) and reads from LS — which should still have the correct items. However, if the DB sync has mutated the cart (e.g., server rejected an item), the LS data is stale until the next `cart/sync` call.

### Can the cart page and cart badge disagree?

**Yes.** The cart page reads from **DB** (via `session_token`). The badge reads from **localStorage**. These are two separate sources of truth. If they diverge (sync failure, race condition), the user sees inconsistent counts.

### Can item modifiers or quantities be lost during transition?

**Unlikely** for the DB path — modifiers are stored as JSON in `knx_cart_items.modifiers_json`. For LS, they persist across page loads. The risk is LS→DB sync failure, which would make the cart page (DB) disagree with the drawer (LS).

### Overall cart continuity assessment:

**Partially coherent.** The data layer is sound (single `session_token` correlation, proper claiming). The presentation layer has two SOTs (LS and DB) that are not guaranteed to agree. The system is **fragile but not dangerous** for cart data — the real damage is in navigation, not data.

---

## 7. Guest Restriction Audit

| Capability | Can Guest Do It? | How? | Correct? |
|-----------|-----------------|------|----------|
| Browse stores | ✅ Yes | `/explore-hubs` is public | ✅ |
| Browse menus | ✅ Yes | `/menu/{slug}` is public | ✅ |
| Add items | ✅ Yes | Menu page JS writes to LS + syncs to DB | ✅ |
| Edit cart (qty) | ❌ No via UI | Cart drawer hidden, cart page read-only | ❌ Should be able to |
| Remove items | ❌ No via UI | Same as above | ❌ Should be able to |
| Open cart drawer | ❌ No | Toggle button not rendered for guest | ❌ Should be visible |
| Access cart page | ✅ Yes | `/cart` shortcode has no auth gate | ✅ But undiscoverable |
| See totals | ✅ Partially | Cart page shows subtotal. No badge visible. | ⚠️ |
| Start checkout | ❌ No | Cart page CTA says "Login to checkout" | ✅ Correct gate |
| Open `/checkout` directly | ❌ No | Shortcode shows "Please login" | ✅ Correct gate |
| What happens at checkout? | Login prompt shown | Link to `/login` with no return context | ⚠️ Intent lost |
| Is guest blocked early or late? | Mixed | Cart access: too early (hidden). Checkout: correct (blocked at action). | ⚠️ |
| Is guest redirected or messaged? | Messaged (in-page) | Shortcodes show inline auth gates | ✅ Acceptable |
| Does flow preserve intent? | ❌ No | No return URL passed to login from any guest-facing gate | ❌ |
| Is guest sent home? | ✅ Yes, after login | Auth handler sends customers to `/` | ❌ **Critical** |

**Does the current system follow "Allow shopping intent, block order completion cleanly"?**

**No.** It blocks *cart visibility* for guests (too aggressive) while correctly blocking checkout. But it then destroys shopping context upon auth by sending to home (too destructive). The system over-restricts the non-sensitive action (viewing cart) and under-preserves the sensitive transition (auth → continuation).

---

## 8. Edge Case Matrix

| # | Scenario | Current/Inferred Behavior | Risk | Status | Severity |
|---|----------|--------------------------|------|--------|----------|
| 1 | Guest has cart, logs in from navbar | → `/` (home). Cart data claimed in DB. Drawer now visible on home. User has lost their position. | Context destruction, conversion loss | **Broken** | **Critical** |
| 2 | Guest has cart, registers from navbar | → `/login` (verify). After verify + login → `/` | Same as 1, with extra steps | **Broken** | **Critical** |
| 3 | Guest has cart, attempts checkout, then logs in | `/checkout` shows gate → link to `/login` → login → `/` (home) | Checkout intent completely lost | **Broken** | **Critical** |
| 4 | Guest has cart, attempts checkout, then registers | Same as 3 with register intermediate step | Checkout intent lost | **Broken** | **Critical** |
| 5 | Guest has empty cart, logs in voluntarily | → `/` (home) | Acceptable (no intent to preserve) | Handled | **None** |
| 6 | Guest is browsing menu, logs in voluntarily | → `/` (home). Loses menu position. | Context loss, minor | **Partially handled** | **Medium** |
| 7 | Guest is on store page, logs in voluntarily | → `/` (home) | Same as 6 | **Partially handled** | **Medium** |
| 8 | Guest is on cart page, logs in | Cart page CTA → `/login` → login → `/` (home) | Should return to `/cart` | **Broken** | **Critical** |
| 9 | Guest on protected page → sent to login | `redirect_to` appended but **never consumed** → `/` (home) | Protected route return broken | **Broken** | **Critical** |
| 10 | Guest on protected page → sent to register | `redirect_to` not forwarded through register flow → lost | Same | **Broken** | **Critical** |
| 11 | Guest has cart, session expires | `knx_session` cookie expires. LS cart persists. `knx_cart_token` persists. Cart page still works. Drawer disappears. | Cart visually disappears from nav. | **Partially handled** | **Medium** |
| 12 | Guest has cart, closes browser, returns | LS cart persists (unless cleared). `knx_cart_token` cookie persists (14-day expiry). Cart page works. Drawer hidden (guest). | Functional but invisible | **Partially handled** | **Medium** |
| 13 | Logged user logs out, cart visible | Logout → `/login`. `knx_cart_token` NOT cleared. LS cart persists. DB cart has `customer_id` set. | Cart data orphaned but functionally accessible | **Partially handled** | **Low** |
| 14 | Multiple tabs, mixed auth/cart state | `storage` event syncs LS cart changes. Auth cookie shared. But if one tab is pre-login (guest navbar) and another is post-login, the guest tab won't re-render navbar. | Stale UI in non-active tab | **Known limitation** | **Low** |
| 15 | Login in one tab while another has guest cart UI | Non-active tab keeps guest navbar until reload. | Same as 14 | **Known limitation** | **Low** |
| 16 | Direct `/checkout` via saved URL | Guest: auth gate. Logged: renders if cart exists. | ✅ Correct | Handled | — |
| 17 | Direct `/cart` via saved URL | Works for both guest and logged. | ✅ Correct | Handled | — |
| 18 | Back button after login | Goes to `/login`. `auth-redirects.php` redirects logged customer to `/cart`. | Works but inconsistent (login handler went to `/`, back-then-forward goes to `/cart`) | **Partially handled** | **Low** |
| 19 | Refresh right after login, before cart hydration | PHP redirect is atomic. LS persists. DB cart is already claimed. | ✅ Safe | Handled | — |
| 20 | Refresh during post-register flow | Register → redirect to `/login`. Refresh reloads `/login`. | ✅ Safe | Handled | — |
| 21 | API returns unauthorized during cart refresh | `cart/sync` has `permission_callback: __return_true` — no auth required. | ✅ Cart sync works for guests | Handled | — |
| 22 | API returns stale cart after auth | Cart sync overwrites DB state each time. If LS is stale, DB gets stale data. | Potential but unlikely — sync fires on LS change | **Unknown** | **Low** |
| 23 | Empty cart + forced cart redirect | Auth-redirects sends logged customer to `/cart` when visiting `/login`. Cart page shows "empty cart" message with "Browse restaurants" link. | Not harmful but awkward | **Partially handled** | **Low** |
| 24 | Full cart + accidental home redirect | Customer login → home. Cart exists but user is on irrelevant page. | **Standard case of the main bug** | **Broken** | **Critical** |
| 25 | Register succeeds but continuation lost | Always → `/login`. No forwarding. | **Broken** | **Critical** |
| 26 | Login succeeds but badge/drawer stays stale | Full page redirect — navbar re-renders from server. Badge reads LS on new page. | ✅ Should be fresh on new page | Handled | — |
| 27 | Existing account cart conflicts with guest cart | `knx_auto_login_user_by_id` claims the `session_token` cart. If user had a different old cart from previous login (different `session_token`), that old cart remains orphaned. No merge. | Potential data inconsistency | **Partially handled** | **Medium** |
| 28 | Cart contains invalid items after auth | No validation occurs at claim time. Items validated only at checkout (quote endpoint). | Deferred validation — acceptable if checkout catches it | **Partially handled** | **Low** |
| 29 | City/hub changed during auth flow | No city/hub validation at login time. Cart may reference a hub outside user's new context. | Caught at checkout quote time | **Partially handled** | **Low** |
| 30 | Auth modal closes without preserving continuation | No auth modal exists — auth is a full-page form. | N/A | — | — |
| 31 | `redirect_to` exists but is ignored | **This is the primary bug.** `auth-redirects.php` generates it; `auth-handler.php` ignores it. | **Core broken contract** | **Broken** | **Critical** |
| 32 | `redirect_to` honored when it shouldn't be | Never honored, so this case doesn't arise. | N/A | — | — |
| 33 | Query-param continuation creates loop | Not possible currently — `redirect_to` is never read. | N/A | — | — |
| 34 | Logout creates ownership mismatch | `customer_id` remains on DB cart. `knx_cart_token` persists. Guest can see items on `/cart` page (matched by `session_token`). But DB thinks cart belongs to a specific customer. | Ownership mismatch if another user logs in on same browser | **Partially handled** | **Medium** |

---

## 9. UX Continuity Findings

### Does auth feel like continuation or interruption?

**Interruption.** Every login for a customer ends at home, regardless of what they were doing. The user must re-navigate to their previous context manually. This is especially damaging for cart/checkout flows where the user has built purchase intent.

### Does cart feel like a real persistent shopping object?

**No, for guests.** The cart exists in data but is invisible in the UI. For logged users, yes — the drawer and badge make it feel present.

### Are we respecting shopping momentum?

**No.** Adding items creates momentum. Hiding the cart, then destroying position after auth, breaks momentum twice.

### Are we overusing hard redirects?

**Yes.** Every auth action results in a PHP `wp_safe_redirect` to a hardcoded destination. There are no soft transitions, no client-side continuation, no "you'll be returned to..." messaging.

### Are we under-communicating why checkout is blocked?

**Partially.** The cart page says "Login to checkout" — this is clear. The checkout page says "Please login to continue" — also clear. But neither explains that the cart is preserved, which matters for user confidence.

### Are guests being punished instead of guided?

**Yes.** Hiding the cart drawer from guests is punishment. The guest did the right thing (added items) but gets no feedback, no badge count, no way to review without knowing the URL.

### Are protected-route returns coherent?

**No.** The return URL is generated and then discarded.

### Are there dead-end moments?

**Yes:**
1. Guest adds items → no cart badge → no way to know items were saved (on non-menu pages)
2. Guest clicks "Login to checkout" → logs in → lands on home → must find cart again
3. Guest visits protected page → redirected to login → logs in → lands on home → original page forgotten

### Does the system preserve confidence during auth transitions?

**No.** The user has no assurance that their cart, position, or intent will be preserved. The redirect to home after every login creates a "reset" feeling.

---

## 10. Risk Classification

### Critical Issues (Before Production)

| # | Issue | Impact | Location |
|---|-------|--------|----------|
| C1 | **`redirect_to` generated but never consumed** | Protected-route return completely broken. Users always go home after login regardless of where they came from. | `auth-redirects.php` generates → `auth-handler.php` ignores |
| C2 | **Customer post-login always goes to `/`** | Every login destroys context for customers. Cart intent, checkout intent, browsing position — all lost. | `auth-handler.php` line 285 |
| C3 | **Cart drawer/toggle/badge hidden for guests** | Guests cannot see or manage their cart from the navbar. Shopping intent is invisible. | `navbar.php` — `if ($context['is_logged'])` gates all cart UI |
| C4 | **No return URL from any shortcode auth gate** | Profile, orders, addresses, checkout — all link to `/login` without `redirect_to`. Intent always lost. | `profile-shortcode.php`, `my-orders-shortcode.php`, `my-addresses-shortcode.php`, `checkout-shortcode.php` |
| C5 | **Auth-redirects and auth-handler disagree on customer destination** | `auth-redirects.php` sends logged customer to `/cart` when they visit `/login`. `auth-handler.php` sends them to `/` on login. Two different "correct" destinations for the same role. | Both files |
| C6 | **Register flow loses all continuation state** | Registration always redirects to `/login`. Any `redirect_to` in the original URL is not forwarded. After register → verify → login, the original intent is gone. | `auth-handler.php` register block |

### Medium-Risk Issues

| # | Issue | Impact |
|---|-------|--------|
| M1 | Cart page is read-only for guests (no edit/remove controls in shortcode) | Guest can add but can't modify. Must either use drawer (hidden) or clear LS manually. |
| M2 | Navbar login link has no current-page context | Even for voluntary login, returning to the same page would be better than home. |
| M3 | Dual source of truth (LS + DB) for cart display | Cart page reads DB, drawer reads LS. Can disagree on counts/items after sync failures. |
| M4 | Logout doesn't clear `knx_cart_token` | Cart ownership mismatch after logout if another user logs in on same device. |
| M5 | No cart merge logic when user has pre-existing cart from different session | Old cart from previous login orphaned. Could confuse returning users. |
| M6 | Session expiry hides cart drawer mid-session | Long browsing session could lose drawer visibility without user action. |

### Low-Risk Issues

| # | Issue | Impact |
|---|-------|--------|
| L1 | Back-button after login goes to `/login` which redirects to `/cart` (not home) | Inconsistent with the login handler's destination. |
| L2 | Auth toast transient has 30s TTL — could expire if user is slow | Minor UX: toast may not appear if login page loads slowly. |
| L3 | Cross-tab stale navbar (guest tab doesn't update after login in another tab) | Minor: requires tab reload. |
| L4 | `auth-shortcode.php` and `auth-redirects.php` both redirect logged users from `/login` (redundant) | No functional issue but redundant code. |

---

## 11. Inferred Current Continuation Policy

The system currently operates under a **fragmented home-first / role-only routing policy**:

1. **Login handler** uses a **role-switch with no context awareness**:
   - Customer → home `/`
   - Driver → `/driver-ops`
   - Hub mgmt → `/hub-dashboard`
   - Admin → `/live-orders`
   - Default → `/cart`

2. **Auth-redirects** uses a **role-switch for "logged user on login page"** with *different destinations*:
   - Customer → `/cart`
   - Other roles → respective dashboards

3. **Auth shortcode** sends logged users to `/cart`.

4. **No authority reads any continuation state** (`redirect_to`, `return_url`, previous page, cart state, etc.)

**Inferred policy name: "Role-destination-only with accidental home fallback for customers."**

This is not a continuation policy — it's a dispatch table. It knows *who* the user is but not *what they were doing*. The `redirect_to` mechanism was clearly intended to add context-awareness, but the implementation was never completed (the consumer side was never built).

The result is that the system has three competing "correct" destinations for a logged-in customer:
- `/` (auth-handler.php says this)
- `/cart` (auth-redirects.php and auth-shortcode.php say this)
- The original page (redirect_to says this, but nobody listens)

This triple disagreement is the root cause of most navigation issues.

---

## 12. Recommended Production Continuation Policy

### Contextual Continuation Model

After authentication, the system should select a destination based on this priority cascade:

```
1. EXPLICIT CONTINUATION (redirect_to / return_url)
   ↓ if not present or invalid
2. TRANSACTION CONTINUATION (cart/checkout context)
   ↓ if not applicable
3. ROLE-BASED LANDING (dashboard for admin, ops for driver, etc.)
   ↓ if customer with no special context
4. SAFE FALLBACK (home for customers, dashboard for admins)
```

### Detailed Rules

#### A. Protected Route Return
**When:** `redirect_to` query parameter exists and is a valid same-origin URL.  
**Action:** After login, redirect to that URL.  
**Validation:** Must be same-origin. Must not point to `/login` or `/register` (would create loop). Must be a real page.  
**Applies to:** All roles.

#### B. Transaction Continuation
**When:** User was in a cart or checkout context (identified by `redirect_to=/cart`, `redirect_to=/checkout`, or a `knx_cart_intent` parameter).  
**Action:** After login, redirect to `/cart` (safest transactional re-entry point, since checkout will re-validate).  
**When NOT:** Cart is empty → fall through to C/D.

#### C. Cart Continuation
**When:** User has an active non-empty cart (detectable by `knx_cart_token` cookie + DB check) and no explicit redirect_to.  
**Action:** Could redirect to `/cart`, but **only if login was triggered from a cart/checkout context**. If login was voluntary (navbar), do not force cart.  

#### D. Voluntary Login/Register
**When:** User clicked login from navbar with no protected-route or transaction trigger. No `redirect_to` present.  
**Action:** Redirect to `/` (home) for customers, respective dashboard for other roles.  
**Rationale:** Home is a valid fallback for voluntary authentication. The key difference is this should only apply when NO better continuation exists.

#### E. Safe Fallback
**When:** All above conditions fail or are invalid.  
**Action:** `/` for customers, `/knx-dashboard` for admins, `/driver-ops` for drivers, etc.

### When Home Is Valid
- Voluntary login/register with no intent context
- Empty cart + no redirect_to

### When Home Should NOT Be Used
- `redirect_to` exists
- User was on a menu/store page
- User was on cart or checkout
- User was on a protected account page
- User had an active cart and was interacting with it

### When Cart Is Valid
- User was on `/cart` or `/checkout`
- User clicked "Login to checkout"
- `redirect_to` points to `/cart` or `/checkout`

### When Cart Should NOT Be Forced
- Cart is empty
- User logged in voluntarily from navbar
- User was accessing a non-cart protected page (e.g., `/my-addresses` for account purposes)
- User is not a customer role

---

## 13. Refactor Strategy (Pre-Implementation)

### Phase 1: Fix the Critical Broken Contract (redirect_to consumption)

**Goal:** Make `auth-handler.php` honor `redirect_to` when present.

**Changes needed:**
1. In `auth-handler.php` LOGIN block: before the role-switch, check for `$_GET['redirect_to']` or `$_POST['redirect_to']`. Validate it (same-origin, not `/login`, not `/register`). If valid, use it as `$redirect_url`.
2. In `auth-handler.php` LOGIN block: the role-switch becomes the *fallback* only when no valid redirect_to exists.
3. Resolve the customer destination disagreement: if no redirect_to, customer goes to `/` (current behavior) or decide on a single canonical fallback.

### Phase 2: Fix Guest Cart Visibility

**Goal:** Show cart toggle, badge, and drawer to guests.

**Changes needed:**
1. In `navbar.php`: remove the `if ($context['is_logged'])` gate around the cart toggle button, badge, and cart drawer HTML. Always render these elements.
2. In `navbar.php`: keep cart-drawer CSS and JS loaded for all users (currently only loaded for logged users).
3. The cart drawer's checkout button should be context-aware: show "Login to checkout" for guests, "Checkout" for logged users.
4. The `customer-sidebar.php` can remain logged-only (it's an account navigation tool, not a shopping tool).

### Phase 3: Add redirect_to to All Auth Gate Links

**Goal:** Every "Login" link in the system should carry the current page as a return URL.

**Changes needed:**
1. `cart-shortcode.php` "Login to checkout" → `/login?redirect_to=/cart`
2. `checkout-shortcode.php` "Login" link → `/login?redirect_to=/checkout`
3. `profile-shortcode.php` "Login" link → `/login?redirect_to=/profile`
4. `my-orders-shortcode.php` "Sign In" link → `/login?redirect_to=/my-orders`
5. `my-addresses-shortcode.php` "Log In" link → `/login?redirect_to=/my-addresses`
6. `helpers.php` `knx_guard()` → include `redirect_to` in the redirect URL
7. Navbar login link → include current page as redirect_to (requires JS or PHP to detect current URL)

### Phase 4: Forward redirect_to Through Register Flow

**Goal:** If user arrives at login with redirect_to and switches to register, the redirect_to should survive.

**Changes needed:**
1. `auth-shortcode.php`: read `$_GET['redirect_to']` and embed it as a hidden field in both login and register forms.
2. `auth-handler.php` REGISTER block: on successful register, redirect to `/login?redirect_to=<preserved_value>`.
3. After email verification, redirect to `/login?redirect_to=<preserved_value>` (requires storing redirect_to in verification record or passing through the flow).

### Phase 5: Harmonize Destination Authorities

**Goal:** Single source of truth for "where does this role go by default?"

**Changes needed:**
1. Create a canonical function: `knx_get_role_landing($role)` that returns the default landing page for each role.
2. Replace all hardcoded role-switch blocks in `auth-handler.php`, `auth-redirects.php`, and `auth-shortcode.php` with calls to this function.
3. `auth-shortcode.php` should call this function instead of hardcoding `/cart`.

### Phase 6: Cart Page Edit Capabilities for Guests

**Goal:** Guests should be able to edit quantities and remove items on the `/cart` page.

**Changes needed:**
1. The cart page shortcode currently renders static PHP HTML from DB. Either:
   - Add JS-driven edit controls that modify localStorage and trigger `cart/sync`, or
   - Make the cart page JS (`cart-page.js`) the primary renderer (it already reads from LS) and add edit controls.
2. Ensure `cart/sync` endpoint continues to work without session auth (it already has `permission_callback: __return_true`).

### Phase 7: Logout Cart Hygiene

**Goal:** Clean cart state on logout to prevent ownership mismatches.

**Changes needed:**
1. `knx_logout_user()`: optionally clear `knx_cart_token` cookie, or at minimum clear `customer_id` on the active cart so it becomes a guest cart again.
2. Consider clearing `knx_cart` from localStorage via a logout-triggered JS snippet (requires logout to be a page load, which it already is).

### Phase 8: Stale UI Prevention

**Goal:** Ensure badge, drawer, and page all agree after auth transitions.

**Changes needed:**
1. After login redirect, the destination page should trigger a cart sync on load (this already happens in `cart-drawer.js` via `syncCartToServer(readCart())`).
2. Consider dispatching a `knx-cart-updated` event on page load after auth transition to force all cart readers to refresh.

### Priority Order

1. **Phase 1** — Most critical. Fixes the broken redirect_to contract.
2. **Phase 2** — Most visible. Fixes guest cart experience.
3. **Phase 3** — Required for Phase 1 to be useful. Provides the redirect_to values.
4. **Phase 5** — Reduces confusion and future bugs.
5. **Phase 4** — Important but complex. Register flow is multi-step with email verification.
6. **Phase 6** — Quality of life for guests.
7. **Phase 7** — Hygiene.
8. **Phase 8** — Polish.

---

*End of audit. All findings are derived from direct code inspection of the current codebase.*
