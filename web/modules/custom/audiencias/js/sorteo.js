/**
 * @file
 * Overlay del sorteo: cubre la petición REAL en la que el servidor genera y
 * aplica el emparejamiento.
 *
 * El envío es un POST normal, SIN AJAX: no hay estado de formulario que
 * persistir entre peticiones (esa complejidad fue la causa del defecto en que
 * lo aplicado no coincidía con lo mostrado). El overlay se abre y desaparece
 * solo cuando el navegador pinta la página con el resultado ya aplicado; no hay
 * espera artificial: dura exactamente lo que dura la operación.
 *
 * El overlay se abre en el evento «submit» del formulario, NO en «mousedown» ni
 * en «click» del botón. Es la única posición segura: el overlay ocupa toda la
 * pantalla (position: fixed; inset: 0), así que abrirlo en «mousedown» lo
 * interponía bajo el puntero antes del «mouseup», el navegador no llegaba a
 * generar el «click» y el formulario NUNCA se enviaba. En «submit» el navegador
 * ya decidió enviar, de modo que el overlay no puede interferir con esa decisión.
 */
(function (Drupal, once) {

  'use strict';

  /**
   * Crea (si no existe) el overlay del sorteo y lo devuelve.
   *
   * Vive en el <body>, así que sobrevive a cualquier repintado del formulario.
   *
   * @return {HTMLElement}
   *   El elemento del overlay.
   */
  function asegurarOverlay() {
    var overlay = document.getElementById('audiencias-sorteo-overlay');
    if (overlay) {
      return overlay;
    }
    overlay = document.createElement('div');
    overlay.id = 'audiencias-sorteo-overlay';
    overlay.className = 'audiencias-sorteo-overlay is-hidden';
    overlay.innerHTML =
      '<div class="audiencias-sorteo-overlay__tarjeta">' +
      '<div class="audiencias-sorteo-overlay__spinner"></div>' +
      '<p class="audiencias-sorteo-overlay__texto">' + Drupal.t('Realizando sorteo…') + '</p>' +
      '<div class="audiencias-sorteo-overlay__pista">' +
      '<div class="audiencias-sorteo-overlay__barra"></div>' +
      '</div>' +
      '</div>';
    document.body.appendChild(overlay);
    return overlay;
  }

  /**
   * Muestra el overlay y REINICIA la animación de la barra (de 0 % a 100 %).
   */
  function mostrarOverlay() {
    var overlay = asegurarOverlay();
    var barra = overlay.querySelector('.audiencias-sorteo-overlay__barra');

    overlay.classList.remove('is-hidden');

    if (barra) {
      // Reinicia la animación: quita la clase, fuerza un reflow y la repone, así
      // la barra arranca de 0 % en cada apertura.
      barra.classList.remove('is-animando');
      // Lectura que fuerza el reflow (necesaria para reiniciar la animación).
      void barra.offsetWidth;
      barra.classList.add('is-animando');
    }
  }

  /**
   * Oculta el overlay.
   */
  function ocultarOverlay() {
    var overlay = document.getElementById('audiencias-sorteo-overlay');
    if (overlay) {
      overlay.classList.add('is-hidden');
    }
  }

  // Se enlaza una sola vez gracias a once() sobre el propio documento.
  once('aud-sorteo-pageshow', 'html').forEach(function () {
    // Con envío normal, al volver con el botón «atrás» el navegador puede
    // restaurar la página desde su caché (bfcache) con el overlay abierto: se
    // cierra siempre que la página se muestra.
    window.addEventListener('pageshow', ocultarOverlay);
  });

  /**
   * Abre el overlay cuando el formulario DEL SORTEO se envía.
   */
  Drupal.behaviors.audienciasSorteoOverlay = {
    attach: function (context) {
      once('aud-sorteo-submit', 'form', context).forEach(function (formulario) {
        // Solo el formulario del sorteo: es el único que contiene el botón
        // disparador. Los demás formularios de la página quedan intactos.
        if (!formulario.querySelector('.js-sorteo-trigger')) {
          return;
        }

        formulario.addEventListener('submit', function () {
          // NUNCA se llama a preventDefault(): el envío sigue su curso pase lo
          // que pase. El try/catch garantiza que un fallo del overlay tampoco
          // pueda impedirlo; sin JS, el formulario se envía igual.
          try {
            mostrarOverlay();
          }
          catch (e) {
            // El sorteo tiene prioridad sobre la animación: se ignora.
          }
        });
      });
    }
  };

})(Drupal, once);
