# 🧭 VISIÓN CANÓNICA — Driver Active Orders

Antes de los tasks, deja esto claro (Copilot necesita este marco):

* `driver-ops` = **descubrir y aceptar**
* `driver-active-orders` = **ejecutar una orden ya asignada**
* Un driver puede tener **1 o más órdenes activas**
* Esta vista es **operativa**, no administrativa
* Todo debe basarse en **snapshot + order state**, nunca en cart

---

# 🧠 ANÁLISIS DE LAS IMÁGENES (resumido)

## Imagen 1 — Ejemplo funcional

* Header con order id + fecha + botón Back
* Secciones claras:

  * Restaurant info
  * Client info
  * Order items
  * Totals
  * Payment
  * Delivery method + time slot
* Abajo:

  * Map
  * Status history (timeline vertical)

## Imagen 2 — Layout deseado

* Mobile-first
* Cards/secciones separadas
* Jerarquía clara (titles pequeños, contenido dominante)
* Mucho aire vertical
* Nada apretado

## Imagen 3 — Order Status (timeline)

* Timeline vertical
* Cada estado con:

  * Icono
  * Texto
  * Timestamp
  * Quién lo ejecutó
* Estado actual claramente visible

---

# ✅ KNX-TASK 01 — Registrar Driver Active Orders (estructura base)

```
KNX-TASK: Driver Active Orders — Module scaffold

- Create new module folder:
  inc/modules/ops/driver-active-orders/

- Inside module create:
  - driver-active-orders-shortcode.php
  - driver-active-orders-script.js
  - driver-active-orders-style.css

- Module purpose:
  - Render active (assigned) orders for driver
  - Execution-focused UI (not discovery)

- Do NOT reuse driver-ops files
- This is a separate responsibility
```

---

# ✅ KNX-TASK 02 — Registrar página en el instalador de Nexus

```
KNX-TASK: Driver Active Orders — Page installer

- Update Kingdom Nexus page installer
- Register a new internal page:
  Slug: driver-active-orders
  Title: Active Orders
  Shortcode: [knx_driver_active_orders]

- Page rules:
  - Internal-only (no public navbar)
  - Sidebar enabled
  - Auth required
  - Role allowed: driver, super_admin

- Ensure installer is idempotent (do not duplicate page)
```

---

# ✅ KNX-TASK 03 — API: obtener órdenes activas del driver

```
KNX-TASK: Driver Active Orders — API endpoint

- Create new REST endpoint:
  GET /knx/v1/ops/driver-active-orders

- Returns ONLY:
  - Orders assigned to current driver
  - ops_status IN:
    preparing, ready, out_for_delivery, picked_up

- Must enforce:
  - driver_user_id match
  - fail-closed if no driver context

- Response must include:
  - order core fields
  - hub info (from snapshot v5 preferred)
  - delivery address (snapshot)
  - totals
  - payment status
  - fulfillment type
  - time slot (if exists)
  - status history (ordered ASC)
```

---

# ✅ KNX-TASK 04 — Shortcode: driver-active-orders

```
KNX-TASK: Driver Active Orders — Shortcode

- Create shortcode: [knx_driver_active_orders]

- Responsibilities:
  - Auth guard (driver only)
  - Inject CSS + JS (no wp_enqueue, no wp_footer)
  - Provide config object:
    - api endpoint
    - nonces
    - redirect URLs (optional)

- Render empty shell:
  - Header
  - Order container
  - Loading state
```

---

# ✅ KNX-TASK 05 — Layout: Order Detail Page (mobile-first)

```
KNX-TASK: Driver Active Orders — Layout sections

- Build layout with clear vertical sections:
  1. Header
     - Order # + date/time
     - Back button

  2. Restaurant Information
     - Name
     - Address (normalized)
     - Phone
     - Email (if exists)

  3. Client Information
     - Name
     - Address
     - Contact phone

  4. Order Items
     - Item name
     - Quantity
     - Modifiers (indented)
     - Line total

  5. Totals Summary
     - Subtotal
     - Taxes & fees
     - Delivery fee
     - Tip
     - TOTAL (highlighted)

  6. Payment Info
     - Method
     - Status

  7. Delivery Info
     - Fulfillment type
     - Time slot (if exists)

- Mobile-first
- Desktop = same flow, wider container
```

---

# ✅ KNX-TASK 06 — Order Status Timeline

```
KNX-TASK: Driver Active Orders — Status history timeline

- Render vertical timeline component
- Each status entry includes:
  - Icon
  - Status label
  - Timestamp
  - "Status from: {actor}"

- Order by created_at ASC
- Highlight current status
- Timeline is read-only
- No status mutation here (future task)
```

---

# ✅ KNX-TASK 07 — Map integration (read-only)

```
KNX-TASK: Driver Active Orders — Map section

- Embed map section below order details
- Show:
  - Pickup location (hub)
  - Delivery location

- Use existing Google Maps integration if available
- Map is read-only
- Do NOT compute routes yet
- If coordinates missing, hide map gracefully
```

---

# ✅ KNX-TASK 08 — Navigation logic (ops → active)

```
KNX-TASK: Driver Flow — Navigation rules

- When driver accepts an order:
  - Redirect to /driver-active-orders

- Driver Active Orders page:
  - Shows current assigned orders
  - If no active orders:
    - Show empty state message
    - Offer link back to Available Orders
```

---

# ✅ KNX-TASK 09 — Safety & Canon Rules

```
KNX-TASK: Driver Active Orders — Canon enforcement

- Never read cart
- Never mutate order state here
- Never recalculate totals
- Read-only view for execution
- All data sourced from:
  - orders table
  - order snapshots
  - order status history
```

---

## 🧠 RESULTADO FINAL ESPERADO

* Driver acepta orden → entra a **Active Orders**
* Ve **todo lo que necesita**, nada extra
* UI clara, profesional, móvil real
* Base perfecta para:

  * Start / Picked up / Delivered
  * Driver ETA
  * Proof of delivery
  * Multi-order routing

---

Cuando quieras, el siguiente paso puede ser:

* 🚦 **Driver status actions (Start / Picked Up / Delivered)**
* 🗺️ **Ruta en mapa con ETA**
* 🔔 **Notificaciones por estado**
* ⏱️ **SLA + timers**

Tú dime.
Esto ya es **software de verdad** 🧠🚀
