```markdown
# Kingdom Nexus — Bootstrap File Documentation

## 1. File Role

`kingdom-nexus.php` is the **main bootstrap file** of the Kingdom Nexus system.

Its sole responsibility is to **initialize the Nexus framework inside the WordPress runtime**, acting as:

- the canonical system entry point
- the load-order orchestrator
- the bridge between WordPress (container) and Nexus (system)

This file does **not** contain business logic.

---

## 2. Explicit Responsibilities

This file is explicitly allowed to:

- Define global system constants (`KNX_PATH`, `KNX_URL`, `KNX_VERSION`) as a Single Source of Truth.
- Prevent direct execution (`ABSPATH` guard).
- Apply infrastructure-level adjustments (SSL behind reverse proxy).
- Initialize PHP sessions with explicit security rules.
- Register critical WordPress hooks (`init`, `plugins_loaded`, activation).
- Load Nexus engines, modules, and resources in a controlled and intentional order.
- Enqueue **minimal, global, cross-system assets** required by Nexus.

---

## 3. Sealed Contracts (Usage Guidelines)

The following rules are **architectural contracts**.

Breaking them introduces systemic risk.

---

### 3.1 Business Logic

❌ This file must NOT:

- implement order logic
- implement payment logic
- perform calculations
- make functional decisions
- execute queries
- validate domain rules
- render UI

All business logic must live in:

- `/inc/functions`
- `/inc/core`
- `/inc/modules`

---

### 3.2 Asset Enqueue Policy

This file may enqueue **only global, transversal assets**.

Currently approved assets:

- **FontAwesome**  
  Reason: icons used across public and admin interfaces.
- **Toast system**  
  Reason: global notification mechanism.
- **Choices.js**  
  Reason: reusable advanced select inputs.

Strict rules:

- No page-specific assets.
- No module-specific assets.
- No experimental libraries.

Each module is responsible for its own assets.

This file is **not** a generic asset loader.

---

### 3.3 Load Order Integrity

The order of `knx_require()` calls is **intentional and critical**.

General principles:

- Core engines load before resources.
- SSOT authorities load before helpers.
- REST infrastructure loads before endpoints.
- Public UI loads after core initialization.

Changing the order without full dependency awareness may break the system.

---

### 3.4 Stability Contract

This file is considered:

- **Stable**
- **High criticality**
- **Non-experimental**

Changes must be minimal, documented, and aligned with a defined roadmap phase.

---

## 4. Technical Notes

### 4.1 Hooks Registered

Only initialization-level hooks are registered here:

- `init` — secure session bootstrap
- `plugins_loaded` — full system bootstrap
- `register_activation_hook` — initial setup
- `wp_enqueue_scripts` / `admin_enqueue_scripts` — global assets
- `wp_head` — FontAwesome fallback

No functional hooks are implemented here.

---

### 4.2 Session Handling

- Sessions start only if none exists.
- Security flags are enforced (`httponly`, `SameSite=Strict`).
- No session data is managed here.

Session lifecycle logic lives in `/inc/core/session-cleaner.php`.

---

### 4.3 File Loading (`knx_require`)

- Wrapper around `require_once`
- Centralized path resolution via `KNX_PATH`
- No dependency validation
- No exception handling

---

### 4.4 Scheduled Tasks

- Registers `knx_hourly_cleanup`
- Frequency: hourly
- Execution logic lives elsewhere

---

## 5. What This File Is Not

- Not a controller
- Not a router
- Not a module
- Not a testing ground
- Not a place for quick fixes
- Not a Copilot playground

---

## 6. Architectural Principle

> WordPress is the container.  
> Nexus is the system.  
> This file only connects them.

If this file accumulates logic, Nexus loses clarity.

```
