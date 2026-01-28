# Driver — Capacidades y Modelo OPS (ES)

Propósito
- Rol: `driver` — actor operativo que recibe asignaciones desde OPS y realiza entregas. Documento basado sólo en evidencia auditada que describe capacidades de driver, modelo OPS y presencia de contexto de driver en el código.

Qué pueden hacer los drivers / Interacciones OPS (sólo evidencia)
- Asignación y pipeline OPS:
  - `inc/core/resources/knx-ops/api-ops-orders.php` — endpoints OPS incluyen `assign`, `unassign`, `cancel` y `list`. La UI cliente legacy que llamaba a estos endpoints ha sido removida; los endpoints backend permanecen como evidencia.
- CRUD y listado de drivers:
  - `inc/core/resources/knx-drivers/api-drivers-crud.php` — endpoint de lista de drivers devuelve filas globales; drivers pueden ser creados/actualizados/toggleados/reset-password por endpoints admin (permisos incluyen `super_admin` y `manager`).

Helper de contexto de driver (sólo evidencia)
- `inc/functions/helpers.php` define `knx_get_acting_driver_context($as_driver_id = 0)`. La función existe en el código (definición encontrada). La búsqueda del repositorio mostró la definición, sin call-sites adicionales en los resultados auditados.

Dónde se controla la autoridad sobre drivers (sólo evidencia)
- Los drivers son destinatarios de acciones OPS. La lógica de asignación aplica `allowed_hubs` para la rama manager; la rama `super_admin` establece `allowed_hubs` con todos los hubs (autoridad global explícita).

Puntos de integración en UI (sólo evidencia)
- La UI legacy de OPS cargaba drivers desde `endpoints.drivers` y mostraba un selector por orden; esa UI fue removida como parte de PHASE 13.CLEAN.
- UI de administración de drivers (`inc/modules/drivers/drivers-admin-shortcode.php` + script) expone endpoints de create/update/toggle/reset.

VEREDICTO (sólo evidencia)
- Los drivers operan dentro del pipeline OPS; las asignaciones/desasignaciones son realizadas por roles administrativos y `super_admin` posee autoridad global en las ramas de código.
