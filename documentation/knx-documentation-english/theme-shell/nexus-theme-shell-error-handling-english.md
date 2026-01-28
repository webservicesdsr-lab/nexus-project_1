```markdown
# NEXUS — Theme Shell & Error Handling

## 1. Role of the Theme

The **Nexus Shell theme** provides a **neutral, institutional presentation layer** for WordPress while the **NEXUS plugin remains the single system authority**.

This theme exists to:

- Replace WordPress default messaging and branding
- Provide a clean, professional UX for non-existent pages
- Serve as a stable visual baseline during development and staging
- Stay fully decoupled from system logic

The theme is **presentation-only**.

---

## 2. Core Principles (Sealed)

The following principles are **non-negotiable**:

1. WordPress is a container.
2. The NEXUS plugin owns all logic and decisions.
3. The theme handles presentation only.
4. No business logic is allowed in the theme.
5. The theme must never decide redirects or flow control.

The theme must be **replaceable at any time** without breaking NEXUS.

---

## 3. Current Scope (v1.1.0)

### Implemented

- Custom institutional 404 page
- Fully responsive layout (desktop + mobile)
- Centered card-based layout
- Original SVG illustration (internal asset only)
- Explicit stylesheet loading to avoid WordPress edge cases

### Explicitly NOT Implemented

- Redirect logic
- 500 error handling logic
- Maintenance mode logic
- Authentication or permission checks
- Any form of plugin integration

---

## 4. File Structure

# NEXUS — Theme Shell & Error Handling

## 1. Role of the Theme

The **Nexus Shell theme** provides a **neutral, institutional presentation layer** for WordPress while the **NEXUS plugin remains the single system authority**.

This theme exists to:

- Replace WordPress default messaging and branding
- Provide a clean, professional UX for non-existent pages
- Serve as a stable visual baseline during development and staging
- Stay fully decoupled from system logic

The theme is **presentation-only**.

---

## 2. Core Principles (Sealed)

The following principles are **non-negotiable**:

1. WordPress is a container.
2. The NEXUS plugin owns all logic and decisions.
3. The theme handles presentation only.
4. No business logic is allowed in the theme.
5. The theme must never decide redirects or flow control.

The theme must be **replaceable at any time** without breaking NEXUS.

---

## 3. Current Scope (v1.1.0)

### Implemented

- Custom institutional 404 page
- Fully responsive layout (desktop + mobile)
- Centered card-based layout
- Original SVG illustration (internal asset only)
- Explicit stylesheet loading to avoid WordPress edge cases

### Explicitly NOT Implemented

- Redirect logic
- 500 error handling logic
- Maintenance mode logic
- Authentication or permission checks
- Any form of plugin integration

---

## 4. File Structure


```
