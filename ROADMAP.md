# ROADMAP MAESTRO — KINGDOM NEXUS

Versión: ROADMAP_MAESTRO_KNX_MODEL-D_vFinal  
Fecha: 2026-01-27  
Objetivo: Tener una brújula ejecutable (estado real + dependencias + smoke tests) sin mezclar “manual” ni documentación profunda (eso vive en `NEXUS.md`).

---

***🟦 LEGEND / STATUS SYSTEM***
- ✅ SEALED → contrato estable (solo cambios aditivos/versionados)
- 🟡 NEAR-SEAL → existe y funciona, pero falta DoD/smoke/edge-cases para sellar
- 🟠 IN PROGRESS → en construcción o frágil / no auditado aún
- ⛔ BLOCKED → no se puede avanzar sin prerequisito
- 🧊 FROZEN → intencionalmente pausado / fuera de alcance por ahora

***🟦 ITEM INDICATORS (sin `[ ]`)***
- ⬜ Pendiente / no auditado / falta evidencia
- ✅ Verificado con evidencia (paths reales)
- 🟡 Existe pero requiere smoke / auditoría / evidencia adicional
- ❌ No existe / fue removido
- ⛔ Bloqueado por prerequisito explícito

---

***🟦 SEALED RULES (CONTRACT)***
- Evidence-first: nada se declara ✅ SEALED sin anchors de evidencia (paths reales + comportamiento observado).  
- WordPress = contenedor. La autoridad y enforcement viven en backend del plugin (handlers + DB).  
- Fail-Closed: si falta permiso/nonce/sesión/ownership → bloqueo duro (backend).  
- Orders = snapshots inmutables (no recalcular órdenes existentes).  
- REST pasa por wrapper/guard estándar.  
- Prohibido `wp_footer` (regla técnica del proyecto).  
- Roles canónicos: `super_admin` (nunca `admin`), `manager`, `driver`, `customer`, `guest`.

---

***🟦 EVIDENCE STANDARD***
- “Evidence anchors” = rutas de archivos que prueban: registro de ruta, permission_callback, validaciones en handler, escrituras DB.  
- Si falta auditoría, se deja: `⬜ PATH_REQUIRED: ...`  
- Si existe pero falta prueba/lectura del archivo: usar `🟡` (near-seal a nivel ítem), no ✅.

---

***🟦 NOW / NEXT / SEALED (SNAPSHOT RÁPIDO)***
- **NOW (lo que estamos tocando hoy):**
  - 🟠 PHASE 16 — Dashboards & Reporting (frontend/UX + coherencia)
  - 🟠 PHASE 13 — Ops Dispatch (filtros canon + sellado delivered)
  - 🟠 PHASE 14 — Drivers Runtime (MVP dashboard + report flow)

- **NEXT (bloqueantes inmediatos):**
  - 🟠 PHASE 10–11 — Coverage/Distance + Delivery Snapshot HARD (si aún falta)
  - 🟠 PHASE 12 — Payments readiness (separación test/live si aplica)

- **SEALED (no tocar sin versionado):**
  - ⬜ TBD (se llenará cuando auditoría confirme SEALED con evidencia)

---

***🟦 PHASE INDEX (MODEL D ORDER)***
1. PHASE 0 — Core Infrastructure & Guard Rails  
2. PHASE 1 — Auth & Session  
3. PHASE 2 — Roles & Capability Model  
4. PHASE 3 — Cities  
5. PHASE 4 — Hubs  
6. PHASE 5 — Menu System (items/modifiers/categories)  
7. PHASE 6 — Cart & Navigation (SSOT / no deep-link checkout)  
8. PHASE 7 — Orders Foundation (quote → create-order, ACID, idempotency, snapshots)  
9. PHASE 8 — Checkout Orchestrator UX  
10. PHASE 9 — Addresses (/my-addresses + selected_address_id SSOT)  
11. PHASE 10 — Coverage & Distance (SSOT)  
12. PHASE 11 — Delivery Fee Engine + Delivery Snapshot  
13. PHASE 12 — Payments Foundation (Stripe: intent + webhook + polling)  
14. PHASE 13 — Ops Dispatch (knx_driver_ops SSOT, ops dashboard)  
15. PHASE 14 — Drivers Runtime (my orders, status updates, availability, reports)  
16. PHASE 15 — Customer Order Experience (list/status/timeline)  
17. PHASE 16 — Dashboards & Reporting (ops history, sales/admin analytics)  
18. PHASE 17 — Notifications (FROZEN)  
19. PHASE 18 — LATER / Experiments  

---

## 📌 PHASE SUMMARY (alto nivel)
| Phase | Domain | Status | Realidad (1 línea) |
|------:|--------|--------|--------------------|
| 0 | Core Infrastructure & Guard Rails | 🟡 | existe en tree; falta audit formal |
| 1 | Auth & Session | 🟡 | existe en modules/auth + helpers; falta audit formal |
| 2 | Roles & Capability Model | 🟡 | hay guards + navegación; falta inventario sellado |
| 3 | Cities | 🟡 | CRUD/resources existen; falta sellado por evidencia |
| 4 | Hubs | 🟡 | CRUD/UI existen; scoping requiere revisión |
| 5 | Menu System | 🟡 | items/modifiers APIs existen; falta mapear SSOT |
| 6 | Cart & Navigation | 🟡 | cart drawer + nav existen; falta checklist de bypass |
| 7 | Orders Foundation | 🟡 | handlers en core/knx-orders existen; falta DoD formal |
| 8 | Checkout Orchestrator UX | 🟡 | UI + scripts existen; “funciona con issues” |
| 9 | Addresses | 🟡 | /my-addresses assets existen; falta sellado formal |
| 10 | Coverage & Distance | 🟡 | engines existen; falta confirmar integración real |
| 11 | Delivery Fee + Snapshot | 🟡 | engines existen; falta confirmar snapshot HARD |
| 12 | Payments Foundation | 🟡 | endpoints + helpers existen; falta checklist live/test |
| 13 | Ops Dispatch | 🟠 | existe y se usa; falta sellar filtros/terminal rules |
| 14 | Drivers Runtime | 🟠 | endpoints existen; falta dashboard MVP + report flow |
| 15 | Customer Order Experience | 🟡 | orders list/get existen; falta confirmar UI actual |
| 16 | Dashboards & Reporting | 🟠 | hay ops/orders + ops/history; falta consolidación |
| 17 | Notifications | 🧊 | congelado |
| 18 | LATER / Experiments | 🧊 | congelado |

---

# 🟡 PHASE 0 — Core Infrastructure & Guard Rails (FOUNDATION)

***🟦 PURPOSE***
- Wrapper/guard/response estándar para REST + fail-closed base.

***🟦 REALITY TODAY***
- Existe infraestructura REST en `inc/core/rest/*` y se usa en resources; falta auditoría formal “sellado”.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/rest/knx-rest-wrapper.php`
- 🟡 `inc/core/rest/knx-rest-guard.php`
- 🟡 `inc/core/rest/knx-rest-response.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- No endpoints sueltos fuera del patrón wrapper/guard.
- Fail-closed por defecto (sin sesión/nonce/role → bloqueo).

***🟦 SMOKE TESTS***
- ⬜ Llamar endpoint protegido sin sesión → debe bloquear (sin leaks).
- ⬜ Llamar write endpoint sin nonce → debe bloquear.
- ⬜ Confirmar que permission_callback existe en registros relevantes.

---

# 🟡 PHASE 1 — Auth & Session (CANONICAL GATES)

***🟦 PURPOSE***
- Sesión como autoridad para acciones de customer/driver/ops; bloqueo duro sin sesión.

***🟦 REALITY TODAY***
- Auth module + helpers existen; falta inventario de “dónde se exige sesión” y “dónde se oculta existencia (404)”.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/modules/auth/auth-handler.php`
- 🟡 `inc/modules/auth/auth-redirects.php`
- 🟡 `inc/modules/auth/auth-shortcode.php`
- 🟡 `inc/functions/helpers.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Guest puede explorar/cart, pero checkout/create-order debe estar server-gated según tu canon.
- Post-login canonical: `/cart`.

***🟦 SMOKE TESTS***
- ⬜ Acceso a endpoints de órdenes sin sesión → bloqueo.
- ⬜ Login redirect → termina en `/cart` (no deep-link a `/checkout`).
- ⬜ Ownership de order: no revelar existencia a no-owner.

---

# 🟡 PHASE 2 — Roles & Capability Model (ROUTE GUARDS + NAV)

***🟦 PURPOSE***
- Permisos por rol en rutas + visibilidad en navegación (sin otorgar autoridad por UI).

***🟦 REALITY TODAY***
- Hay navigation engine y guard patterns; falta inventario de rutas `super_admin` selladas.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/functions/navigation-engine.php`
- 🟡 `inc/core/rest/knx-rest-guard.php`
- 🟡 (múltiples resources en `inc/core/resources/*`)

***🟦 CONTRACTS (DO NOT BREAK)***
- Rol canónico: `super_admin` (nunca `admin`).
- UI visible ≠ permiso real (backend manda).

***🟦 SMOKE TESTS***
- ⬜ Endpoint `super_admin` llamado por manager → bloqueo.
- ⬜ Navegación oculta links no permitidos.
- ⬜ Permission_callback consistente en writes.

---

# 🟡 PHASE 3 — Cities (CRUD + OPERATIONAL TOGGLE + DELIVERY RATES)

***🟦 PURPOSE***
- CRUD ciudades + toggles operativos + tarifas (inputs para totals/delivery).

***🟦 REALITY TODAY***
- Resources de cities existen; falta sellar “scoping manager” y “acciones selladas”.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/resources/knx-cities/get-cities.php`
- 🟡 `inc/core/resources/knx-cities/post-operational-toggle.php`
- 🟡 `inc/core/resources/knx-cities/add-city.php`
- 🟡 `inc/core/resources/knx-cities/delete-city.php`
- 🟡 `inc/core/resources/knx-cities/get-delivery-rates.php`
- 🟡 `inc/core/resources/knx-cities/update-delivery-rates.php`
- 🟡 UI: `inc/modules/knx-cities/knx-cities-shortcode.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Delivery rates no pueden ser “inventadas” si falta data crítica (fail-closed).

***🟦 SMOKE TESTS***
- ⬜ Manager sin ownership → toggle operacional bloquea.
- ⬜ Add/delete city por no-super_admin → bloquea.
- ⬜ Rates missing → totals/checkout debe bloquear (no fallback peligroso).

---

# 🟡 PHASE 4 — Hubs (CRUD + IDENTITY/LOCATION/HOURS/LOGO/SETTINGS)

***🟦 PURPOSE***
- Hubs como unidad operativa: identidad, ubicación, horarios, settings, logo.

***🟦 REALITY TODAY***
- CRUD/UI de hubs existe; falta sellar scoping/ownership (especialmente manager).

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/resources/knx-hubs/api-hubs-core.php`
- 🟡 `inc/core/resources/knx-hubs/api-hubs.php`
- 🟡 `inc/core/resources/knx-hubs/api-get-hub.php`
- 🟡 `inc/core/resources/knx-hubs/api-delete-hub.php`
- 🟡 `inc/core/resources/knx-hubs/api-update-settings.php`
- 🟡 `inc/core/resources/knx-hubs/api-update-hub-slug.php`
- 🟡 `inc/core/resources/knx-hubs/api-upload-logo.php`
- 🟡 UI: `inc/modules/hubs/hubs-shortcode.php`
- 🟡 UI edit: `inc/modules/hubs/edit-hub-template.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Ubicación/horarios afectan availability y operación (no fail-open).

***🟦 SMOKE TESTS***
- ⬜ Manager no-owner intentando edit/delete hub → bloquea (o documentar si aún no existe).
- ⬜ Delete hub hace cascade correcto (sin orphan rows).

---

# 🟡 PHASE 5 — Menu System (ITEMS / MODIFIERS / CATEGORIES)

***🟦 PURPOSE***
- Menú por hub: items/modifiers/categories; alimenta cart/quote.

***🟦 REALITY TODAY***
- Hay resources y módulos; falta mapear SSOT de render y validación.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/resources/knx-items/api-menu-read.php`
- 🟡 `inc/core/resources/knx-items/api-hub-items.php`
- 🟡 `inc/core/resources/knx-items/api-modifiers.php`
- 🟡 UI/admin: `inc/modules/items/*`
- 🟡 UI/categories: `inc/modules/hub-categories/*`

***🟦 CONTRACTS (DO NOT BREAK)***
- Create-order usa snapshot: no depender de “menú mutable”.

***🟦 SMOKE TESTS***
- ⬜ Menu read para hub inválido → fail-closed.
- ⬜ Item sin modifiers opcionales no rompe render (según issue conocido previo).

---

# 🟡 PHASE 6 — Cart & Navigation (SSOT / NO DEEP-LINK CHECKOUT)

***🟦 PURPOSE***
- Cart como gate canónico; navegación sin bypass a checkout.

***🟦 REALITY TODAY***
- Existe cart drawer + cart page; falta checklist formal de bypass.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/modules/cart/cart-drawer.js`
- 🟡 `inc/modules/cart/cart-drawer.css`
- 🟡 `inc/public/cart/cart-shortcode.php`
- 🟡 `inc/public/cart/cart-page.js`
- 🟡 `inc/public/navigation/*`
- 🟡 `inc/functions/navigation-engine.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Post-login SIEMPRE `/cart`.
- No deep-link a `/checkout` como entrada canónica.

***🟦 SMOKE TESTS***
- ⬜ Abrir `/checkout` sin sesión → redirige/bloquea según canon.
- ⬜ Guest: puede armar cart pero no crear order (server gate).
- ⬜ Drawer SSOT: no duplicar lógicas.

---

# 🟡 PHASE 7 — Orders Foundation (QUOTE → CREATE-ORDER, ACID, IDEMPOTENCY, SNAPSHOTS)

***🟦 PURPOSE***
- Crear órdenes reales con snapshot inmutable; status controlado.

***🟦 REALITY TODAY***
- Existe `inc/core/knx-orders/*`; falta sellado formal por auditoría + smoke tests.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/knx-orders/api-quote-totals.php`
- 🟡 `inc/core/knx-orders/api-create-order-mvp.php`
- 🟡 `inc/core/knx-orders/api-get-order.php`
- 🟡 `inc/core/knx-orders/api-list-orders.php`
- 🟡 `inc/core/knx-orders/api-update-order-status.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Una vez creada: NO recalcular.
- Fail-closed si falta data crítica.

***🟦 SMOKE TESTS***
- ⬜ Doble submit → no crea duplicado (idempotency window).
- ⬜ Falla parcial (ej: cupón) → rollback completo (ACID).
- ⬜ Non-owner no puede leer order.

---

# 🟡 PHASE 8 — Checkout Orchestrator UX (SINGLE PAGE + GATES + NO LEAKS)

***🟦 PURPOSE***
- Checkout orquesta: prevalidate → quote → create-order; UX sin leaks técnicos.

***🟦 REALITY TODAY***
- “Funciona con issues” (según tu estado). Falta pulido + consistencia de contracts.

***🟦 EVIDENCE ANCHORS***
- ✅ `inc/core/resources/knx-checkout/api-checkout-prevalidate.php`
- ✅ `inc/core/resources/knx-checkout/api-checkout-quote.php`
- ✅ `inc/public/checkout/checkout-shortcode.php`
- ✅ `inc/public/checkout/checkout-script.js`
- ✅ `inc/public/checkout/checkout-payment-flow.js`
- 🟡 `inc/public/checkout/checkout-style.css`

***🟦 CONTRACTS (DO NOT BREAK)***
- Gates UX normales deberían responder 200 + flags (evitar 4xx “normales”).
- Checkout no crea address (solo consume).

***🟦 SMOKE TESTS***
- ⬜ Checkout nunca rompe render aunque falte algo.
- ⬜ Gates: missing address/coords/out-of-zone se muestran sin leaks.
- ⬜ Create-order solo ocurre si backend lo permite.

---

# 🟡 PHASE 9 — Addresses (CANON `/my-addresses` + selected_address_id SSOT)

***🟦 PURPOSE***
- CRUD addresses + selección canónica para delivery.

***🟦 REALITY TODAY***
- Assets existen; falta confirmar sellado formal (SSOT selected_address_id + back to cart).

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/resources/knx-addresses/api-addresses.php`
- 🟡 `inc/functions/address-helper.php`
- 🟡 UI: `inc/public/addresses/my-addresses-shortcode.php`
- 🟡 UI: `inc/public/addresses/my-addresses-script.js`
- 🟡 UI: `inc/public/addresses/my-addresses-style.css`

***🟦 CONTRACTS (DO NOT BREAK)***
- `/cart` no debe depender de addresses (solo CTA/estado).

***🟦 SMOKE TESTS***
- ⬜ Crear/edit/delete address funciona y no filtra a otros users.
- ⬜ Select address persiste (cookie/session según canon).
- ⬜ Back to cart siempre `/cart`.

---

# 🟡 PHASE 10 — Coverage & Distance (SSOT)

***🟦 PURPOSE***
- Determinar can_deliver + reason_code + zone_id + distance determinística server-side.

***🟦 REALITY TODAY***
- Engines existen; falta verificar integración real en quote/create-order.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/functions/coverage-engine.php`
- 🟡 `inc/functions/distance-calculator.php`
- 🟡 `inc/functions/geo-engine.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- No confiar en frontend para distance/coverage.

***🟦 SMOKE TESTS***
- ⬜ Dirección fuera de zona → backend bloquea (no UI-only).
- ⬜ Distance determinística (mismo input → mismo output).

---

# 🟡 PHASE 11 — Delivery Fee Engine + Delivery Snapshot

***🟦 PURPOSE***
- Delivery fee como SSOT backend + snapshot en create-order.

***🟦 REALITY TODAY***
- Engines existen; falta confirmar “snapshot HARD” en create-order.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/functions/delivery-fee-engine.php`
- 🟡 `inc/functions/totals-engine.php`
- 🟡 `inc/core/resources/knx-cities/get-delivery-rates.php`
- 🟡 `inc/core/resources/knx-cities/update-delivery-rates.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Si falta regla crítica → fail-closed (no cobrar mal).

***🟦 SMOKE TESTS***
- ⬜ Delivery fee congelado: cambiar rates después NO afecta órdenes existentes.
- ⬜ Missing rates → quote/create-order bloquea correctamente.

---

# 🟡 PHASE 12 — Payments Foundation (Stripe: intent + webhook + polling)

***🟦 PURPOSE***
- PaymentIntent server-side + webhook + status polling.

***🟦 REALITY TODAY***
- Payments resources existen; falta checklist formal de test/live readiness (si aplica).

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/resources/knx-payments/api-create-payment-intent.php`
- 🟡 `inc/core/resources/knx-payments/api-payment-status.php`
- 🟡 `inc/core/resources/knx-payments/api-payment-webhook.php`
- 🟡 `inc/core/resources/knx-payments/stripe-authority.php`
- 🟡 `inc/functions/stripe-helpers.php`
- 🟡 `inc/functions/stripe-logger.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Webhook idempotente.
- No reconfirmar desde frontend sin backend check.

***🟦 SMOKE TESTS***
- ⬜ PaymentIntent se crea solo desde snapshot (order totals).
- ⬜ Webhook firma inválida → 4xx.
- ⬜ Evento desconocido → 2xx ignore (no romper).
- ⬜ Polling refleja estado final sin loops infinitos.

---

# 🟠 PHASE 13 — Ops Dispatch (knx_driver_ops SSOT, ops dashboard)

***🟦 PURPOSE***
- Pipeline operativo: asignación + estado delivery por `knx_driver_ops`.

***🟦 REALITY TODAY***
- Existe OPS dashboard y engine ops-sync; falta sellar reglas terminales + filtros canon.

***🟦 EVIDENCE ANCHORS***
- ✅ `inc/core/functions/knx-driver-ops-sync.php`
- ✅ `inc/core/resources/knx-ops/api-ops-orders.php`
- ✅ `inc/core/resources/knx-ops/api-ops-orders-live.php`
 - 🟡 UI: (legacy OPS UI removed)

***🟦 CONTRACTS (DO NOT BREAK)***
- `knx_orders` = verdad canónica (dinero/snapshot/status).
- `knx_driver_ops` = verdad operativa (assign/ops_status).
- Si ops_status terminal (ej: delivered) → no reassign/unassign.

***🟦 SMOKE TESTS***
- ⬜ /ops muestra solo activas (definir terminal set).
- ⬜ delivered → bloquea mutaciones.
- ⬜ manager scoping por hub/city (si aplica) se respeta.

---

# 🟠 PHASE 14 — Drivers Runtime (my orders, status updates, availability, reports)

***🟦 PURPOSE***
- Driver ve sus órdenes activas + puede avanzar estados operativos + reportar issues.

***🟦 REALITY TODAY***
- Endpoints existen; falta consolidar dashboard MVP + report flow sin riesgo.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/resources/knx-drivers/api-driver-my-orders.php`
- 🟡 `inc/core/resources/knx-drivers/api-driver-update-status.php`
- 🟡 `inc/core/resources/knx-drivers/api-driver-availability.php`
- 🟡 `inc/core/resources/knx-drivers/api-drivers-crud.php`
- 🟡 UI: `inc/modules/drivers/drivers-shortcode.php`
- 🟡 UI: `inc/modules/drivers/drivers-script.js`
- 🟡 Admin UI: `inc/modules/drivers/drivers-admin-shortcode.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Driver no toca dinero.
- Report flow no debe hacer refunds automáticos.

***🟦 SMOKE TESTS***
- ⬜ Driver solo ve órdenes asignadas a su user_id.
- ⬜ Update-status valida transición (no saltos inválidos).
- ⬜ Availability toggle no rompe ops.

---

# 🟡 PHASE 15 — Customer Order Experience (list/status/timeline)

***🟦 PURPOSE***
- Customer ve órdenes activas + historial + timeline (read-only contracts).

***🟦 REALITY TODAY***
- Infra de orders existe; falta consolidar y referenciar la UI actual exacta si ya está en branch.

***🟦 EVIDENCE ANCHORS***
- 🟡 `inc/core/knx-orders/api-list-orders.php`
- 🟡 `inc/core/knx-orders/api-get-order.php`
- 🟡 `inc/public/profile/profile-shortcode.php` (perfil existe; orders UI puede vivir aparte)
- 🟡 `inc/public/profile/profile-script.js`

***🟦 CONTRACTS (DO NOT BREAK)***
- Ownership estricto: customer solo ve lo suyo.
- Timeline se deriva de snapshots + status history (sin recalcular totals).

***🟦 SMOKE TESTS***
- ⬜ Customer list → solo sus órdenes.
- ⬜ Order detail → no leaks de PII ajena.
- ⬜ Terminal vs active separados correctamente.

---

# 🟠 PHASE 16 — Dashboards & Reporting (ops history, sales/admin analytics)

***🟦 PURPOSE***
- Vistas consolidadas: historia de ops, reporting, dashboards admin.

***🟦 REALITY TODAY***
- Existen módulos de orders live + ops + history; falta consolidación final y naming/documentación en NEXUS.

***🟦 EVIDENCE ANCHORS***
 - 🟡 Live Orders UI: (legacy removed)
- 🟡 `inc/core/resources/knx-ops/api-ops-orders.php`
- 🟡 `inc/core/resources/knx-ops/api-ops-orders-live.php` (evidence: live proxy for ops/orders)
- 🟡 Admin base: `inc/modules/admin/admin-menu.php`, `inc/modules/admin/admin-users.php`

***🟦 CONTRACTS (DO NOT BREAK)***
- Reporting nunca recalcula órdenes; solo lee snapshots + history.

***🟦 SMOKE TESTS***
- ⬜ History paginado no rompe performance.
- ⬜ Read-only garantizado (sin writes accidentales).
- ⬜ Mobile/desktop UI consistente (sin leaks técnicos).

---

# 🧊 PHASE 17 — Notifications (email / sms / push)

***🟦 PURPOSE***
- Notificaciones operativas (order events) cuando core esté sólido.

***🟦 REALITY TODAY***
- Congelado.

***🟦 EVIDENCE ANCHORS***
- ⬜ PATH_REQUIRED: (notification hooks/files si existen)

***🟦 CONTRACTS (DO NOT BREAK)***
- No introducir push hasta que WebView/apps integradas estén listas (si esa es la regla vigente).

***🟦 SMOKE TESTS***
- ⬜ TBD

---

# 🧊 PHASE 18 — LATER / Experiments

***🟦 PURPOSE***
- Time slots, webviews, experimentos.

***🟦 REALITY TODAY***
- Congelado.

***🟦 EVIDENCE ANCHORS***
- ⬜ PATH_REQUIRED: (experiments)

***🟦 CONTRACTS (DO NOT BREAK)***
- Nada experimental puede romper contratos sellados.

***🟦 SMOKE TESTS***
- ⬜ TBD

---

***🟦 ACCEPTANCE CHECKLIST (ROADMAP)***
- ✅ Sin `[ ]` (solo emojis)
- ✅ Headers/subheaders en azul (`***🟦 ...***`)
- ✅ Mantiene Model D order (0 → 18)
- ✅ Cada fase tiene: PURPOSE / REALITY TODAY / EVIDENCE / CONTRACTS / SMOKE TESTS
- ✅ No se afirma “SEALED” sin auditoría/evidencia completa
