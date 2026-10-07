# Tarea 05: el overlay del sorteo impide enviar el formulario

## Contexto

Módulo `audiencias` (Drupal 11), en `web/modules/custom/audiencias/`. Sesión
ejecutada desde la raíz del proyecto (la que contiene `composer.json` y `web/`).
Las rutas escritas como `js/...` o `css/...` son relativas a la raíz del módulo.

La raíz **no es un repositorio git**: `audiencias` es un repo independiente. Git
se ejecuta dentro del módulo. No hagas commit.

La tarea 04 ya está aplicada y **verificada en producción**: el sorteo genera y
aplica en una sola petición, y lo mostrado coincide con lo guardado. No toques el
PHP salvo lo que se indique explícitamente aquí.

## El defecto

Con JavaScript activo, pulsar «Realizar sorteo» **no envía el formulario**.
Diagnóstico ya hecho, no lo repitas:

- Pestaña Red: no se produce ningún POST. Solo cargas de CSS y JS, todas 200.
- Consola: sin errores.
- Watchdog: sin registro alguno; la petición nunca llega al servidor.
- **Con JavaScript deshabilitado, el sorteo funciona correctamente** y aplica bien.

### Causa

En `js/sorteo.js`, el overlay se abre en el evento **`mousedown`**:

```js
document.addEventListener('mousedown', function (evento) {
  if (objetivo.closest('.js-sorteo-trigger')) {
    mostrarOverlay();
  }
});
```

Y en `css/sorteo.css` el overlay es `position: fixed; inset: 0; z-index: 1000`,
es decir, cubre toda la pantalla.

Secuencia: al pulsar, `mousedown` abre el overlay sobre el botón; cuando llega el
`mouseup`, el puntero ya está sobre el overlay y no sobre el botón, así que el
navegador **nunca genera el evento `click`** y el formulario no se envía.

Antes no ocurría porque el envío iba por AJAX de Drupal, disparado por otra vía.
La tarea 04 pasó a envío normal y el submit ahora depende del clic nativo.

## La solución

Mueve la apertura del overlay al evento **`submit` del formulario**, no a
`mousedown` ni a `click` del botón. Es el punto correcto: el navegador ya decidió
enviar, así que el overlay no puede interferir con esa decisión.

Sugerencia de implementación (adáptala al estilo del archivo):

```js
once('aud-sorteo-submit', 'form', context).forEach(function (formulario) {
  formulario.addEventListener('submit', function () {
    if (formulario.querySelector('.js-sorteo-trigger')) {
      mostrarOverlay();
    }
  });
});
```

Requisitos:

1. **El envío debe completarse siempre.** Ninguna ruta del JS puede llamar a
   `preventDefault()` ni bloquear el submit. Si el JS falla, el formulario debe
   enviarse igual — como ocurre hoy con JS deshabilitado.
2. **Solo el formulario del sorteo.** No enganches el listener a cualquier
   formulario de la página administrativa.
3. **El overlay debe cerrarse al volver con «atrás».** Con envío normal, el
   navegador puede restaurar la página desde la caché de retroceso (bfcache) con
   el overlay abierto. Usa el evento `pageshow` para ocultarlo.
4. **Elimina el código muerto** de la versión anterior: la constante de duración
   mínima, el revelado diferido con `setTimeout`, `window._audSorteoInicio` y el
   comportamiento de `Drupal.behaviors` que revelaba el resultado tras 5 s. Ya no
   hay nada que revelar: el resultado llega en la respuesta y se muestra directo.
5. **Verifica la clase `is-hidden` en el PHP.** El contenedor
   `resultado_wrapper` de `SorteoVacantesForm::buildForm()` ya no debe llevarla,
   porque nadie la va a quitar. Confirma que la tarea 04 la eliminó; si quedó,
   quítala. Es el **único** cambio de PHP autorizado en esta tarea.

### Sobre htmx

La página carga `htmx.min.js` (viene del tema `bellodev`). No es la causa del
defecto —el diagnóstico apunta al `mousedown`—, pero **verifica** que htmx no
esté interceptando el envío de este formulario. Si lo hiciera, repórtalo y no lo
resuelvas por tu cuenta: tocar el tema está fuera de alcance.

### Sobre la duración mínima

La versión anterior garantizaba 5 segundos de overlay. Con envío normal eso no se
puede sostener sin retrasar artificialmente la respuesta. **No lo reintroduzcas.**
El overlay debe durar lo que dure la petición real.

## Restricciones

- No modifiques `SorteoVacantesForm.php` salvo el punto 5.
- No reintroduzcas AJAX ni `#ajax` en el formulario del sorteo: fue la causa del
  defecto original de la tarea 04.
- No toques el esquema, las rutas ni los permisos.
- No corrijas el menú invertido de `audiencias.links.menu.yml`: es otra tarea.
- No salgas del directorio del módulo. No toques `bellodev` ni
  `create_public_official`.
- Sin PHPUnit ni linters: no inventes comandos de test.
- Comentarios y textos en español, con el estilo del archivo.

## Entregable

1. Archivos modificados, una línea por cambio.
2. Por qué el nuevo punto de enganche no puede impedir el envío.
3. Qué pasa si el usuario pulsa dos veces seguidas el botón.
4. Qué pasa al volver con «atrás» tras un sorteo.
5. Si htmx interfiere o no, y en qué te basas.

Recuerda: tras modificar el JS hace falta `drush cr` para renovar el agregado.
Indícalo pero **no lo ejecutes**: toca producción.

No hagas commit. Déjame revisar el diff.
