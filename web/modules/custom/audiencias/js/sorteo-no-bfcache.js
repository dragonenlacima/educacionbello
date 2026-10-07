/**
 * @file
 * Fuerza una recarga cuando la pantalla del sorteo se restaura desde bfcache.
 *
 * No usa Drupal.behaviors a propósito: los behaviors se ejecutan en
 * DOMContentLoaded y en respuestas AJAX, pero no en el evento pageshow, que es
 * el único momento en que se detecta la restauración desde bfcache.
 */
(function () {
  'use strict';

  window.addEventListener('pageshow', function (event) {
    // event.persisted === true significa que la página se restauró desde
    // bfcache: los conteos en pantalla pueden estar obsoletos.
    if (event.persisted) {
      window.location.reload();
    }
  });
})();
