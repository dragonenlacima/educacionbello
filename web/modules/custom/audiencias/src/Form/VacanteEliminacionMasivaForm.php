<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Listado de vacantes con eliminación masiva y confirmación.
 *
 * Página dedicada (pestaña «Eliminar vacantes») que conserva las columnas y las
 * operaciones por fila del listado y añade dos acciones masivas con vista previa
 * y confirmación: «Eliminar seleccionadas» (casillas) y «Eliminar todas las no
 * asignadas». La eliminación es PERMANENTE.
 *
 * Regla de negocio: SOLO se pueden eliminar las vacantes SIN asignar. Las
 * vacantes asignadas quedan protegidas: su casilla aparece deshabilitada, se
 * excluyen de «Eliminar todas» y, si llegaran a colarse, se omiten al confirmar.
 * En todos los casos se informa al usuario cuántas se excluyeron. Para eliminar
 * una vacante asignada hay que reversar primero su asignación.
 *
 * La vacante es una tabla HOJA (ninguna otra la referencia), por lo que el
 * borrado no deja huérfanos ni requiere cascada. Todo el borrado se ejecuta
 * dentro de una transacción.
 */
class VacanteEliminacionMasivaForm extends FormBase {

  /**
   * Repositorio de vacantes.
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * Repositorio de instituciones.
   *
   * @var \Drupal\audiencias\Repository\InstitucionRepository
   */
  protected InstitucionRepository $institucionRepository;

  /**
   * Repositorio de cargos.
   *
   * @var \Drupal\audiencias\Repository\CargoRepository
   */
  protected CargoRepository $cargoRepository;

  /**
   * Repositorio de funcionarios.
   *
   * @var \Drupal\audiencias\Repository\FuncionarioRepository
   */
  protected FuncionarioRepository $funcionarioRepository;

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
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\Core\Database\Connection $database
   *   Conexión a la base de datos para la transacción.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    VacanteRepository $vacante_repository,
    InstitucionRepository $institucion_repository,
    CargoRepository $cargo_repository,
    FuncionarioRepository $funcionario_repository,
    Connection $database,
    MessengerInterface $messenger,
  ) {
    $this->vacanteRepository = $vacante_repository;
    $this->institucionRepository = $institucion_repository;
    $this->cargoRepository = $cargo_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->database = $database;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.vacante_repository'),
      $container->get('audiencias.institucion_repository'),
      $container->get('audiencias.cargo_repository'),
      $container->get('audiencias.funcionario_repository'),
      $container->get('database'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_vacante_eliminacion_masiva_form';
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
    // Mapas auxiliares (registro completo de institución para reutilizar la
    // ubicación sin consultas adicionales).
    $instituciones = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $instituciones[$institucion->institucion_id] = $institucion;
    }
    $cargos = [];
    foreach ($this->cargoRepository->loadAll() as $cargo) {
      $cargos[$cargo->cargo_id] = $cargo->nombre;
    }
    $funcionarios = [];
    foreach ($this->funcionarioRepository->loadAll() as $funcionario) {
      $funcionarios[$funcionario->funcionario_id] = $funcionario->nombre;
    }

    // Cabecera asociativa: las claves enlazan cada celda con su columna y, en la
    // primera, «tableselect» inserta la casilla de selección.
    $cabecera = [
      'id' => $this->t('ID'),
      'institucion' => $this->t('Institución'),
      'nombre' => $this->t('Nombre'),
      'estado' => $this->t('Estado'),
      'cargo' => $this->t('Cargo'),
      'tipo_vacante' => $this->t('Tipo de vacante'),
      'funcionario' => $this->t('Funcionario'),
      'operaciones' => $this->t('Operaciones'),
    ];

    $opciones = [];
    foreach ($this->vacanteRepository->loadAll() as $registro) {
      $asignada = !empty($registro->funcionario_id);
      $funcionario = $asignada
        ? ($funcionarios[$registro->funcionario_id] ?? $this->t('(desconocido)'))
        : $this->t('Sin asignar');

      // Solo las vacantes SIN asignar son seleccionables: las asignadas quedan
      // protegidas con la casilla deshabilitada (hay que reversarlas primero).
      $opciones[$registro->vacante_id] = [
        'id' => $registro->vacante_id,
        'institucion' => $this->celdaInstitucion($instituciones[$registro->institucion_id] ?? NULL),
        'nombre' => $registro->nombre,
        'estado' => $registro->estado ? $this->t('Activa') : $this->t('Inactiva'),
        'cargo' => $cargos[$registro->cargo_id] ?? $this->t('(desconocido)'),
        'tipo_vacante' => $registro->tipo_vacante ?? '',
        'funcionario' => $funcionario,
        'operaciones' => $this->celdaOperaciones($registro->vacante_id),
        '#disabled' => $asignada,
      ];
    }

    // Nota de ayuda: deja clara la regla antes de la tabla.
    $form['ayuda'] = [
      '#markup' => '<p>' . $this->t('Solo se pueden eliminar las vacantes <strong>sin asignar</strong>. Las vacantes asignadas aparecen con la casilla deshabilitada; para eliminarlas, reversa primero su asignación.') . '</p>',
    ];

    $form['vacantes'] = [
      '#type' => 'tableselect',
      '#header' => $cabecera,
      '#options' => $opciones,
      '#empty' => $this->t('No hay vacantes registradas.'),
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
      '#value' => $this->t('Eliminar todas las no asignadas'),
      '#submit' => ['::eliminarTodas'],
      // No depende de la selección: no valida los valores del listado.
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Construye la celda «Institución» (nombre y, si existe, ubicación tenue).
   *
   * @param object|null $institucion
   *   Registro de la institución o NULL si no se encuentra.
   *
   * @return array|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   Render array de la celda, o un texto si la institución es desconocida.
   */
  protected function celdaInstitucion(?object $institucion) {
    if ($institucion === NULL) {
      return $this->t('(desconocida)');
    }
    return [
      'data' => [
        '#type' => 'inline_template',
        '#template' => '<div>{{ nombre }}{% if ubicacion %}<br><small>{{ ubicacion }}</small>{% endif %}</div>',
        '#context' => [
          'nombre' => $institucion->nombre,
          'ubicacion' => $institucion->ubicacion ?? '',
        ],
      ],
    ];
  }

  /**
   * Construye la celda de operaciones por fila (Editar / Borrar).
   *
   * @param int|string $vacante_id
   *   Identificador de la vacante.
   *
   * @return array
   *   Render array de tipo «operations».
   */
  protected function celdaOperaciones($vacante_id): array {
    $destino = Url::fromRoute('audiencias.vacante.eliminar_masivo')->toString();
    return [
      'data' => [
        '#type' => 'operations',
        '#links' => [
          'editar' => [
            'title' => $this->t('Editar'),
            'url' => Url::fromRoute('audiencias.vacante.edit', ['id' => $vacante_id]),
          ],
          'borrar' => [
            'title' => $this->t('Borrar'),
            'url' => Url::fromRoute('audiencias.vacante.delete', ['id' => $vacante_id], ['query' => ['destination' => $destino]]),
          ],
        ],
      ],
    ];
  }

  /**
   * Manejador de «Eliminar seleccionadas»: prepara la confirmación.
   *
   * Toma las vacantes marcadas; si no hay ninguna, avisa y permanece en el
   * listado.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function eliminarSeleccionadas(array &$form, FormStateInterface $form_state): void {
    $seleccion = array_filter((array) $form_state->getValue('vacantes'));
    $ids = array_map('intval', array_values($seleccion));

    if (empty($ids)) {
      $this->messenger->addWarning($this->t('No marcó ninguna vacante para eliminar.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    // Solo se conservan las vacantes SIN asignar; las asignadas se excluyen.
    $borrables = $this->filtrarBorrables($ids);
    $bloqueadas = count($ids) - count($borrables);

    if (empty($borrables)) {
      $this->messenger->addWarning($this->t('Las vacantes marcadas están asignadas y no se pueden eliminar. Reversa primero su asignación.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    if ($bloqueadas > 0) {
      $this->messenger->addWarning($this->t('Se excluyeron @b vacantes asignadas (no se pueden eliminar); continuará con @n sin asignar.', [
        '@b' => $bloqueadas,
        '@n' => count($borrables),
      ]));
    }

    $form_state->set('conjunto', $borrables);
    $form_state->set('paso', 'confirmar');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Manejador de «Eliminar todas»: prepara la confirmación con todas las
   * vacantes.
   *
   * Opera sobre TODAS las vacantes (ambos cargos), sin depender de la selección
   * ni de la paginación.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function eliminarTodas(array &$form, FormStateInterface $form_state): void {
    // Solo las vacantes SIN asignar; las asignadas se excluyen y se cuentan.
    $borrables = [];
    $bloqueadas = 0;
    foreach ($this->vacanteRepository->loadAll() as $vacante) {
      if (empty($vacante->funcionario_id)) {
        $borrables[] = (int) $vacante->vacante_id;
      }
      else {
        $bloqueadas++;
      }
    }

    if (empty($borrables)) {
      $this->messenger->addWarning($bloqueadas > 0
        ? $this->t('Todas las vacantes están asignadas; ninguna se puede eliminar. Reversa primero sus asignaciones.')
        : $this->t('No hay vacantes para eliminar.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    if ($bloqueadas > 0) {
      $this->messenger->addWarning($this->t('Se excluyeron @b vacantes asignadas (no se pueden eliminar); continuará con @n sin asignar.', [
        '@b' => $bloqueadas,
        '@n' => count($borrables),
      ]));
    }

    $form_state->set('conjunto', $borrables);
    $form_state->set('paso', 'confirmar');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Filtra una lista de identificadores dejando solo las vacantes BORRABLES.
   *
   * Una vacante es borrable únicamente si existe y NO está asignada a ningún
   * funcionario (funcionario_id vacío).
   *
   * @param int[] $ids
   *   Identificadores de vacante candidatos.
   *
   * @return int[]
   *   Subconjunto de identificadores que se pueden eliminar.
   */
  protected function filtrarBorrables(array $ids): array {
    $borrables = [];
    foreach ($ids as $id) {
      $vacante = $this->vacanteRepository->load((int) $id);
      if ($vacante !== NULL && empty($vacante->funcionario_id)) {
        $borrables[] = (int) $id;
      }
    }
    return $borrables;
  }

  /**
   * Construye el paso de CONFIRMACIÓN con el conteo y el detalle a eliminar.
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

    $form['#title'] = $this->t('Confirmar eliminación de vacantes');

    $instituciones = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $instituciones[$institucion->institucion_id] = $institucion->nombre;
    }
    $cargos = [];
    foreach ($this->cargoRepository->loadAll() as $cargo) {
      $cargos[$cargo->cargo_id] = $cargo->nombre;
    }

    // El conjunto ya viene filtrado a vacantes SIN asignar; aquí solo se arma el
    // detalle: Institución — Vacante — Cargo.
    $filas = [];
    foreach ($ids as $id) {
      $vacante = $this->vacanteRepository->load((int) $id);
      if ($vacante === NULL) {
        continue;
      }
      $filas[] = [
        $instituciones[$vacante->institucion_id] ?? $this->t('(desconocida)'),
        $vacante->nombre,
        $cargos[$vacante->cargo_id] ?? $this->t('(desconocido)'),
      ];
    }

    $form['aviso'] = [
      '#markup' => '<p>' . $this->t('Se eliminarán <strong>@n</strong> vacantes (todas sin asignar) de forma <strong>permanente</strong>. Esta acción no se puede deshacer.', [
        '@n' => count($filas),
      ]) . '</p>',
    ];

    $form['detalle'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Institución'),
        $this->t('Vacante'),
        $this->t('Cargo'),
      ],
      '#rows' => $filas,
      '#empty' => $this->t('No hay nada que eliminar.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['confirmar'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirmar eliminación'),
      '#button_type' => 'primary',
      '#submit' => ['::confirmarEliminacion'],
    ];
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
   * Manejador de «Confirmar eliminación»: borra las vacantes en una transacción.
   *
   * No hay cascada: la vacante es una tabla hoja. Borrar una vacante asignada
   * solo pierde su designación (el funcionario no se toca). Si algo falla a
   * mitad, la transacción se revierte por completo.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function confirmarEliminacion(array &$form, FormStateInterface $form_state): void {
    $ids = (array) $form_state->get('conjunto');

    $eliminadas = 0;
    $omitidas = 0;
    $fallidas = 0;

    // Envuelve todo el borrado en una transacción: o se aplica completo o nada.
    $transaccion = $this->database->startTransaction();
    try {
      foreach ($ids as $id) {
        $id = (int) $id;
        $vacante = $this->vacanteRepository->load($id);
        // Defensa: nunca borrar una asignada (su estado pudo cambiar desde la
        // vista previa); se omite y se cuenta.
        if ($vacante === NULL || !empty($vacante->funcionario_id)) {
          $omitidas++;
          continue;
        }
        // Capa de correctitud: el propio DELETE reconfirma que sigue libre. Un 0
        // no aborta el lote (no es excepción): se cuenta aparte y se continúa.
        $borradas = $this->vacanteRepository->deleteSiLibre($id);
        if ($borradas === 0) {
          $fallidas++;
          continue;
        }
        $eliminadas += $borradas;
      }
    }
    catch (\Throwable $e) {
      // Revierte explícitamente y reporta; no deja datos a medias.
      $transaccion->rollBack();
      $this->messenger->addError($this->t('No se pudo completar la eliminación: @mensaje. No se hicieron cambios.', [
        '@mensaje' => $e->getMessage(),
      ]));
      $form_state->set('paso', NULL);
      $form_state->set('conjunto', NULL);
      $form_state->setRedirect('audiencias.vacante.eliminar_masivo');
      return;
    }

    // Al salir del ámbito, la transacción se confirma automáticamente.
    unset($transaccion);

    $this->messenger->addStatus($this->t('@n vacantes eliminadas.', ['@n' => $eliminadas]));
    if ($omitidas > 0) {
      $this->messenger->addWarning($this->t('@m vacantes se omitieron porque quedaron asignadas o ya no existían.', [
        '@m' => $omitidas,
      ]));
    }
    // Fallos por concurrencia: se informan aparte de las omisiones que sí
    // detectó la comprobación previa.
    if ($fallidas > 0) {
      $this->messenger->addError($this->t('@f vacantes no se pudieron eliminar porque fueron asignadas mientras se confirmaba la operación. Actualice la pantalla y vuelva a intentarlo.', [
        '@f' => $fallidas,
      ]));
    }

    $form_state->set('paso', NULL);
    $form_state->set('conjunto', NULL);
    $form_state->setRedirect('audiencias.vacante.eliminar_masivo');
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
    $form_state->set('paso', NULL);
    $form_state->set('conjunto', NULL);
    $this->messenger->addStatus($this->t('Operación cancelada; no se hicieron cambios.'));
    $form_state->setRedirect('audiencias.vacante.eliminar_masivo');
  }

}
