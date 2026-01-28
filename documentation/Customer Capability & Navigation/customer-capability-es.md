# Customer — Capacidades (ES)

Propósito
- Rol: `customer` — comprador autenticado que puede crear y gestionar sus propias órdenes y perfil. Documento basado sólo en evidencia auditada que describe capacidades y limitaciones del customer.

Qué puede hacer `customer` (sólo evidencia)
- Crear órdenes: `inc/core/knx-orders/api-create-order-mvp.php` — existen handlers para crear órdenes (evidencia de endpoints de creación de órdenes accesibles por sesiones autenticadas).
- Cotizaciones y totales: `inc/core/knx-orders/api-quote-totals.php` — endpoints para calcular cotizaciones/totales forman parte del flujo de creación de órdenes.
- Ver sus órdenes: `inc/core/knx-orders/api-get-order.php` y `inc/core/knx-orders/api-list-orders.php` — los handlers verifican sesión y rol; customers pueden recuperar órdenes asociadas a su sesión/usuario.

Qué no puede acceder `customer` (sólo evidencia)
- Los customers no obtienen capacidades administrativas; handlers admin requieren roles admin o permisos de sesión (ver uso de `knx_rest_permission_roles(...)` en endpoints admin). Los endpoints admin se registran por separado e incluyen `manager` y/o `super_admin`.

Notas sobre scoping y privacidad (sólo evidencia)
- Los handlers de órdenes usan sesión y comprobaciones de rol; algunos handlers devuelven `404 'order-not-found'` para viewers no autorizados para evitar filtrar existencia.

VEREDICTO (sólo evidencia)
- Los customers pueden crear y gestionar sus propias órdenes y ver sus datos de pedido; las acciones administrativas se rigen por permisos de roles en las rutas y handlers.
