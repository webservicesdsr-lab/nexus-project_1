# Guía de la Plataforma — Drivers 🚗

Bienvenido. Esta guía es sobre **cómo usar la plataforma**, no sobre cómo hacer entregas — eso ya lo sabes.

---

## Pantalla 1 — Órdenes Disponibles (`/driver-live-orders`)

Aquí aparecen todas las órdenes que el sistema tiene para ti en este momento.

**Lo que ves en cada tarjeta:**
- Número de orden (pastilla azul)
- Nombre del restaurante/hub
- Dirección de entrega
- Total de la orden
- Método: **Delivery** o **Customer Pickup** (el cliente recoge en el local — en estos casos no vas a ningún lado)

**Botones disponibles:**
- **`Accept`** — Toma la orden. Una vez que presionas esto, la orden pasa a ser tuya.
- **`See Map`** — Abre el mapa de la dirección de entrega antes de aceptar. Solo aparece en órdenes de delivery.

> ⚠️ La lista se actualiza sola cada ciertos segundos. No necesitas refrescar la página.

---

## Pantalla 2 — Mis Órdenes Activas (`/driver-active-orders`)

Aquí están todas las órdenes que ya aceptaste y están en proceso.

**Cada tarjeta muestra:**
- Número de orden
- Restaurante
- Estado actual (ver tabla de estados abajo)

**Toca cualquier tarjeta** para abrir el detalle completo de esa orden.

---

## Pantalla 3 — Detalle de Orden (`/driver-view-order`)

Esta es la pantalla donde trabajas una orden. Tiene todo lo que necesitas:

**Sección izquierda — Acciones:**
- **`Change Status`** — Botón azul principal. Avanza el estado de la orden al siguiente paso. Aparece mientras la orden no esté cerrada.
- **`Release Order`** — Botón rojo. Libera la orden para que otro driver la tome. Desaparece una vez que ya recogiste el pedido (`Picked Up`).

**Sección de información:**
- Datos del restaurante, dirección, logo
- Botón directo a **Google Maps** o **Waze** para navegar
- Productos del pedido
- Datos de pago
- Historial de cambios de estado

**Chat:**
- Panel de chat directo con el cliente. Se desactiva cuando la orden se cierra (completada o cancelada).

---

## Cómo avanzar el estado — Botón `Change Status`

Cuando tocas **`Change Status`**, aparece un modal que te muestra el **siguiente paso disponible**. Solo puedes avanzar en orden, no saltar pasos.

### Flujo para órdenes de Delivery:

| Estado actual en pantalla | Qué presionas en el modal | Lo que pasa |
|---|---|---|
| **Order Created** | `Accepted by Driver` | Confirmas que tomaste la orden |
| **Accepted by Driver** | `Accepted by Hub` | Confirmas que el restaurante fue notificado |
| **Accepted by Hub** | `Preparing` | El restaurante está preparando |
| **Preparing** | `Prepared` | El pedido está listo para recoger |
| **Prepared** | `Picked Up` | Ya tienes el pedido en mano |
| **Picked Up** | `Completed` | Entrega realizada. Orden cerrada ✅ |

### Flujo para órdenes de Customer Pickup (cliente recoge en local):

| Estado actual en pantalla | Qué presionas en el modal | Lo que pasa |
|---|---|---|
| **Order Created** | `Accepted by Driver` | Confirmas que coordinaste la orden |
| **Accepted by Driver** | `Accepted by Hub` | Restaurante notificado |
| **Accepted by Hub** | `Preparing` | Preparando |
| **Preparing** | `Prepared` / `Ready for Pickup` | Listo para que el cliente recoja. El sistema cierra automáticamente el siguiente paso. |
| — | `Completed` | Orden cerrada ✅ |

> En Customer Pickup el sistema hace un salto automático de `Prepared` → `Picked Up` → `Completed` en dos clics.

---

## Estados que verás en las tarjetas

| Lo que dice la pantalla | Qué significa |
|---|---|
| **Order Created** | Orden nueva, nadie la ha tocado |
| **Accepted by Driver** | Ya está en tu poder |
| **Accepted by Restaurant** | El local confirmó |
| **Preparing** | Están cocinando |
| **Prepared** | Listo para recoger |
| **Picked Up** | Ya lo tienes en mano |
| **Completed** | Entregado y cerrado |
| **Cancelled** | Cancelada — no se puede reabrir |

---

## Navegación inferior

La barra de navegación en la parte de abajo siempre está visible:

- **Orders** — Lista de órdenes activas y disponibles
- **Profile** — Tu perfil

La sección donde estás aparece resaltada.

---

## Situaciones comunes

**¿Ya acepté una orden pero no puedo hacerla?**
Toca **`Release Order`** (botón rojo en el detalle). La orden vuelve al pool de disponibles para otro driver.
— Solo puedes hacer esto ANTES de marcarla como `Picked Up`.

**¿Una orden dice `Cancelled`?**
No hay nada que hacer. Aparecerá en tu historial pero no se puede reactivar.

**¿El botón `Change Status` no aparece?**
La orden ya está en estado terminal (`Completed` o `Cancelled`). No hay más pasos.

---

---

# 📸 Para crear el PDF con screenshots

**Herramienta recomendada: [Canva](https://canva.com)**
Busca la plantilla **"Employee Handbook"** o **"User Manual"** — tiene el formato ideal para esto.

## Screenshots que necesitas tomar

Abre la plataforma y captura estas pantallas en orden:

1. [ ] `/driver-live-orders` — lista de órdenes disponibles con al menos una tarjeta visible
2. [ ] `/driver-live-orders` — modal de mapa (toca "See Map" en una orden de delivery)
3. [ ] `/driver-active-orders` — lista de órdenes activas
4. [ ] `/driver-view-order` — detalle completo de una orden (con los botones visibles)
5. [ ] `/driver-view-order` — modal de "Change Status" abierto
6. [ ] `/driver-view-order` — sección del mapa con el botón de navegación
7. [ ] `/driver-view-order` — sección de chat

## Cómo capturar la pantalla

**iPhone:** Botón lateral + Subir volumen simultáneamente

**Android:** Botón encendido + Bajar volumen simultáneamente

**Mac (para la computadora):** `Cmd + Shift + 4` → selecciona el área
