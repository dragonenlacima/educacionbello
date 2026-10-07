<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de sorteo aleatorio de vacantes por cargo.
 *
 * Empareja al azar los funcionarios PENDIENTES con las vacantes DISPONIBLES del
 * mismo cargo (uno a uno, hasta el máximo posible).
 *
 * Al entrar, la pantalla NO sortea: solo muestra cuántos funcionarios están
 * pendientes y cuántas vacantes hay libres. El sorteo se GENERA Y SE APLICA en
 * la misma petición al pulsar «Realizar sorteo», dentro de una transacción, y
 * después se muestra el resultado YA APLICADO. No hay paso de confirmación ni
 * emparejamiento guardado entre peticiones: por construcción, lo que se muestra
 * es exactamente lo que quedó en la base de datos.
 */
class SorteoVacantesForm extends FormBase {

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
   * La conexión a la base de datos (para la transacción del sorteo).
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Construye el formulario con sus dependencias.
   *
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   * @param \Drupal\Core\Database\Connection $database
   *   Conexión a la base de datos, para envolver el sorteo en una transacción.
   */
  public function __construct(
    CargoRepository $cargo_repository,
    FuncionarioRepository $funcionario_repository,
    VacanteRepository $vacante_repository,
    InstitucionRepository $institucion_repository,
    MessengerInterface $messenger,
    Connection $database,
  ) {
    $this->cargoRepository = $cargo_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->vacanteRepository = $vacante_repository;
    $this->institucionRepository = $institucion_repository;
    $this->messenger = $messenger;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.cargo_repository'),
      $container->get('audiencias.funcionario_repository'),
      $container->get('audiencias.vacante_repository'),
      $container->get('audiencias.institucion_repository'),
      $container->get('messenger'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_sorteo_vacantes_form';
  }

  /**
   * Devuelve los funcionarios PENDIENTES del cargo (sin vacante asignada).
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   *
   * @return array
   *   Lista de registros de funcionario que aún no tienen vacante.
   */
  protected function obtenerPendientes(int $cargo_id): array {
    $pendientes = [];
    foreach ($this->funcionarioRepository->buscarPorCargo($cargo_id) as $funcionario) {
      if ($this->vacanteRepository->buscarPorFuncionario((int) $funcionario->funcionario_id) === NULL) {
        $pendientes[] = $funcionario;
      }
    }
    return $pendientes;
  }

  /**
   * Baraja una lista con Fisher-Yates usando la aleatoriedad del sistema.
   *
   * No se usa shuffle(), que se apoya en Mersenne Twister, un generador NO
   * criptográfico: en un acto administrativo público corresponde tomar la
   * aleatoriedad del sistema operativo mediante random_int().
   *
   * @param array $items
   *   Lista a barajar. Se recibe por valor: la original no se modifica.
   *
   * @return array
   *   La lista barajada, reindexada desde 0.
   */
  private function barajar(array $items): array {
    $items = array_values($items);
    for ($i = count($items) - 1; $i > 0; $i--) {
      $j = random_int(0, $i);
      [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    return $items;
  }

  /**
   * Genera un emparejamiento aleatorio de pendientes con disponibles.
   *
   * Baraja ambas listas y empareja índice a índice hasta el máximo posible
   * (mínimo entre pendientes y disponibles).
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   *
   * @return array
   *   Estructura con claves «pares» (lista de [funcionario_id, vacante_id]),
   *   «pendientes_total», «disponibles_total», «funcionarios_sobrantes» y
   *   «vacantes_sobrantes».
   */
  protected function generarSorteo(int $cargo_id): array {
    $pendientes = $this->obtenerPendientes($cargo_id);
    $disponibles = $this->vacanteRepository->disponiblesPorCargo($cargo_id);

    // Baraja aleatoriamente ambos conjuntos. Se barajan LOS DOS para que, cuando
    // sobren vacantes, cuáles se adjudican también salga al azar.
    $pendientes = $this->barajar($pendientes);
    $disponibles = $this->barajar($disponibles);

    $maximo = min(count($pendientes), count($disponibles));

    $pares = [];
    for ($i = 0; $i < $maximo; $i++) {
      $pares[] = [
        'funcionario_id' => (int) $pendientes[$i]->funcionario_id,
        'vacante_id' => (int) $disponibles[$i]->vacante_id,
      ];
    }

    // Sobrantes: lo que queda fuera del emparejamiento.
    $funcionarios_sobrantes = [];
    for ($i = $maximo; $i < count($pendientes); $i++) {
      $funcionarios_sobrantes[] = (int) $pendientes[$i]->funcionario_id;
    }
    $vacantes_sobrantes = [];
    for ($i = $maximo; $i < count($disponibles); $i++) {
      $vacantes_sobrantes[] = (int) $disponibles[$i]->vacante_id;
    }

    return [
      'pares' => $pares,
      'pendientes_total' => count($pendientes),
      'disponibles_total' => count($disponibles),
      'funcionarios_sobrantes' => $funcionarios_sobrantes,
      'vacantes_sobrantes' => $vacantes_sobrantes,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $cargo_id
   *   Identificador del cargo a sortear (desde la ruta).
   */
  public function buildForm(array $form, FormStateInterface $form_state, $cargo_id = NULL): array {
    // El cargo llega SIEMPRE por la ruta (la URL es la misma en el GET y en el
    // POST), así que no depende de que se conserve estado entre peticiones.
    $cargo_id = (int) ($form_state->get('cargo_id') ?? $cargo_id);
    $cargo = $this->cargoRepository->load($cargo_id);
    if ($cargo === NULL) {
      throw new NotFoundHttpException('El cargo indicado no existe.');
    }

    // Se guarda en el almacenamiento para que el manejador de envío lo tenga a
    // mano dentro de ESTA petición (y en la reconstrucción posterior).
    $form_state->set('cargo_id', $cargo_id);

    $form['#title'] = $this->t('Sorteo de vacantes: @cargo', ['@cargo' => $cargo->nombre]);

    // Estilos del módulo (presentación del resultado) y librería del overlay,
    // que ahora cubre la petición REAL del sorteo.
    $form['#attached']['library'][] = 'audiencias/asignacion';
    $form['#attached']['library'][] = 'audiencias/sorteo';
    $form['#attached']['library'][] = 'audiencias/sorteo_no_bfcache';

    $form['encabezado'] = [
      '#markup' => '<h2>' . $this->t('Sorteo de vacantes') . '</h2>',
      '#weight' => 0,
    ];

    // Estado actual del cargo. Al ENTRAR no se sortea nada: solo se informa.
    // Se recalcula en cada construcción, también tras aplicar un sorteo.
    $pendientes = count($this->obtenerPendientes($cargo_id));
    $disponibles = count($this->vacanteRepository->disponiblesPorCargo($cargo_id));

    $form['estado'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['audiencias-sorteo-resumen']],
      '#weight' => 5,
      'conteo' => [
        // Cada conteo se traduce como frase completa con formatPlural(), no en
        // fragmentos sueltos. El «·» y el punto final son puntuación, no texto.
        '#markup' => '<div class="audiencias-sorteo-resumen__conteo">'
        . $this->formatPlural(
          $pendientes,
          '<span class="audiencias-sorteo-resumen__numero">1</span> funcionario pendiente',
          '<span class="audiencias-sorteo-resumen__numero">@count</span> funcionarios pendientes'
        )
        . ' · '
        . $this->formatPlural(
          $disponibles,
          '<span class="audiencias-sorteo-resumen__numero">1</span> vacante disponible',
          '<span class="audiencias-sorteo-resumen__numero">@count</span> vacantes disponibles'
        )
        . '.</div>',
      ],
    ];

    // Resultado YA APLICADO del sorteo ejecutado en ESTA misma petición: lo deja
    // realizarSorteo() justo antes de reconstruir. No sobrevive a la petición,
    // así que no existe un emparejamiento almacenado que pueda diferir de lo
    // guardado en la base de datos.
    $resultado = $form_state->get('resultado');
    if ($resultado !== NULL) {
      $form['resultado_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['js-sorteo-resultado']],
        '#weight' => 10,
      ];
      $form['resultado_wrapper'] += $this->construirResultado($resultado);
    }

    // Barra de acciones al pie con jerarquía visual.
    $form['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['audiencias-acciones-sorteo']],
      '#weight' => 100,
    ];

    // «Realizar sorteo»: genera Y aplica en la misma petición. Solo se ofrece si
    // hay al menos un funcionario pendiente y al menos una vacante disponible.
    // Envío normal (sin AJAX): no hay estado de formulario que persistir.
    if ($pendientes > 0 && $disponibles > 0) {
      $form['actions']['realizar'] = [
        '#type' => 'submit',
        '#value' => $this->t('Realizar sorteo'),
        '#button_type' => 'primary',
        '#submit' => ['::realizarSorteo'],
        '#attributes' => ['class' => ['js-sorteo-trigger']],
      ];
    }
    else {
      $form['actions']['sin_sorteo'] = [
        '#markup' => '<p>' . $this->t('No hay sorteo posible: se requiere al menos un funcionario pendiente y una vacante disponible.') . '</p>',
      ];
    }

    $form['actions']['cancelar'] = $this->botonCancelar($cargo_id);

    return $form;
  }

  /**
   * Construye el resultado YA APLICADO (resumen, tabla y sobrantes).
   *
   * Devuelve los elementos hijos del contenedor del resultado. No genera ni
   * aplica nada: solo presenta los pares que ::realizarSorteo() acaba de guardar
   * en la base de datos durante esta misma petición.
   *
   * @param array $resultado
   *   Estructura con «aplicados» (los pares guardados), «pendientes_total»,
   *   «funcionarios_sobrantes» y «vacantes_sobrantes».
   *
   * @return array
   *   Elementos de render a insertar en el contenedor del resultado.
   */
  protected function construirResultado(array $resultado): array {
    $pares = $resultado['aplicados'];
    $asignadas = count($pares);
    $pendientes_total = $resultado['pendientes_total'];

    // Sin pares aplicados: no hubo nada que asignar.
    if ($asignadas === 0) {
      return [
        'vacio' => [
          '#markup' => '<p>' . $this->t('El sorteo no aplicó ninguna asignación.') . '</p>',
        ],
      ];
    }

    $elementos = [];

    // Resumen del sorteo, destacado en un banner: el conteo «Se asignaron N de M»
    // en tamaño mayor y, debajo, las líneas secundarias (sobrantes/libres).
    $funcionarios_sobrantes = count($resultado['funcionarios_sobrantes']);
    $vacantes_sobrantes = count($resultado['vacantes_sobrantes']);

    $elementos['resumen'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['audiencias-sorteo-resumen']],
      'conteo' => [
        '#markup' => '<div class="audiencias-sorteo-resumen__conteo">'
        . $this->t('Sorteo APLICADO: se asignaron <span class="audiencias-sorteo-resumen__numero">@n</span> de @m pendientes. Estas asignaciones ya están guardadas.', [
          '@n' => $asignadas,
          '@m' => $pendientes_total,
        ]) . '</div>',
      ],
    ];

    // Líneas secundarias solo si hay sobrantes o vacantes libres.
    $detalle_resumen = [];
    if ($funcionarios_sobrantes > 0) {
      $detalle_resumen[] = $this->t('@x funcionarios quedaron sin vacante.', ['@x' => $funcionarios_sobrantes]);
    }
    if ($vacantes_sobrantes > 0) {
      $detalle_resumen[] = $this->t('@y vacantes quedaron libres.', ['@y' => $vacantes_sobrantes]);
    }
    if ($detalle_resumen !== []) {
      $elementos['resumen']['detalle'] = [
        '#theme' => 'item_list',
        '#items' => $detalle_resumen,
        '#attributes' => ['class' => ['audiencias-sorteo-resumen__detalle']],
      ];
    }

    // Mapas auxiliares para mostrar nombres en el resultado.
    $instituciones = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $instituciones[(int) $institucion->institucion_id] = $institucion;
    }

    // Tabla del resultado: funcionario → institución — vacante.
    $filas = [];
    foreach ($pares as $par) {
      $funcionario = $this->funcionarioRepository->load($par['funcionario_id']);
      $vacante = $this->vacanteRepository->load($par['vacante_id']);
      if ($funcionario === NULL || $vacante === NULL) {
        continue;
      }
      $institucion = $instituciones[(int) $vacante->institucion_id] ?? NULL;
      $nombre_inst = $institucion !== NULL ? $institucion->nombre : (string) $this->t('(institución desconocida)');
      $ubicacion = $institucion !== NULL ? trim((string) ($institucion->ubicacion ?? '')) : '';

      $celda_funcionario = [
        'data' => [
          '#type' => 'inline_template',
          '#template' => '<strong>{{ nombre }}</strong><br><small class="audiencias-funcionario-meta">{{ "Cédula"|t }}: {{ documento }} · {{ "Posición"|t }}: {{ posicion }}</small>',
          '#context' => [
            'nombre' => $funcionario->nombre,
            'documento' => $funcionario->documento,
            'posicion' => $funcionario->posicion,
          ],
        ],
      ];
      // Designación en verde; la ubicación, debajo y en texto tenue (igual que el tablero).
      $celda_vacante = [
        'data' => [
          '#type' => 'inline_template',
          '#template' => '<span class="audiencias-designacion">{{ institucion }} — {{ vacante }}</span>{% if ubicacion %}<br><small class="audiencias-ubicacion">{{ ubicacion }}</small>{% endif %}',
          '#context' => [
            'institucion' => $nombre_inst,
            'vacante' => $vacante->nombre,
            'ubicacion' => $ubicacion,
          ],
        ],
      ];
      $filas[] = [$celda_funcionario, $celda_vacante];
    }

    $elementos['resultado'] = [
      '#type' => 'table',
      '#header' => [$this->t('Funcionario'), $this->t('Vacante asignada (sorteo)')],
      '#rows' => $filas,
      '#attributes' => ['class' => ['audiencias-sorteo-tabla']],
    ];

    // Lista de funcionarios sobrantes, si los hay.
    if ($funcionarios_sobrantes > 0) {
      $items = [];
      foreach ($resultado['funcionarios_sobrantes'] as $funcionario_id) {
        $funcionario = $this->funcionarioRepository->load((int) $funcionario_id);
        if ($funcionario !== NULL) {
          $items[] = $this->t('@nombre (cédula @documento)', [
            '@nombre' => $funcionario->nombre,
            '@documento' => $funcionario->documento,
          ]);
        }
      }
      // Bloque diferenciado tipo aviso (fondo amarillo claro), con su título.
      $elementos['sobrantes'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['audiencias-sorteo-sobrantes']],
        'titulo' => [
          '#markup' => '<h3 class="audiencias-sorteo-sobrantes__titulo">'
          . $this->t('Funcionarios que quedaron sin vacante (@n)', ['@n' => $funcionarios_sobrantes])
          . '</h3>',
        ],
        'lista' => [
          '#theme' => 'item_list',
          '#items' => $items,
        ],
      ];
    }

    return $elementos;
  }

  /**
   * Construye el enlace «Cancelar» de regreso a la pantalla del cargo.
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   *
   * @return array
   *   Render array de un enlace de cancelación.
   */
  protected function botonCancelar(int $cargo_id): array {
    return [
      '#type' => 'link',
      '#title' => $this->t('Cancelar'),
      '#url' => Url::fromRoute('audiencias.asignacion', ['cargo_id' => $cargo_id]),
      // Acción terciaria: aspecto tenue tipo enlace dentro de la barra.
      '#attributes' => ['class' => ['button', 'button--terciario']],
    ];
  }

  /**
   * Manejador de «Realizar sorteo»: genera Y aplica en la MISMA petición.
   *
   * Aquí está la corrección de fondo del defecto: el emparejamiento se calcula y
   * se guarda dentro de esta única llamada, sin pasar por el estado del
   * formulario ni por una pantalla de confirmación. El resultado que se muestra
   * después se construye a partir de los pares REALMENTE aplicados, así que no
   * puede diferir de lo que quedó en la base de datos.
   *
   * Todo el lote va en una transacción, como en los demás formularios masivos
   * del módulo. Un fallo aislado (0 filas o violación de la llave única) no
   * aborta el lote: se cuenta aparte y se informa al final.
   *
   * @param array $form
   *   Estructura del formulario.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Estado del formulario.
   */
  public function realizarSorteo(array &$form, FormStateInterface $form_state): void {
    $cargo_id = (int) $form_state->get('cargo_id');
    $sorteo = $this->generarSorteo($cargo_id);

    $aplicados = [];
    $omitidos = 0;
    $fallidos = 0;

    // Envuelve todo el lote en una transacción: o se aplica completo o nada.
    $transaccion = $this->database->startTransaction();
    try {
      foreach ($sorteo['pares'] as $par) {
        $funcionario_id = (int) $par['funcionario_id'];
        $vacante_id = (int) $par['vacante_id'];

        // Defensa en profundidad: con el flujo nuevo no hay ventana entre
        // generar y aplicar, así que estas dos comprobaciones son redundantes;
        // se conservan por si el estado cambiara por otra vía.
        if ($this->vacanteRepository->buscarPorFuncionario($funcionario_id) !== NULL) {
          $omitidos++;
          continue;
        }
        $vacante = $this->vacanteRepository->load($vacante_id);
        if ($vacante === NULL || !empty($vacante->funcionario_id) || (int) $vacante->estado !== 1) {
          $omitidos++;
          continue;
        }

        // Asignación por sorteo: la denominación es «sorteo».
        try {
          $afectadas = $this->vacanteRepository->asignar($vacante_id, $funcionario_id, 'sorteo');
        }
        catch (IntegrityConstraintViolationException $e) {
          // Saltó la llave única sobre «funcionario_id»: al funcionario le
          // asignaron otra vacante en paralelo.
          $fallidos++;
          continue;
        }
        // Cero filas afectadas: otro proceso tomó la vacante en el intervalo.
        if ($afectadas === 0) {
          $fallidos++;
          continue;
        }
        $aplicados[] = $par;
      }
    }
    catch (\Throwable $e) {
      // Revierte explícitamente y reporta; no deja el sorteo a medias.
      $transaccion->rollBack();
      $this->messenger->addError($this->t('No se pudo aplicar el sorteo: @mensaje. No se hicieron cambios.', [
        '@mensaje' => $e->getMessage(),
      ]));
      $form_state->setRebuild(TRUE);
      return;
    }

    // Al salir del ámbito, la transacción se confirma automáticamente.
    unset($transaccion);

    // Lo que se mostrará son EXACTAMENTE los pares que quedaron guardados.
    $form_state->set('resultado', [
      'aplicados' => $aplicados,
      'pendientes_total' => $sorteo['pendientes_total'],
      'funcionarios_sobrantes' => $sorteo['funcionarios_sobrantes'],
      'vacantes_sobrantes' => $sorteo['vacantes_sobrantes'],
    ]);

    $this->messenger->addStatus($this->t('Sorteo aplicado: @n asignaciones quedaron guardadas.', [
      '@n' => count($aplicados),
    ]));
    if ($omitidos > 0) {
      $this->messenger->addWarning($this->t('@m pares se omitieron porque su estado ya había cambiado.', [
        '@m' => $omitidos,
      ]));
    }
    if ($fallidos > 0) {
      $this->messenger->addError($this->t('@f asignaciones no se pudieron aplicar porque otro proceso tomó la vacante o el funcionario. Repita el sorteo con los pendientes.', [
        '@f' => $fallidos,
      ]));
    }

    // Reconstruye en ESTA misma petición para mostrar el resultado aplicado.
    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * El envío por defecto no se usa: «Realizar sorteo» define su propio manejador.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Intencionalmente vacío: ver ::realizarSorteo().
  }

}
