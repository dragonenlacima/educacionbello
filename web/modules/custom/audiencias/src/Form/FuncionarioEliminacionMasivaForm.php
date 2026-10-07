<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Listado de funcionarios con eliminación masiva y borrado en cascada.
 *
 * Sustituye al listado de solo lectura por un formulario que conserva las
 * columnas y las operaciones por fila, y añade dos acciones masivas con vista
 * previa y confirmación: «Eliminar seleccionados» (casillas) y «Eliminar todos»
 * (todos los funcionarios, ambos cargos). La eliminación es PERMANENTE: antes de
 * borrar a un funcionario asignado a una vacante, se libera esa vacante
 * (funcionario_id a NULL). Todo el borrado se ejecuta dentro de una transacción.
 */
class FuncionarioEliminacionMasivaForm extends FormBase {

  /**
   * Repositorio de funcionarios.
   *
   * @var \Drupal\audiencias\Repository\FuncionarioRepository
   */
  protected FuncionarioRepository $funcionarioRepository;

  /**
   * Repositorio de cargos.
   *
   * @var \Drupal\audiencias\Repository\CargoRepository
   */
  protected CargoRepository $cargoRepository;

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
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\Core\Database\Connection $database
   *   Conexión a la base de datos para la transacción.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    FuncionarioRepository $funcionario_repository,
    CargoRepository $cargo_repository,
    VacanteRepository $vacante_repository,
    Connection $database,
    MessengerInterface $messenger,
  ) {
    $this->funcionarioRepository = $funcionario_repository;
    $this->cargoRepository = $cargo_repository;
    $this->vacanteRepository = $vacante_repository;
    $this->database = $database;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.funcionario_repository'),
      $container->get('audiencias.cargo_repository'),
      $container->get('audiencias.vacante_repository'),
      $container->get('database'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_funcionario_eliminacion_masiva_form';
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
    // Mapa de cargo_id => nombre para mostrar el cargo de forma legible.
    $cargos = [];
    foreach ($this->cargoRepository->loadAll() as $cargo) {
      $cargos[$cargo->cargo_id] = $cargo->nombre;
    }

    // Cabecera asociativa: las claves enlazan cada celda con su columna y, en la
    // primera, «tableselect» inserta la casilla de selección.
    $cabecera = [
      'id' => $this->t('ID'),
      'nombre' => $this->t('Nombre'),
      'documento' => $this->t('Documento'),
      'posicion' => $this->t('Posición'),
      'cargo' => $this->t('Cargo'),
      'operaciones' => $this->t('Operaciones'),
    ];

    $opciones = [];
    foreach ($this->funcionarioRepository->loadAll() as $registro) {
      $opciones[$registro->funcionario_id] = [
        'id' => $registro->funcionario_id,
        'nombre' => $registro->nombre,
        'documento' => $registro->documento,
        'posicion' => $registro->posicion,
        'cargo' => $cargos[$registro->cargo_id] ?? $this->t('(desconocido)'),
        'operaciones' => $this->celdaOperaciones($registro->funcionario_id),
      ];
    }

    $form['funcionarios'] = [
      '#type' => 'tableselect',
      '#header' => $cabecera,
      '#options' => $opciones,
      '#empty' => $this->t('No hay funcionarios registrados.'),
      '#js_select' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['eliminar_seleccionados'] = [
      '#type' => 'submit',
      '#value' => $this->t('Eliminar seleccionados'),
      '#submit' => ['::eliminarSeleccionados'],
    ];
    $form['actions']['eliminar_todos'] = [
      '#type' => 'submit',
      '#value' => $this->t('Eliminar todos'),
      '#submit' => ['::eliminarTodos'],
      // No depende de la selección: no valida los valores del listado.
      '#limit_validation_errors' => [],
    ];

    return $form;
  }

  /**
   * Construye la celda de operaciones por fila (Editar / Borrar).
   *
   * @param int|string $funcionario_id
   *   Identificador del funcionario.
   *
   * @return array
   *   Render array de tipo «operations».
   */
  protected function celdaOperaciones($funcionario_id): array {
    return [
      'data' => [
        '#type' => 'operations',
        '#links' => [
          'editar' => [
            'title' => $this->t('Editar'),
            'url' => Url::fromRoute('audiencias.funcionario.edit', ['id' => $funcionario_id]),
          ],
          'borrar' => [
            'title' => $this->t('Borrar'),
            'url' => Url::fromRoute('audiencias.funcionario.delete', ['id' => $funcionario_id]),
          ],
        ],
      ],
    ];
  }

  /**
   * Manejador de «Eliminar seleccionados»: prepara la confirmación.
   *
   * Toma los funcionarios marcados; si no hay ninguno, avisa y permanece en el
   * listado.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function eliminarSeleccionados(array &$form, FormStateInterface $form_state): void {
    $seleccion = array_filter((array) $form_state->getValue('funcionarios'));
    $ids = array_map('intval', array_values($seleccion));

    if (empty($ids)) {
      $this->messenger->addWarning($this->t('No marcó ningún funcionario para eliminar.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $form_state->set('conjunto', $ids);
    $form_state->set('paso', 'confirmar');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Manejador de «Eliminar todos»: prepara la confirmación con todos los
   * funcionarios.
   *
   * Opera sobre TODOS los funcionarios (ambos cargos), sin depender de la
   * selección ni de la paginación.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function eliminarTodos(array &$form, FormStateInterface $form_state): void {
    $ids = [];
    foreach ($this->funcionarioRepository->loadAll() as $funcionario) {
      $ids[] = (int) $funcionario->funcionario_id;
    }

    if (empty($ids)) {
      $this->messenger->addWarning($this->t('No hay funcionarios para eliminar.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $form_state->set('conjunto', $ids);
    $form_state->set('paso', 'confirmar');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Construye el paso de CONFIRMACIÓN con el conteo, el detalle y la cascada.
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

    $form['#title'] = $this->t('Confirmar eliminación de funcionarios');

    $cargos = [];
    foreach ($this->cargoRepository->loadAll() as $cargo) {
      $cargos[$cargo->cargo_id] = $cargo->nombre;
    }

    // Detalle: Nombre — Cédula — Cargo. De paso, se cuentan los asignados a una
    // vacante para advertir del impacto de la cascada.
    $filas = [];
    $asignados = 0;
    foreach ($ids as $id) {
      $funcionario = $this->funcionarioRepository->load((int) $id);
      if ($funcionario === NULL) {
        continue;
      }
      $vacante = $this->vacanteRepository->buscarPorFuncionario((int) $id);
      if ($vacante !== NULL) {
        $asignados++;
      }
      $filas[] = [
        $funcionario->nombre,
        $funcionario->documento,
        $cargos[$funcionario->cargo_id] ?? $this->t('(desconocido)'),
        $vacante !== NULL ? $this->t('Sí (se liberará)') : $this->t('No'),
      ];
    }

    $form['aviso'] = [
      '#markup' => '<p>' . $this->t('Se eliminarán <strong>@n</strong> funcionarios de forma <strong>permanente</strong>. Esta acción no se puede deshacer.', [
        '@n' => count($filas),
      ]) . '</p>',
    ];

    if ($asignados > 0) {
      $form['cascada'] = [
        '#markup' => '<p>' . $this->t('<strong>@a</strong> de ellos están asignados a una vacante; esas vacantes quedarán «Sin asignar» antes de borrar al funcionario.', [
          '@a' => $asignados,
        ]) . '</p>',
      ];
    }

    $form['detalle'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Nombre'),
        $this->t('Cédula'),
        $this->t('Cargo'),
        $this->t('¿Asignado?'),
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
   * Manejador de «Confirmar eliminación»: borra en cascada dentro de una
   * transacción.
   *
   * Por cada funcionario: si está asignado a una vacante, primero la libera
   * (funcionario_id a NULL) y luego elimina al funcionario. Si algo falla a
   * mitad, la transacción se revierte por completo.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function confirmarEliminacion(array &$form, FormStateInterface $form_state): void {
    $ids = (array) $form_state->get('conjunto');

    $eliminados = 0;
    $liberadas = 0;

    // Envuelve todo el borrado en una transacción: o se aplica completo o nada.
    $transaccion = $this->database->startTransaction();
    try {
      foreach ($ids as $id) {
        $id = (int) $id;
        // Cascada: libera cualquier vacante que ocupe el funcionario, sea cual
        // sea, de modo que el borrado no pueda dejar una vacante huérfana. Un 0
        // significa que no ocupaba ninguna: caso normal, sin aviso.
        $liberadas += $this->vacanteRepository->desasignarPorFuncionario($id);
        $eliminados += $this->funcionarioRepository->delete($id);
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
      $form_state->setRedirect('audiencias.funcionario.list');
      return;
    }

    // Al salir del ámbito, la transacción se confirma automáticamente.
    unset($transaccion);

    $this->messenger->addStatus($this->t('@n funcionarios eliminados; @m vacantes liberadas.', [
      '@n' => $eliminados,
      '@m' => $liberadas,
    ]));

    $form_state->set('paso', NULL);
    $form_state->set('conjunto', NULL);
    $form_state->setRedirect('audiencias.funcionario.list');
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
    $form_state->setRedirect('audiencias.funcionario.list');
  }

}
