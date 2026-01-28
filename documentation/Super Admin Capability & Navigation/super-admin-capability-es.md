# Super Admin — Capacidades y Navegación (ES)

Propósito
- Rol: `super_admin` — la autoridad canónica a nivel de sistema para el plugin Kingdom Nexus. Este documento registra, sólo con evidencia auditada en el código, qué puede ver y ejecutar `super_admin` en UI y backend.

Rol y propósito (sólo evidencia)
- El rol `super_admin` aparece en `permission_callback` de rutas y en comprobaciones dentro de handlers para conceder acciones de nivel sistema. Hay rutas selladas y comprobaciones en handlers que demuestran esta exclusividad.

Endpoints exclusivos para `super_admin` (archivo → función → ruta → comportamiento)
- `inc/core/resources/knx-cities/add-city.php` → `knx_v2_add_city()`
  - `POST /knx/v2/cities/add` — registrado con `knx_rest_permission_roles(['super_admin'])`; el handler exige `super_admin` y nonce; inserta la ciudad.
- `inc/core/resources/knx-cities/delete-city.php` → `knx_v2_delete_city()`
  - `POST /knx/v2/cities/delete` — ruta sellada a `super_admin`; el handler llama `knx_rest_require_role($session, ['super_admin'])`, exige nonce, bloquea eliminación si la ciudad tiene hubs y hace soft-delete.
- `inc/core/resources/knx-cities/get-delivery-rates.php` → (getter)
  - `GET /knx/v2/cities/get-delivery-rates` — permiso a nivel de ruta requiere `super_admin`.
- `inc/core/resources/knx-cities/update-delivery-rates.php` → (actualizador)
  - `POST /knx/v2/cities/update-delivery-rates` — permiso a nivel de ruta requiere `super_admin`; el handler exige nonce y hace upsert de tarifas.
 - (endpoint legacy de force-status eliminado del repositorio)

Endpoints compartidos donde `super_admin` actúa de forma global (archivo → comportamiento)
- CRUD y eliminación de hubs
  - `inc/core/resources/knx-hubs/api-hubs-core.php`, `inc/core/resources/knx-hubs/api-delete-hub.php`, `inc/core/resources/knx-hubs/api-update-hub-identity.php` — el registro de rutas incluye `super_admin` y `manager`. El handler (`knx_api_delete_hub_v3()`) realiza deletes en cascada y no exige que el manager sea propietario del hub.
- CRUD de drivers
  - `inc/core/resources/knx-drivers/api-drivers-crud.php` — endpoints list/create/update/toggle/reset-password incluyen `super_admin` en permisos; la lista de drivers devuelve filas globales.
  - OPS assign/unassign/cancel (endpoints legacy eliminados del repositorio)
  - Listas de órdenes: algunos handlers antiguos devolvían órdenes de todos los hubs por defecto; evidencia de proxy en vivo eliminada.

Bypasses explícitos y justificación (sólo evidencia)
- `force-status` (ops): registrado y reforzado como `super_admin` únicamente — evidencia: registro de ruta con `knx_rest_permission_roles(['super_admin'])`.
- Asignación OPS: la rama `super_admin` establece `$allowed_hubs` con todos los hubs (autoridad global explícita en el handler).
- Listados de Hubs/Drivers: las consultas del servidor devuelven filas globales (sin scoping por manager), por lo que `super_admin` puede actuar sobre cualquier fila devuelta.

Diferencias frente a `manager` (sólo evidencia)
- Visibilidad UI: muchos shortcodes y elementos de navegación se muestran tanto a `manager` como a `super_admin` (ver `inc/functions/navigation-engine.php` y `inc/modules/*`).
- Aplicación servidor: algunas rutas están selladas a `super_admin` (cities add/delete, delivery-rates, ops force-status). Otras rutas incluyen `manager` en permisos, pero la rama `super_admin` en código es global (assign, drivers, delete hubs).
- Scoping manager: `GET /knx/v2/cities/get` y `POST /knx/v2/cities/operational-toggle` implementan lógica de scoping para manager cuando existe la columna `knx_hubs.manager_user_id`; múltiples endpoints no hacen validaciones de propiedad por manager en el handler.

VEREDICTO (sólo evidencia)
- CANONICAL — AUTORIDAD GLOBAL
