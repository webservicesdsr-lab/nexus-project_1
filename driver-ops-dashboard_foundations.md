Reporte técnico: Backend de Driver OPS (Available Orders + Self-Assign)

Este documento describe solo el backend del flujo Driver OPS: cómo se determina qué órdenes son “available”, cómo se autoriza el acceso y cómo se ejecuta el driver self-assign de forma transaccional y fail-closed.

1) Objetivo del backend Driver OPS

Driver OPS resuelve dos cosas, con una sola verdad (SSOT):

Listar “available orders” para un driver (pool de órdenes elegibles para “catch/accept”).

Permitir self-assign (driver se asigna a sí mismo) de forma atómica, evitando race conditions (dos drivers aceptando la misma orden).

2) Entidades y modelo de datos (tablas clave)
A) knx_orders (tabla de órdenes)

Campos relevantes (nombres típicos):

id (PK)

order_number

hub_id, city_id

fulfillment_type (p.ej. delivery)

status (p.ej. placed, confirmed, preparing, ready)

payment_method (p.ej. stripe, cash)

payment_status (p.ej. paid, pending)

driver_id (canonical: driver profile id, no WP user id)

created_at, updated_at

delivery_address, total, etc.

Canon: knx_orders.driver_id guarda el driver_profile_id (id del perfil del driver en knx_drivers), no el user_id de WP.

B) knx_driver_ops (estado operativo de órdenes para drivers)

Campos típicos:

order_id (FK lógica)

ops_status (p.ej. unassigned, assigned, …)

driver_user_id (WP user id del driver asignado)

assigned_by (WP user id; en self-assign = el mismo driver)

assigned_at

updated_at (o equivalente)

Nota importante: aquí conviven 2 IDs:

driver_user_id = WP user id (para auditoría/operación)

orders.driver_id = driver profile id (enlace canónico a “quién es el driver” dentro del dominio)

Esto es intencional mientras exista esa dualidad, pero el backend se encarga de mapear correctamente.

C) knx_drivers (perfil del driver)

Campos mínimos esperados:

id (PK) = driver_profile_id

user_id = WP user id asociado

Canon: cualquier referencia “domain driver” usa knx_drivers.id.

D) Scope del driver (ciudades/hubs permitidos)

Se resuelve mediante helper canónico:

knx_do__load_driver_scope(driver_profile_id) → devuelve:

city_ids (array)

hub_ids (array)

Fail-closed: si no hay scope, el driver no ve órdenes.

3) Identidad y autorización (driver context)

Todo Driver OPS se apoya en:

knx_get_driver_context() → provee session.user_id (WP) y, cuando está disponible, driver.id (driver_profile_id).

Regla clave:
Si no podemos resolver driver_profile_id, el backend debe fallar cerrado (403 o lista vacía con reason).

Para resolver driver_profile_id:

Preferir ctx->driver->id

Si no existe, buscar en DB: SELECT id FROM knx_drivers WHERE user_id = session.user_id

4) SSOT: Availability Engine canónico

Existe una función central (SSOT):

knx_ops_get_available_orders(array $args)
Devuelve:

orders (array)

meta (diagnóstico seguro)

4.1 Invariantes selladas (NO se pueden “relajar”)

Estas reglas son obligatorias para que algo se considere “available pool”:

fulfillment_type = 'delivery'

status IN ('placed','confirmed','preparing','ready')

Pago válido:

payment_status = 'paid' OR

payment_method = 'cash' (sin depender del payment_status)

Esto evita que la UI muestre órdenes que operativamente no deberían entrar al pool de “catch”.

4.2 Ventana temporal (recientes)

Por defecto se filtra por created_at >= now - days (ej. 7 días).

Puede omitirse (no_after_filter=1) si el caller lo permite, pero la idea es mantener el pool operativo limpio.

4.3 Scope (fail-closed)

Solo retorna órdenes dentro del scope:

hub_id IN allowed_hub_ids OR city_id IN allowed_city_ids

Si ambos arrays están vacíos → retorna 0 órdenes (fail-closed).

4.4 Filtros de “pool unassigned”

Para el driver dashboard (available orders), el engine se invoca con:

require_driver_null = true → orders.driver_id IS NULL OR 0

require_ops_unassigned = true → knx_driver_ops sin driver o ops_status unassigned (o sin row)

Esto asegura que “available” realmente signifique “nadie lo tiene”.

4.5 Meta para diagnóstico

El engine devuelve meta con:

days, after_mysql, limit, offset

allowed_city_ids, allowed_hub_ids

flags usados (require_driver_null, etc.)

ops_table_present

reason si falló cerrado por schema/scope

Importante: no se debe exponer SQL crudo en meta (no leak).

5) Endpoints Driver OPS (v2)
A) GET /wp-json/knx/v2/driver/orders/available

Función: lista el pool “available”.

Pasos internos:

permission_callback: requiere knx_get_driver_context() y session.user_id

Resolver:

driver_user_id (WP)

driver_profile_id (knx_drivers.id)

Cargar scope: knx_do__load_driver_scope(driver_profile_id)

Llamar SSOT:

require_driver_null=true

require_ops_unassigned=true

Responder:

Contrato:

{
  "success": true,
  "ok": true,
  "data": {
    "orders": [...],
    "meta": {..., "driver_user_id": 7, "driver_profile_id": 7}
  }
}

B) POST /wp-json/knx/v2/driver/orders/{id}/assign (self-assign)

Función: driver “catch” una orden.

Seguridad:

Requiere knx_nonce (CSRF protection) y driver context válido.

Transacción (atómica):

START TRANSACTION

SELECT ... FROM knx_orders WHERE id=? FOR UPDATE

Validar invariantes selladas otra vez (no confiar en el list):

delivery

status assignable

pago válido

Validar driver_id es NULL/0 (nadie lo tiene)

Validar scope (hub/city)

SELECT ... FROM knx_driver_ops WHERE order_id=? FOR UPDATE (si existe tabla)

Reglas anti-race:

Si otro driver ya asignó → 409 already_assigned

Si ya está asignada al mismo driver → idempotente (success true con already_assigned)

Upsert en knx_driver_ops:

driver_user_id = session.user_id

assigned_by = session.user_id

ops_status = 'assigned'

assigned_at = now

Update knx_orders:

driver_id = driver_profile_id (canon)

COMMIT

Respuesta típica éxito:

{
  "success": true,
  "ok": true,
  "data": {
    "assigned": true,
    "order_id": 9,
    "driver_user_id": 7,
    "driver_profile_id": 7,
    "ops_status": "assigned"
  }
}

6) Endpoint OPS (v1) como wrapper del mismo SSOT

Rutas:

GET /wp-json/knx/v1/ops/driver-available-orders

GET /wp-json/knx/v1/drivers/available-orders (alias)

Contrato legacy:

{ "ok": true, "orders": [...], "meta": {...} }


Reglas:

Permission: driver | manager | super_admin

Usa driver context para scope

Puede permitir include_assigned=1 solo a manager/super_admin (visibilidad), pero sin romper invariantes.

7) Por qué antes veías “0 orders” aunque existían en DB

Con este backend, cuando ves orders: [], casi siempre es por una de estas:

Scope vacío o mal resuelto

allowed_city_ids / allowed_hub_ids vacíos → fail-closed.

Driver profile id no resuelto

driver_profile_id no mapea desde user_id.

No pasan invariantes selladas

fulfillment_type distinto a delivery

payment_status != paid y payment_method != cash

status fuera de placed/confirmed/preparing/ready

Filtro de ventana temporal

created_at fuera de after_mysql

Tu evidencia (DB muestra driver_id NULL y knx_driver_ops vacío) indica que el bloqueo NO es asignación previa, sino típicamente scope / mapping / invariantes. Por eso el backend ahora devuelve meta diagnóstica robusta.

8) Reglas de edición segura (para futuras IAs/chats)

Si vas a modificar Driver OPS backend, respeta esto:

Nunca dupliques SQL de availability en endpoints → todo pasa por SSOT.

Invariantes “available pool” no se discuten: delivery + statuses + payment valid.

Self-assign siempre:

transacción

row locks (FOR UPDATE)

idempotencia

respuesta 409 en colisión

knx_orders.driver_id = driver_profile_id (canon)

knx_driver_ops.driver_user_id = WP user id (operación/auditoría)

Fail-closed en scope y en mapping de driver_profile_id.


-----