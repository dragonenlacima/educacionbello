<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Listado de vacantes con reversa masiva de asignaciones.
 *
 * Sustituye al listado de solo lectura por un formulario que conserva las
 * columnas y las operaciones por fila, y añade dos acciones masivas con vista
 * previa y confirmación: «Reversar seleccionadas» (casillas, solo en vacantes
 * asignadas) y «Reversar todas las asignadas» (todas, de ambos cargos). Solo se
 * reversan vacantes con funcionario asignado; nunca se tocan las libres.
 */
class VacanteReversaMasivaForm extends FormBase {

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
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    VacanteRepository $vacante_repository,
    InstitucionRepository $institucion_repository,
    CargoRepository $cargo_repository,
    FuncionarioRepository $funcionario_repository,
    MessengerInterface $messenger,
  ) {
    $this->vacanteRepository = $vacante_repository;
    $this->institucionRepository = $institucion_repository;
    $this->cargoRepository = $cargo_repository;
    $this->funcionarioRepository = $funcionario_repository;
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
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_vacante_reversa_masiva_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    // El formulario tiene dos pasos: el listado y la confirmación de la reversa.
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
      'denominacion' => $this->t('Denominación'),
      'operaciones' => $this->t('Operaciones'),
    ];

    // Destino de regreso para las operaciones por fila: el propio listado, de
    // modo que tras reversar o asignar el usuario vuelve aquí.
    $destino = Url::fromRoute('audiencias.vacante.list')->toString();

    $opciones = [];
    foreach ($this->vacanteRepository->loadAll() as $registro) {
      $asignada = !empty($registro->funcionario_id);
      $funcionario = $asignada
        ? ($funcionarios[$registro->funcionario_id] ?? $this->t('(desconocido)'))
        : $this->t('Sin asignar');

      // Operación condicionada al estado: «Asignar» si está libre y activa,
      // «Reversar» (individual) si ya está ocupada. Una vacante inactiva no se
      // asigna, así que no ofrece «Asignar». Llevan «destination» para regresar
      // a este listado.
      $extra = [];
      if ($asignada) {
        $extra = [
          'desasignar' => [
            'title' => $this->t('Reversar asignación'),
            'url' => Url::fromRoute('audiencias.vacante.desasignar', ['vacante_id' => $registro->vacante_id], ['query' => ['destination' => $destino]]),
          ],
        ];
      }
      elseif ((int) $registro->estado === 1) {
        $extra = [
          'asignar' => [
            'title' => $this->t('Asignar funcionario'),
            'url' => Url::fromRoute('audiencias.vacante.asignar', ['vacante_id' => $registro->vacante_id], ['query' => ['destination' => $destino]]),
          ],
        ];
      }

      // Denominación: con inicial mayúscula si existe; «—» si está vacía o NULL.
      $denominacion = trim((string) ($registro->denominacion ?? ''));
      $denominacion = $denominacion !== '' ? ucfirst($denominacion) : '—';

      $opciones[$registro->vacante_id] = [
        'id' => $registro->vacante_id,
        'institucion' => $this->celdaInstitucion($instituciones[$registro->institucion_id] ?? NULL),
        'nombre' => $registro->nombre,
        'estado' => $registro->estado ? $this->t('Activa') : $this->t('Inactiva'),
        'cargo' => $cargos[$registro->cargo_id] ?? $this->t('(desconocido)'),
        'tipo_vacante' => $registro->tipo_vacante ?? '',
        'funcionario' => $funcionario,
        'denominacion' => $denominacion,
        'operaciones' => $this->celdaOperaciones($registro->vacante_id, $extra),
        // Solo las vacantes ASIGNADAS son seleccionables; las libres no tienen
        // nada que reversar y su casilla queda deshabilitada.
        '#disabled' => !$asignada,
      ];
    }

    // Enlace de exportación a Excel, reutilizando la presentación tipo «pill»
    // de la librería de asignación. Apunta a la ruta del controlador (no es un
    // submit del formulario).
    $form['#attached']['library'][] = 'audiencias/asignacion';
    $form['exportar'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['audiencias-filtros']],
      'enlace' => [
        '#type' => 'link',
        '#title' => $this->t('Exportar a Excel'),
        '#url' => Url::fromRoute('audiencias.vacante.exportar'),
        '#attributes' => ['class' => ['audiencias-filtro']],
      ],
    ];

    $form['vacantes'] = [
      '#type' => 'tableselect',
      '#header' => $cabecera,
      '#options' => $opciones,
      '#empty' => $this->t('No hay vacantes registradas.'),
      '#js_select' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['reversar_seleccionadas'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reversar seleccionadas'),
      '#submit' => ['::reversarSeleccionadas'],
    ];
    $form['actions']['reversar_todas'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reversar todas las asignadas'),
      '#submit' => ['::reversarTodas'],
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
   * Construye la celda de operaciones por fila (extra + Editar/Borrar).
   *
   * @param int|string $vacante_id
   *   Identificador de la vacante.
   * @param array $extra
   *   Enlaces a anteponer (clave => ['title' => ..., 'url' => ...]).
   *
   * @return array
   *   Render array de tipo «operations».
   */
  protected function celdaOperaciones($vacante_id, array $extra): array {
    $enlaces = $extra + [
      'editar' => [
        'title' => $this->t('Editar'),
        'url' => Url::fromRoute('audiencias.vacante.edit', ['id' => $vacante_id]),
      ],
      'borrar' => [
        'title' => $this->t('Borrar'),
        'url' => Url::fromRoute('audiencias.vacante.delete', ['id' => $vacante_id]),
      ],
    ];

    return [
      'data' => [
        '#type' => 'operations',
        '#links' => $enlaces,
      ],
    ];
  }

  /**
   * Manejador de «Reversar seleccionadas»: prepara la confirmación.
   *
   * Toma las vacantes marcadas y conserva solo las que siguen ASIGNADAS. Si no
   * hay ninguna asignada marcada, avisa y permanece en el listado.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function reversarSeleccionadas(array &$form, FormStateInterface $form_state): void {
    $seleccion = array_filter((array) $form_state->getValue('vacantes'));
    $ids = $this->filtrarAsignadas(array_map('intval', array_values($seleccion)));

    if (empty($ids)) {
      $this->messenger->addWarning($this->t('No marcó ninguna vacante asignada para reversar.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $form_state->set('conjunto', $ids);
    $form_state->set('paso', 'confirmar');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Manejador de «Reversar todas las asignadas»: prepara la confirmación.
   *
   * Opera sobre TODAS las vacantes asignadas (ambos cargos), sin depender de la
   * selección ni de la paginación.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function reversarTodas(array &$form, FormStateInterface $form_state): void {
    $ids = [];
    foreach ($this->vacanteRepository->buscarAsignadas() as $vacante) {
      // Pares «vacante_id => funcionario_id»: se captura el ocupante actual
      // para exigirlo al confirmar la reversa.
      $ids[(int) $vacante->vacante_id] = (int) $vacante->funcionario_id;
    }

    if (empty($ids)) {
      $this->messenger->addWarning($this->t('No hay vacantes asignadas para reversar.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $form_state->set('conjunto', $ids);
    $form_state->set('paso', 'confirmar');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Filtra una lista de identificadores dejando solo las vacantes asignadas.
   *
   * Captura además el ocupante de cada vacante en ESTE momento —el que el
   * operador está viendo al seleccionar— para poder exigirlo al confirmar.
   *
   * @param int[] $ids
   *   Identificadores de vacante candidatos.
   *
   * @return int[]
   *   Pares «vacante_id => funcionario_id» de las vacantes que actualmente
   *   tienen funcionario asignado.
   */
  protected function filtrarAsignadas(array $ids): array {
    $asignadas = [];
    foreach ($ids as $id) {
      $vacante = $this->vacanteRepository->load($id);
      if ($vacante !== NULL && !empty($vacante->funcionario_id)) {
        $asignadas[(int) $id] = (int) $vacante->funcionario_id;
      }
    }
    return $asignadas;
  }

  /**
   * Construye el paso de CONFIRMACIÓN con el conteo y el detalle a reversar.
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

    $form['#title'] = $this->t('Confirmar reversa de asignaciones');

    $instituciones = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $instituciones[$institucion->institucion_id] = $institucion->nombre;
    }
    $funcionarios = [];
    foreach ($this->funcionarioRepository->loadAll() as $funcionario) {
      $funcionarios[$funcionario->funcionario_id] = $funcionario->nombre;
    }

    $form['aviso'] = [
      '#markup' => '<p>' . $this->t('Se reversarán <strong>@n</strong> asignaciones. Las vacantes quedarán «Sin asignar» y sus funcionarios libres para reasignarse.', [
        '@n' => count($ids),
      ]) . '</p>',
    ];

    // Detalle: Funcionario — Vacante — Institución. El «conjunto» son pares
    // «vacante_id => funcionario_id»; aquí solo se recorren las claves.
    $filas = [];
    foreach (array_keys($ids) as $id) {
      $vacante = $this->vacanteRepository->load((int) $id);
      if ($vacante === NULL) {
        continue;
      }
      $filas[] = [
        !empty($vacante->funcionario_id)
          ? ($funcionarios[$vacante->funcionario_id] ?? $this->t('(desconocido)'))
          : $this->t('(sin funcionario)'),
        $vacante->nombre,
        $instituciones[$vacante->institucion_id] ?? $this->t('(desconocida)'),
      ];
    }

    $form['detalle'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Funcionario'),
        $this->t('Vacante'),
        $this->t('Institución'),
      ],
      '#rows' => $filas,
      '#empty' => $this->t('No hay nada que reversar.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['confirmar'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirmar reversa'),
      '#button_type' => 'primary',
      '#submit' => ['::confirmarReversa'],
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
   * Manejador de «Confirmar reversa»: aplica las desasignaciones válidas.
   *
   * Exige el ocupante capturado al seleccionar (los pares del «conjunto»), de
   * modo que solo se reversa la asignación que el operador realmente vio. Un
   * fallo no aborta el lote: se cuentan aparte las benignas (ya estaban «Sin
   * asignar») y los conflictos reales (ahora las ocupa otro funcionario).
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function confirmarReversa(array &$form, FormStateInterface $form_state): void {
    $conjunto = (array) $form_state->get('conjunto');

    $reversadas = 0;
    $omitidas = 0;
    $conflictos = 0;
    // El «conjunto» son pares «vacante_id => funcionario_id» capturados al
    // seleccionar: se exige ese ocupante en el momento exacto del UPDATE.
    foreach ($conjunto as $id => $funcionario_esperado) {
      if ($this->vacanteRepository->desasignar((int) $id, (int) $funcionario_esperado) === 0) {
        // Un fallo NO aborta el lote: se clasifica y se continúa.
        $actual = $this->vacanteRepository->load((int) $id);
        if ($actual !== NULL && !empty($actual->funcionario_id)) {
          // Conflicto real: ahora la ocupa otro funcionario.
          $conflictos++;
        }
        else {
          // Benigno: ya estaba «Sin asignar» o la vacante ya no existe.
          $omitidas++;
        }
        continue;
      }
      $reversadas++;
    }

    $this->messenger->addStatus($this->t('@n reversadas.', ['@n' => $reversadas]));
    // Benignas: ya no requerían cambios; no son un error.
    if ($omitidas > 0) {
      $this->messenger->addWarning($this->t('@m vacantes ya estaban «Sin asignar»; no requirieron cambios.', [
        '@m' => $omitidas,
      ]));
    }
    // Conflictos reales: se reasignaron durante la confirmación.
    if ($conflictos > 0) {
      $this->messenger->addError($this->t('@c vacantes no se reversaron porque ahora las ocupa otro funcionario. Actualice la pantalla y vuelva a intentarlo.', [
        '@c' => $conflictos,
      ]));
    }

    $form_state->set('paso', NULL);
    $form_state->set('conjunto', NULL);
    $form_state->setRedirect('audiencias.vacante.list');
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
    $form_state->setRedirect('audiencias.vacante.list');
  }

}
