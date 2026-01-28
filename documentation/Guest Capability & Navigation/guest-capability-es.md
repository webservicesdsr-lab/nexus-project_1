# Guest — Capacidades (ES)

Propósito
- Visitante no autenticado (Guest). Documento que registra, sólo con evidencia auditada, qué pueden ver o hacer los visitantes no autenticados.

Qué pueden ver los guests (sólo evidencia)
- Listado público de hubs: `inc/core/resources/knx-hubs/api-hubs-core.php` expone una ruta pública `GET` que devuelve hubs activos (el servidor filtra por `status = 'active'`). Esto alimenta shortcodes y páginas en `public/`.
- Páginas públicas y shortcodes: la estructura del repositorio contiene `public/` con páginas (por ejemplo `public/home`, `public/explore-hubs`, `public/menu`) para navegación pública.

Qué no pueden hacer (sólo evidencia)
- Mutaciones y acciones administrativas requieren sesión autenticada y roles apropiados; muchas rutas de escritura exigen `knx_nonce` y comprobaciones `knx_rest_permission_roles(...)`. La creación de órdenes y acciones de carrito requieren sesión.

VEREDICTO (sólo evidencia)
- Guests pueden navegar contenido público (hubs activos, páginas públicas). Las acciones de mutación requieren autenticación y roles.
