<?php

declare(strict_types=1);

namespace Drupal\audiencias\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Marca «no-store» la respuesta de la pantalla de sorteo.
 *
 * La pantalla del sorteo muestra conteos que cambian con cada asignación y es
 * privada de quien la consulta. Con «no-store» ninguna caché HTTP intermedia
 * (proxy inverso, CDN, acelerador) puede guardarla ni volver a servirla. La
 * garantía queda así en el código del módulo, con independencia de la
 * configuración del servidor web, que puede cambiar o no existir en otro
 * entorno de despliegue.
 *
 * Esta cabecera NO evita el bfcache ni el efecto del botón «atrás»: desde 2025
 * Chrome conserva en bfcache las páginas servidas con «Cache-Control:
 * no-store» y solo las desaloja si cambia una cookie HTTP-only del sitio, cosa
 * que aplicar un sorteo no hace. De la restauración desde bfcache se encarga el
 * JS de la librería «audiencias/sorteo_no_bfcache», que recarga la pantalla al
 * detectarla en el evento «pageshow».
 *
 * No se usa «#attached[http_header]» porque FinishResponseSubscriber del core
 * reescribe «Cache-Control» en las respuestas no cacheables y pisaría el valor.
 */
class NoStoreSorteoSubscriber implements EventSubscriberInterface {

  /**
   * Rutas cuya respuesta no debe almacenarse en ninguna caché HTTP.
   *
   * Se deja como constante de clase para poder ampliar la lista más adelante
   * sin tocar la lógica del oyente.
   */
  protected const RUTAS_SIN_ALMACENAR = [
    'audiencias.asignacion.sorteo',
  ];

  /**
   * Fija «Cache-Control: no-store» en las rutas declaradas.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   Evento de respuesta del kernel.
   */
  public function onRespond(ResponseEvent $event): void {
    // Solo la petición principal: las subpeticiones (bloques, placeholders)
    // no producen la respuesta que se envía al cliente.
    if (!$event->isMainRequest()) {
      return;
    }

    $ruta = $event->getRequest()->attributes->get('_route');
    if (!in_array($ruta, self::RUTAS_SIN_ALMACENAR, TRUE)) {
      return;
    }

    $event->getResponse()->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // FinishResponseSubscriber del core se suscribe a KernelEvents::RESPONSE
    // con la prioridad por defecto, es decir 0 (ver en Drupal 11.4.5
    // core/lib/Drupal/Core/EventSubscriber/FinishResponseSubscriber.php, donde
    // registra ['onRespond'] sin prioridad explícita). Como reescribe
    // «Cache-Control» en toda respuesta no cacheable, hay que ejecutarse
    // DESPUÉS que él: cualquier prioridad negativa lo consigue.
    //
    // Se elige -1024 para quedar también por debajo del resto de oyentes del
    // core que tocan la respuesta en esta versión (los más bajos, aparte de
    // BigPipe, están en -512), con margen suficiente para que un cambio de
    // prioridades en futuras versiones del core no nos adelante.
    return [
      KernelEvents::RESPONSE => [['onRespond', -1024]],
    ];
  }

}
