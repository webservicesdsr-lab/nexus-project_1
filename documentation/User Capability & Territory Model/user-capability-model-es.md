# Modelo de Capacidades de Usuario y Territorio (ES)

Propósito
- Documento maestro que unifica los documentos de roles auditados. Todas las declaraciones son sólo evidencia extraída del código auditado.

Declaración canónica
- CANONICAL WITH LIMITS: El sistema define una autoridad canónica global (`super_admin`) con poderes sellados para acciones de sistema; los roles administrativos locales (`manager`) están diseñados para gestión territorial, pero en varias partes del código el acceso es global o el scoping no está completo.

Qué está sellado a `super_admin` (sólo evidencia)
- Endpoints sellados (registro de ruta o comprobación en handler):
  - Gestión de ciudades add/delete: `inc/core/resources/knx-cities/add-city.php`, `inc/core/resources/knx-cities/delete-city.php` (`POST /knx/v2/cities/add`, `POST /knx/v2/cities/delete`) — registrados como `super_admin` únicamente.
  - Tarifas por ciudad get/update: `inc/core/resources/knx-cities/get-delivery-rates.php`, `inc/core/resources/knx-cities/update-delivery-rates.php` — permiso `super_admin` a nivel de ruta.
  - OPS `force-status`: (endpoint histórico eliminado del repositorio)

Dónde `super_admin` actúa de forma global (sólo evidencia)
- El código implementa semántica global para `super_admin` en varios endpoints:
  - Asignación OPS: la rama `super_admin` establece `$allowed_hubs` con todos los hubs.
  - Listados de hubs/drivers: consultas servidor devuelven filas globales (sin filtro por manager), permitiendo a `super_admin` operar en todas ellas.
  - Handlers de delete/update de hubs realizan deletes en cascada para todas las entidades relacionadas (por ejemplo `knx_api_delete_hub_v3()`).

Manager vs Super Admin (sólo evidencia)
- `manager` es el rol con intención local y ciertos endpoints implementan scoping cuando existen columnas de migración (`knx_hubs.manager_user_id`).
- Aun así, muchos endpoints incluyen `manager` en la lista de permisos pero el handler no valida propiedad por manager y existen comentarios de código marcando comportamiento `TEMPORARY` que permiten todos los hubs.

Resumen del modelo de territorio (sólo evidencia)
- Territorio sellado: CRUD de ciudades y operaciones de tarifas están selladas a `super_admin`.
- Territorio intentado: `manager` debería operar en hubs/ciudades asignadas cuando `knx_hubs.manager_user_id` está presente — evidencia: endpoints de ciudades hacen joins y comprobaciones.
- Territorio implementado: varios endpoints devuelven datos globales o permiten acciones de manager sin validaciones de propiedad; `super_admin` tiene rutas de código que son explícitamente globales.

VEREDICTO (sólo evidencia)
- CANONICAL WITH LIMITS — `super_admin` es la autoridad canónica; `manager` es el rol local pretendido, pero el scoping no está completo en el código.
