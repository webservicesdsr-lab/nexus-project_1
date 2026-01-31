# NEXUS SHELL — UX Guidelines

Propósito
- Establecer reglas visuales y de interacción para la interfaz NEXUS (web admin y módulos), de modo que todas las pantallas compartan una apariencia y comportamiento coherentes y "serios".
- Facilitar la implementación consistente de componentes (modals, toasts, tables, botones, formularios) para desarrolladores del plugin.

Principios de diseño
- Claridad: prioridad en la legibilidad y jerarquía visual.
- Consistencia: variables CSS y clases base compartidas entre módulos.
- Densidad moderada: información compacta pero legible; uso de cards para agrupamiento.
- Accesibilidad: soporte de teclado, foco visible, contraste suficiente.
- Previsibilidad: animaciones sutiles y coherentes, sin sorpresas.

Tokens y variables CSS (recomendadas)
- Paleta principal:
  - `--nxs-primary`: #0b793a  (acciones y estados positivos)
  - `--nxs-card`: #ffffff    (fondos de cards/modals)
  - `--nxs-bg`: #f6f7f9      (fondo general en páginas administrativas)
  - `--nxs-text`: #0b1220    (texto principal)
  - `--nxs-muted`: #6b7280   (texto secundario / hints)
- Tipografía:
  - Base: 15px (1rem ≈ 16px), títulos escalados (h2 ~1.25rem en cards)
- Espacings:
  - Card padding: 20–32px
  - Gaps entre controles: 8–12px

Clases y componentes canonicos
- Wrapper / Card
  - `.knx-hubs-wrapper`, `.knx-auth-card` — contenedor central con `max-width` y `border-radius`.
  - Usar `background: var(--nxs-card)` y `box-shadow: 0 12px 30px rgba(11,18,32,0.08)`.

- Botones
  - `.knx-btn` (primario): fondo `var(--nxs-primary)`, color `var(--nxs-card)`, borde redondo (8–10px), peso `700`.
  - `.knx-btn-secondary`: fondo transparente, borde fino, color `var(--nxs-muted)`.
  - Focus: `outline: 3px solid rgba(11,121,58,0.14); outline-offset: 2px;` para todos los botones.

- Formularios
  - Inputs: bordes suaves `1px solid rgba(11,18,32,0.06)`, `border-radius: 10px`, `padding: 12px 14px`.
  - Validación: mensajes inline con color `--nxs-primary` para éxito y `#b30000` para error.

- Tables & Lists
  - Filas como tarjeta en móvil; en escritorio tabla tradicional con `th` en mayúsculas y `font-size:13px`.
  - Thumbnail antes del nombre: `.knx-hub-thumb` (46×46, object-fit:cover). Si no hay logo, mostrar placeholder neutro.
  - Badges de estado: `.status-active` / `.status-inactive` como pills (background suave y color del texto acorde).
  - Featured: icono de estrella dentro de `.knx-badge--featured` — preferir icon-only, no texto.

- Toggle / Switch
  - Tamaño ligeramente mayor que el estándar (ej. 46×26). Animación sutil de 0.12–0.3s.
  - Cuando falla una petición, revertir estado visual inmediatamente y mostrar toast de error.

- Modals
  - Estructura: overlay `.knx-modal` + `.knx-modal-content` centrado, `role="dialog"`, `aria-modal="true"`, `aria-labelledby`.
  - Estilo: fondo `var(--nxs-card)`, radio 12px, sombreado sutil. Máximo ancho recomendado 460px.
  - Comportamiento JS:
    - Al abrir: `focus()` en primer control relevante, añadir clase al body para bloquear scroll (`document.body.style.overflow='hidden'`).
    - Al cerrar: devolver focus al trigger y restaurar scroll.
    - Cerrar con `Escape` y con botones de cancel.
    - Mantener listeners idempotentes (desuscribir handlers temporales si se vuelven a crear).

- Confirm dialogs (reemplazan a `alert/confirm` nativos)
  - Usar el modal centrado (`#knxConfirmDeactivate`) con texto breve y acciones visibles: Cancel / Confirm.
  - Confirm debe exponer `id` del recurso en el closure o dataset para poder ejecutar la petición REST.

- Toasts
  - Usar sistema global `knxToast(message, type)` con variantes: `success`, `info`, `warning`, `error`.
  - No bloquear la UI. Position: `bottom-right` por defecto.
  - Animación: translateY / fade in (300–400ms), respeta `prefers-reduced-motion`.

JS Patterns recomendados
- Separación de responsabilidades: HTML render (shortcode / template) + JS de comportamiento (archivo `.js` encolado) — evitar inline scripts donde sea posible.
- Fetch / REST:
  - JSON body para endpoints REST; usar nonces para seguridad `knx_nonce` o `knx_*` según convención.
  - Manejar errores de red con catch y mostrar `knxToast('Network error', 'error')`.
- Modals y focus:
  - Al abrir: `firstInput.focus()` en un timeout corto (100–150ms) para permitir animación.
  - Trap de teclado: ESC cierra; consider `focus trap` para accesibilidad si el modal es complejo.
- Confirm fallback: si el modal no está disponible, usar `window.confirm()` como fallback, pero preferir el modal para consistencia.

Accesibilidad (A11y)
- Asegurar contraste suficiente para texto y controles.
- Todos los modales deben usar `role="dialog"` y `aria-labelledby`.
- Controles interactivos accesibles por teclado y con `:focus` visible.
- Respetar `prefers-reduced-motion`.

Iconografía y images
- Preferir SVG para iconos (estrellas, lápiz, plus); usar `aria-hidden="true"` si no aportan información adicional.
- Logos y thumbnails: almacenar URL en `logo_url` y mostrar con `alt` descriptivo.
- Si la imagen falta mostrar placeholder con fondo neutro y las iniciales del nombre como fallback si se desea.

Animaciones
- Mantener animaciones cortas y sutiles (120–350ms).
- Easing recomendado: cubic-bezier(.2,.9,.2,1) para entradas suaves.

Responsive
- Breakpoints principales: 768px (tablet), 420–480px (móvil pequeño).
- En móvil, las filas de tablas deben transformarse en tarjetas apiladas para mejorar usabilidad táctil.

Ejemplos (snippet)
- Modal HTML (canon):

```
<div id="knxConfirmDeactivate" class="knx-modal" aria-hidden="true">
  <div class="knx-modal-content" role="dialog" aria-modal="true" aria-labelledby="knxConfirmTitle">
    <h3 id="knxConfirmTitle">Deactivate Hub</h3>
    <p>Are you sure you want to deactivate this hub?</p>
    <button id="knxCancelDeactivate" class="knx-btn-secondary">Cancel</button>
    <button id="knxConfirmDeactivateBtn" class="knx-btn">Deactivate</button>
  </div>
</div>
```

- Recommended CSS variables snippet:

```
:root{
  --nxs-primary: #0b793a;
  --nxs-card: #ffffff;
  --nxs-bg: #f6f7f9;
  --nxs-text: #0b1220;
  --nxs-muted: #6b7280;
}
```

Checklist de implementación
- [ ] Usar las variables CSS en nuevos componentes.
- [ ] Reemplazar `alert()` y `confirm()` nativos por modals canon.
- [ ] Asegurar `aria-*` en modals y botones.
- [ ] Implementar `knxToast` y usarlo consistentemente.
- [ ] Probar en móvil y escritorio; revisar contrastes.

Notas finales
- Mantener cambios visuales concentrados en los archivos de estilo del módulo (`inc/modules/.../...-style.css`) y evitar tocar estilos globales del theme salvo que sea necesario.
- Si deseas, puedo generar un `knx-shell.css` central que defina las variables y componentes base para importar en todos los módulos.

---
Generado automáticamente para el proyecto NEXUS — mantener actualizado cuando cambien tokens o componentes base.
