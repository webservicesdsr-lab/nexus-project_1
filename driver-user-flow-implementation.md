# 🚚 Driver User Flow Implementation

**Status:** CANONICAL · SEALED

Este documento define **de forma definitiva** el flujo de usuario del DRIVER dentro del sistema Nexus / KNX.

No es un plan de tareas.
No es un backlog.
No es una propuesta.

👉 Es la **fuente canónica** para diseño, backend, frontend y futuras extensiones.

---

## 🎯 OBJETIVO DEL DRIVER FLOW

El driver necesita **ejecutar órdenes reales en tiempo real**, con:

* Información confiable
* Cero ambigüedad
* UX clara y operativa

Este flujo **no es administrativo**, **no es histórico**, **no es analítico**.

Es un **flujo operativo**.

---

## 🧠 PRINCIPIOS CANÓNICOS

Estas reglas aplican a TODO el driver flow.

### 1️⃣ Snapshot v5 ONLY

* El driver **solo** consume órdenes con snapshot v5 válido.
* Snapshots legacy:

  * ❌ No se renderizan
  * ❌ No se adaptan
  * ❌ No se fallbackean

> Legacy = datos sin contrato → fuera del flujo operativo.

---

### 2️⃣ Fail-Closed Siempre

Si ocurre cualquiera de los siguientes casos:

* Orden no encontrada
* Orden no asignada al driver
* Snapshot inválido o incompleto
* Estado fuera del set permitido

➡️ La UI **no renderiza la orden**.
➡️ El driver es redirigido a una vista segura.

Nunca se muestran datos parciales.

---

### 3️⃣ Read-Only por Definición

En el flujo actual:

* ❌ No se muta estado
* ❌ No se recalculan totales
* ❌ No se lee cart

Todo es:

* snapshot
* order state
* status history

---

## 🗺️ ARQUITECTURA DE NAVEGACIÓN DEL DRIVER

El driver opera sobre **4 vistas principales**, organizadas en un bottom navbar.

### TAB 1 — Quick Menu

**Propósito:**

* Navegación rápida
* Accesos directos

No tiene lógica operativa.

---

### TAB 2 — Driver OPS (Discovery)

**Ruta:** `/driver-ops`

**Propósito:**

* Descubrir órdenes NEW (unassigned)

**Características:**

* Feed en tiempo real
* Órdenes recientes
* Botón principal: **Accept**

**Resultado:**

* Al aceptar una orden → redirect inmediato a Order Detail

---

### TAB 3 — Live Orders (Tracking)

**Ruta:** `/driver-live-orders`

**Propósito:**

* Ver TODAS las órdenes activas del driver

**Estados incluidos:**

* assigned
* accepted
* preparing
* ready
* out_for_delivery
* picked_up

**Características:**

* Lista compacta
* Collapse por orden
* Búsqueda local
* Paginación

**Acción principal:**

* **View Order** → navega a Order Detail

---

### TAB 4 — Profile

**Propósito:**

* Perfil del driver
* Configuración personal

No participa en el flujo operativo.

---

## 📄 ORDER DETAIL — `/driver-active-orders/{id}`

### ❗ Definición Crítica

Esta vista **NO es una lista**.

Es el **detalle completo de UNA SOLA orden**.

---

### Accesos Permitidos

1. Desde Driver OPS (TAB 2)

   * Accept → redirect automático

2. Desde Live Orders (TAB 3)

   * View Order → navegación directa

No existe acceso manual ni navegación libre.

---

## 🧩 CONTENIDO DE ORDER DETAIL

La vista está compuesta por **secciones verticales claras**, mobile-first.

### 1️⃣ Header

* Order ID
* Fecha / hora
* Botón Back (contextual)

---

### 2️⃣ Restaurant Information

* Nombre
* Dirección normalizada
* Teléfono (si existe)

---

### 3️⃣ Client Information

* Nombre
* Dirección o Pickup label
* Teléfono

---

### 4️⃣ Order Items

* Nombre del item
* Cantidad
* Modifiers (indentados)
* Line total

---

### 5️⃣ Totals Summary

* Subtotal
* Fees / taxes
* Delivery fee
* Tip
* **TOTAL** (destacado)

---

### 6️⃣ Payment Info

* Método
* Estado del pago

---

### 7️⃣ Delivery Info (Condicional)

* Fulfillment type
* Time slot (si existe en snapshot v5)

No se asume que estos campos existan.

---

### 8️⃣ Status Timeline

* Timeline vertical
* Estados ordenados ASC
* Timestamp
* Actor del cambio
* Estado actual resaltado

Read-only.

---

### 9️⃣ Map (Condicional)

* Pickup location
* Delivery location

Solo si hay coordenadas válidas.

---

## 🔄 FLUJO COMPLETO DEL DRIVER

```text
Driver OPS (TAB 2)
   ↓
Accept Order
   ↓
/driver-active-orders?id
   ↓
Execute Order
   ↓
Back
   ↓
Live Orders (TAB 3)
```

---

## 🔙 BACK NAVIGATION — REGLA CANÓNICA

El botón Back en Order Detail:

1. Respeta el contexto de entrada
2. Prioriza:

   * `?from=` param
   * `document.referrer`
   * fallback: `/driver-live-orders`

Nunca hardcodea una sola ruta.

---

## 🚫 LO QUE ESTE FLUJO NO HACE

* ❌ No muestra historial
* ❌ No muestra órdenes legacy
* ❌ No muta estado (por ahora)
* ❌ No calcula rutas
* ❌ No estima ETA

Todo eso pertenece a **fases futuras**.

---

## 🧱 BASE PARA FUTURAS FASES

Este diseño permite agregar sin romper:

* 🚦 Driver status actions
* 🗺️ Ruta con ETA
* 🔔 Notificaciones
* ⏱️ SLA y timers

Porque:

* Snapshot v5 es estable
* El contrato es claro
* El flujo es predecible

---

## 🏁 CONCLUSIÓN

El Driver User Flow:

* Está **cerrado conceptualmente**
* Es **operativo, no experimental**
* Rechaza legacy conscientemente
* Prioriza claridad sobre compatibilidad

Este documento es la **referencia final**.

Cualquier implementación futura debe:

* alinearse aquí
* o justificar explícitamente por qué no.

🚀
