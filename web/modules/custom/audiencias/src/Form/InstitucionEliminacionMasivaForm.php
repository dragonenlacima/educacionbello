<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Listado de instituciones con eliminación masiva, vista previa y bloqueo.
 *
 * Sustituye al listado de solo lectura por un formulario que conserva las
 * columnas y las operaciones por fila, y añade dos acciones masivas con vista
 * previa y confirmación: «Eliminar seleccionadas» (casillas) y «Eliminar todas»
 * (todas las instituciones del listado). La eliminación es PERMANENTE.
 *
 * BLOQUEO: una institución con vacantes asociadas NO se puede eliminar; primero
 * hay que quitar sus vacantes. En la confirmación, las candidatas se separan en
 * ELIMINABLES (0 vacantes) y BLOQUEADAS (>0 vacantes, con su conteo); solo se
 * borran las eliminables, dentro de una transacción.
 */
class InstitucionEliminacionMasivaForm extends FormBase {

  /**
   * Repositorio de instituciones.
   *
   * @var \Drupal\audiencias\Repository\InstitucionRepository
   */
  protected InstitucionRepository $institucionRepository;

  /**
   * Repositorio de vacantes.
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * La conexión a la base de datos (solo para envolver el borrado en una
   * transacción; el acceso a datos sigue pasando por los repositorios).
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Construye el formulario con sus dependencias.
   *
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\Core\Database\Connection $database
   *   Conexión a la base de datos para la transacción.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    InstitucionRepository $institucion_repository,
    VacanteRepository $vacante_repository,
    Connection $database,
    MessengerInterface $messenger,
  ) {
    $this->institucionRepository = $institucion_repository;
    $this->vacanteRepository = $vacante_repository;
    $this->database = $database;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.institucion_repository'),
      $container->get('audiencias.vacante_repository'),
      $container->get('database'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_institucion_eliminacion_masiva_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // El formulario tiene dos pasos: el listado y la confirmación del borrado.
    if ($form_state->get('paso') === 'confirmar') {
      return $this->construirConfirmacion($form, $form_state);
    }
    return $this->construirListado($form, $form_state);
  }

  /**
   * Construye el paso de LISTADO con casillas y acciones masivas.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   *
   * @return array
   *   El formulario con el listado.
   */
  protected function construirListado(array $form, FormStateInterface $form_state): array {
    // Cabecera asociativa: las claves enlazan cada celda con su columna y, en la
    // primera, «tableselect» inserta la casilla de selección.
    $cabecera = [
      'id' => $this->t('ID'),
      'nombre' => $this->t('Nombre'),
      'ubicacion' => $this->t('Ubicación'),
      'operaciones' => $this->t('Operaciones'),
    ];

    $opciones = [];
    foreach ($this->institucionRepository->loadAll() as $registro) {
      $opciones[$registro->institucion_id] = [
        'id' => $registro->institucion_id,
        'nombre' => $registro->nombre,
        'ubicacion' => $registro->ubicacion ?? '',
        'operaciones' => $this->celdaOperaciones($registro->institucion_id),
      ];
    }

    // Enlace de exportación a Excel, reutilizando la presentación tipo «pill»
    // de la librería de asignación. Apunta a la ruta del controlador.
    $form['#attached']['library'][] = 'audiencias/asignacion';
    $form['exportar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['audiencias-filtros']],
      'enlace' => [
        '#type' => 'link',
        '#title' => $this->t('Exportar a Excel'),
        '#url' => Url::fromRoute('audiencias.institucion.exportar'),
        '#attributes' => ['class' => ['audiencias-filtro']],
      ],
    ];

    $form['instituciones'] = [
      '#type' => 'tableselect',
      '#header' => $cabecera,
      '#options' => $opciones,
      '#empty' => $this->t('No hay instituciones registradas.'),
      '#js_select' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['eliminar_seleccionadas'] = [
      '#type' => 'submit',
      '#value' => $this->t('Eliminar seleccionadas'),
      '#submit' => ['::eliminarSeleccionadas'],
    ];
    $form['actions']['eliminar_todas'] = [
      '#type' => 'submit',
      '#value' => $this->t('Eliminar todas'),
      '#submit' => ['::eliminarTodas'],
      // No depende de la selección: no valida los valores del listado.
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Construye la celda de operaciones por fila (Editar / Borrar).
   *
   * @param int|string $institucion_id
   *   Identificador de la institución.
   *
   * @return array
   *   Render array de tipo «operations».
   */
  protected function celdaOperaciones($institucion_id): array {
    return [
      'data' => [
        '#type' => 'operations',
        '#links' => [
          'editar' => [
            'title' => $this->t('Editar'),
            'url' => Url::fromRoute('audiencias.institucion.edit', ['id' => $institucion_id]),
          ],
          'borrar' => [
            'title' => $this->t('Borrar'),
            'url' => Url::fromRoute('audiencias.institucion.delete', ['id' => $institucion_id]),
          ],
        ],
      ],
    ];
  }

  /**
   * Manejador de «Eliminar seleccionadas»: prepara la confirmación.
   *
   * Toma las instituciones marcadas (de la página visible); si no hay ninguna,
   * avisa y permanece en el listado.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function eliminarSeleccionadas(array &$form, FormStateInterface $form_state): void {
    $seleccion = array_filter((array) $form_state->getValue('instituciones'));
    $ids = array_map('intval', array_values($seleccion));

    if (empty($ids)) {
      $this->messenger->addWarning($this->t('No marcó ninguna institución para eliminar.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $form_state->set('conjunto', $ids);
    $form_state->set('paso', 'confirmar');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Manejador de «Eliminar todas»: prepara la confirmación con todas las
   * instituciones.
   *
   * Opera sobre TODAS las instituciones, sin depender de la selección ni de la
   * paginación.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function eliminarTodas(array &$form, FormStateInterface $form_state): void {
    $ids = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $ids[] = (int) $institucion->institucion_id;
    }

    if (empty($ids)) {
      $this->messenger->addWarning($this->t('No hay instituciones para eliminar.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $form_state->set('conjunto', $ids);
    $form_state->set('paso', 'confirmar');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Construye el paso de CONFIRMACIÓN separando eliminables y bloqueadas.
   *
   * Cada candidata se consulta con contarPorInstitucion(): las de 0 vacantes son
   * ELIMINABLES; las de >0 vacantes quedan BLOQUEADAS (con su conteo). Solo se
   * ofrece el botón de confirmar si hay al menos una eliminable.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   *
   * @return array
   *   El formulario en modo confirmación.
   */
  protected function construirConfirmacion(array $form, FormStateInterface $form_state): array {
    $ids = (array) $form_state->get('conjunto');

    $form['#title'] = $this->t('Confirmar eliminación de instituciones');

    // Clasifica cada candidata según tenga o no vacantes asociadas. Se recalcula
    // en cada reconstrucción (también durante el envío), de modo que el conjunto
    // de eliminables que se guarda en el estado refleja siempre los datos reales.
    $eliminables = [];
    $bloqueadas = [];
    $ids_eliminables = [];
    foreach ($ids as $id) {
      $institucion = $this->institucionRepository->load((int) $id);
      if ($institucion === NULL) {
        continue;
      }
      $vacantes = $this->vacanteRepository->contarPorInstitucion((int) $id);
      if ($vacantes > 0) {
        $bloqueadas[] = [$institucion->nombre, $institucion->ubicacion ?? '', $vacantes];
      }
      else {
        $eliminables[] = [$institucion->nombre, $institucion->ubicacion ?? ''];
        // Solo las eliminables se guardan para el borrado real.
        $ids_eliminables[] = (int) $id;
      }
    }
    $form_state->set('eliminables', $ids_eliminables);

    // Bloque de ELIMINABLES (las que se borrarán).
    $form['eliminables_titulo'] = [
      '#markup' => '<h3>' . $this->t('Se eliminarán (@n)', ['@n' => count($eliminables)]) . '</h3>',
    ];
    if ($eliminables !== []) {
      $form['eliminables_aviso'] = [
        '#markup' => '<p>' . $this->t('Estas instituciones se eliminarán de forma <strong>permanente</strong>. Esta acción no se puede deshacer.') . '</p>',
      ];
    }
    $form['eliminables'] = [
      '#type' => 'table',
      '#header' => [$this->t('Nombre'), $this->t('Ubicación')],
      '#rows' => $eliminables,
      '#empty' => $this->t('Ninguna de las instituciones seleccionadas se puede eliminar.'),
    ];

    // Bloque de BLOQUEADAS (las que NO se borran, con su motivo).
    if ($bloqueadas !== []) {
      $form['bloqueadas_titulo'] = [
        '#markup' => '<h3>' . $this->t('Bloqueadas (@n) — no se eliminarán', ['@n' => count($bloqueadas)]) . '</h3>',
      ];
      $form['bloqueadas_aviso'] = [
        '#markup' => '<p>' . $this->t('Estas instituciones tienen vacantes asociadas; elimine sus vacantes primero (por ejemplo, desde el listado de vacantes) y vuelva a intentarlo.') . '</p>',
      ];
      // Construye las filas con el motivo formateado por institución.
      $filas_bloqueadas = [];
      foreach ($bloqueadas as $bloqueada) {
        [$nombre, $ubicacion, $conteo] = $bloqueada;
        $filas_bloqueadas[] = [
          $nombre,
          $ubicacion,
          $this->t('Tiene @n vacantes asociadas; elimínalas primero.', ['@n' => $conteo]),
        ];
      }
      $form['bloqueadas'] = [
        '#type' => 'table',
        '#header' => [$this->t('Nombre'), $this->t('Ubicación'), $this->t('Motivo')],
        '#rows' => $filas_bloqueadas,
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    // Solo se ofrece confirmar si hay al menos una institución eliminable.
    if ($eliminables !== []) {
      $form['actions']['confirmar'] = [
        '#type' => 'submit',
        '#value' => $this->t('Confirmar eliminación'),
        '#button_type' => 'primary',
        '#submit' => ['::confirmarEliminacion'],
      ];
    }
    else {
      $form['sin_eliminables'] = [
        '#markup' => '<p><strong>' . $this->t('No hay ninguna institución que se pueda eliminar. No se borrará nada.') . '</strong></p>',
      ];
    }
    $form['actions']['cancelar'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancelar'),
      '#submit' => ['::cancelar'],
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * No se usa: cada botón define su propio manejador de #submit.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Intencionalmente vacío.
  }

  /**
   * Manejador de «Confirmar eliminación»: borra las eliminables en transacción.
   *
   * Re-valida que cada eliminable siga SIN vacantes (por si cambió entre la vista
   * previa y la confirmación) y la borra con InstitucionRepository->delete().
   * Cuenta eliminadas y omitidas. Si algo falla, revierte por completo.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function confirmarEliminacion(array &$form, FormStateInterface $form_state): void {
    $ids = array_unique(array_map('intval', (array) $form_state->get('eliminables')));

    $eliminadas = 0;
    $omitidas = 0;

    // Envuelve todo el borrado en una transacción: o se aplica completo o nada.
    $transaccion = $this->database->startTransaction();
    try {
      foreach ($ids as $id) {
        $id = (int) $id;
        // Re-valida: si entretanto adquirió vacantes, se omite (no se borra).
        if ($this->vacanteRepository->contarPorInstitucion($id) > 0) {
          $omitidas++;
          continue;
        }
        $eliminadas += $this->institucionRepository->delete($id);
      }
    }
    catch (\Throwable $e) {
      // Revierte explícitamente y reporta; no deja datos a medias.
      $transaccion->rollBack();
      $this->messenger->addError($this->t('No se pudo completar la eliminación: @mensaje. No se hicieron cambios.', [
        '@mensaje' => $e->getMessage(),
      ]));
      $this->limpiarEstado($form_state);
      $form_state->setRedirect('audiencias.institucion.list');
      return;
    }

    // Al salir del ámbito, la transacción se confirma automáticamente.
    unset($transaccion);

    $this->messenger->addStatus($this->t('@n instituciones eliminadas; @m omitidas (con vacantes asociadas).', [
      '@n' => $eliminadas,
      '@m' => $omitidas,
    ]));

    $this->limpiarEstado($form_state);
    $form_state->setRedirect('audiencias.institucion.list');
  }

  /**
   * Manejador de «Cancelar»: descarta el conjunto y vuelve al listado.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function cancelar(array &$form, FormStateInterface $form_state): void {
    $this->limpiarEstado($form_state);
    $this->messenger->addStatus($this->t('Operación cancelada; no se hicieron cambios.'));
    $form_state->setRedirect('audiencias.institucion.list');
  }

  /**
   * Limpia del estado del formulario los datos del flujo multi-paso.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  protected function limpiarEstado(FormStateInterface $form_state): void {
    $form_state->set('paso', NULL);
    $form_state->set('conjunto', NULL);
    $form_state->set('eliminables', NULL);
  }

}
