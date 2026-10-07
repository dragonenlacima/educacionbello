<?php

declare(strict_types=1);

namespace Drupal\audiencias\Controller;

use Drupal\audiencias\Form\AsignarFilaForm;
use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Url;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pantalla de asignación parametrizada por cargo.
 *
 * Funciona a la vez como herramienta de asignación inmediata (un formulario por
 * fila pendiente) y como tablero de estado (resumen de avance y designación
 * actual de cada funcionario). Obtiene los datos solo a través de la capa de
 * repositorios.
 */
class AsignacionController extends ControllerBase {

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
   * Resolución de clases (para instanciar el formulario con su funcionario).
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  protected ClassResolverInterface $classResolver;

  /**
   * Sistema de archivos (para el directorio temporal de la exportación).
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Construye el controlador con sus dependencias.
   *
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   Constructor de formularios.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $class_resolver
   *   Servicio de resolución de clases.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   Servicio del sistema de archivos.
   */
  public function __construct(
    CargoRepository $cargo_repository,
    FuncionarioRepository $funcionario_repository,
    VacanteRepository $vacante_repository,
    InstitucionRepository $institucion_repository,
    FormBuilderInterface $form_builder,
    ClassResolverInterface $class_resolver,
    FileSystemInterface $file_system,
  ) {
    $this->cargoRepository = $cargo_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->vacanteRepository = $vacante_repository;
    $this->institucionRepository = $institucion_repository;
    $this->formBuilder = $form_builder;
    $this->classResolver = $class_resolver;
    $this->fileSystem = $file_system;
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
      $container->get('form_builder'),
      $container->get('class_resolver'),
      $container->get('file_system'),
    );
  }

  /**
   * Renderiza la pantalla de asignación de un cargo.
   *
   * @param int $cargo_id
   *   Identificador del cargo a gestionar.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La petición actual (para los filtros de estado y búsqueda).
   *
   * @return array
   *   Render array de la pantalla.
   */
  public function pantalla($cargo_id, Request $request): array {
    $cargo_id = (int) $cargo_id;
    $cargo = $this->cargoRepository->load($cargo_id);
    if ($cargo === NULL) {
      throw new NotFoundHttpException('El cargo indicado no existe.');
    }

    // Mapa de instituciones (nombre) para mostrar la designación actual.
    $instituciones = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $instituciones[$institucion->institucion_id] = $institucion->nombre;
    }

    // Conjunto completo de funcionarios del cargo (para los conteos del resumen).
    $todos = $this->obtenerRegistros($cargo_id, 'todos', '');
    $total = count($todos);
    $asignados = count(array_filter($todos, static fn(array $r): bool => $r['designacion'] !== NULL));
    $pendientes = $total - $asignados;
    $libres = count($this->vacanteRepository->disponiblesPorCargo($cargo_id));
    $porcentaje = $total > 0 ? (int) round(($asignados / $total) * 100) : 0;

    // Filtros de estado y búsqueda desde la cadena de consulta.
    $estado = $this->normalizarEstado($request->query->get('estado', 'todos'));
    $buscar = trim((string) $request->query->get('buscar', ''));

    // Aplica los filtros sobre el conjunto ya cargado (sin nuevas consultas);
    // el orden de «obtenerRegistros» ya deja los pendientes primero.
    $registros = $this->filtrar($todos, $estado, $buscar);

    $build = [];
    $build['#title'] = $this->t('Asignación de @cargo', ['@cargo' => $cargo->nombre]);

    // Resumen de avance + barra de progreso.
    $build['resumen'] = $this->construirResumen($total, $asignados, $pendientes, $libres, $porcentaje);

    // Panel de sorteo aleatorio de vacantes.
    $build['sorteo'] = $this->construirSorteo($cargo_id, $pendientes, $libres);

    // Formulario de filtros (GET).
    $build['filtros'] = $this->construirFiltros($cargo_id, $estado, $buscar);

    // Botones de exportación a Excel (uno por estado).
    $build['exportar'] = $this->construirExportar($cargo_id);

    // Tabla de funcionarios.
    $build['tabla'] = $this->construirTabla($registros, $cargo_id, $instituciones);
    $build['tabla']['#empty'] = $this->t('No hay funcionarios que coincidan con el filtro.');

    // Hoja de estilos de la pantalla (filtros tipo «pill»).
    $build['#attached']['library'][] = 'audiencias/asignacion';

    // Evita que la pantalla se cachee: refleja asignaciones en tiempo real.
    $build['#cache']['max-age'] = 0;

    return $build;
  }

  /**
   * Normaliza el valor del filtro de estado a uno de los permitidos.
   *
   * @param mixed $estado
   *   Valor recibido de la petición.
   *
   * @return string
   *   Uno de: todos, pendientes, asignados (por defecto «todos»).
   */
  protected function normalizarEstado($estado): string {
    $estado = (string) $estado;
    return in_array($estado, ['todos', 'pendientes', 'asignados'], TRUE) ? $estado : 'todos';
  }

  /**
   * Obtiene los funcionarios filtrados de un cargo con su designación.
   *
   * Método COMPARTIDO por la pantalla y la exportación: carga los funcionarios
   * del cargo vía repositorios, adjunta su designación actual (vacante asignada,
   * si la hay), aplica el filtro de estado/búsqueda y deja los pendientes
   * primero (orden estable por posición).
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   * @param string $estado
   *   Estado a filtrar: todos, pendientes o asignados.
   * @param string $buscar
   *   Término a buscar en nombre o cédula (vacío = sin filtro).
   *
   * @return array
   *   Lista de registros con claves «funcionario» y «designacion», filtrada y
   *   ordenada (pendientes primero, luego por posición ascendente).
   */
  protected function obtenerRegistros(int $cargo_id, string $estado, string $buscar): array {
    $registros = [];
    foreach ($this->funcionarioRepository->buscarPorCargo($cargo_id) as $funcionario) {
      $registros[] = [
        'funcionario' => $funcionario,
        'designacion' => $this->vacanteRepository->buscarPorFuncionario((int) $funcionario->funcionario_id),
      ];
    }

    $registros = $this->filtrar($registros, $estado, $buscar);

    usort($registros, static function (array $a, array $b): int {
      $pa = $a['designacion'] === NULL ? 0 : 1;
      $pb = $b['designacion'] === NULL ? 0 : 1;
      if ($pa !== $pb) {
        return $pa <=> $pb;
      }
      return (int) $a['funcionario']->posicion <=> (int) $b['funcionario']->posicion;
    });

    return $registros;
  }

  /**
   * Filtra los registros por estado y por término de búsqueda.
   *
   * @param array $registros
   *   Lista de registros con claves «funcionario» y «designacion».
   * @param string $estado
   *   Uno de: todos, pendientes, asignados.
   * @param string $buscar
   *   Término a buscar en nombre o cédula (vacío = sin filtro).
   *
   * @return array
   *   Registros filtrados.
   */
  protected function filtrar(array $registros, string $estado, string $buscar): array {
    return array_values(array_filter($registros, static function (array $registro) use ($estado, $buscar): bool {
      $asignado = $registro['designacion'] !== NULL;
      if ($estado === 'pendientes' && $asignado) {
        return FALSE;
      }
      if ($estado === 'asignados' && !$asignado) {
        return FALSE;
      }
      if ($buscar !== '') {
        $funcionario = $registro['funcionario'];
        $aguja = mb_strtolower($buscar);
        $coincide = str_contains(mb_strtolower($funcionario->nombre), $aguja)
          || str_contains(mb_strtolower((string) $funcionario->documento), $aguja);
        if (!$coincide) {
          return FALSE;
        }
      }
      return TRUE;
    }));
  }

  /**
   * Construye el bloque de resumen de avance con barra de progreso.
   *
   * @param int $total
   *   Total de funcionarios del cargo.
   * @param int $asignados
   *   Cantidad de funcionarios ya asignados.
   * @param int $pendientes
   *   Cantidad de funcionarios pendientes.
   * @param int $libres
   *   Vacantes libres de este cargo.
   * @param int $porcentaje
   *   Porcentaje de avance (asignados sobre el total).
   *
   * @return array
   *   Render array del resumen.
   */
  protected function construirResumen(int $total, int $asignados, int $pendientes, int $libres, int $porcentaje): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<div class="audiencias-resumen">
        <p><strong>{{ "Funcionarios"|t }}:</strong> {{ total }} · <strong>{{ "Asignados"|t }}:</strong> {{ asignados }} · <strong>{{ "Pendientes"|t }}:</strong> {{ pendientes }}</p>
        <p><strong>{{ "Vacantes libres de este cargo"|t }}:</strong> {{ libres }}</p>
        {# Barra de progreso: pista suave y relleno de acento; solo el ancho es dinámico. #}
        <div class="audiencias-progreso">
          <div class="audiencias-progreso__barra" style="width:{{ porcentaje }}%;">{{ porcentaje }}%</div>
        </div>
      </div>',
      '#context' => [
        'total' => $total,
        'asignados' => $asignados,
        'pendientes' => $pendientes,
        'libres' => $libres,
        'porcentaje' => $porcentaje,
      ],
    ];
  }

  /**
   * Construye el formulario de filtros (estado y búsqueda) por GET.
   *
   * @param int $cargo_id
   *   Identificador del cargo (destino del formulario).
   * @param string $estado
   *   Estado seleccionado actualmente.
   * @param string $buscar
   *   Término de búsqueda actual.
   *
   * @return array
   *   Render array de un formulario sencillo con método GET.
   */
  protected function construirFiltros(int $cargo_id, string $estado, string $buscar): array {
    $opciones = [
      'todos' => $this->t('Todos'),
      'pendientes' => $this->t('Pendientes'),
      'asignados' => $this->t('Asignados'),
    ];

    // Cada filtro es un enlace tipo «pill»: clase base más «is-active» en el que
    // corresponde al estado actual. No se altera el comportamiento del filtrado.
    $enlaces = [];
    foreach ($opciones as $clave => $etiqueta) {
      $query = ['estado' => $clave];
      if ($buscar !== '') {
        $query['buscar'] = $buscar;
      }
      $clases = ['audiencias-filtro'];
      if ($clave === $estado) {
        $clases[] = 'is-active';
      }
      $enlaces[$clave] = [
        '#type' => 'link',
        '#title' => $etiqueta,
        '#url' => Url::fromRoute('audiencias.asignacion', ['cargo_id' => $cargo_id], ['query' => $query]),
        '#attributes' => ['class' => $clases],
      ];
    }

    return [
      '#type' => 'container',
      'titulo' => [
        '#markup' => '<p><strong>' . $this->t('Filtrar por estado:') . '</strong></p>',
      ],
      // Contenedor en línea (flex) de los filtros, sin viñetas.
      'estados' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['audiencias-filtros']],
      ] + $enlaces,
      'busqueda' => [
        '#type' => 'inline_template',
        '#template' => '<form method="get" style="margin:0 0 1rem;">
          <input type="hidden" name="estado" value="{{ estado }}" />
          <input type="text" name="buscar" value="{{ buscar }}" placeholder="{{ "Nombre o cédula"|t }}" />
          <button type="submit">{{ "Buscar"|t }}</button>
        </form>',
        '#context' => [
          'estado' => $estado,
          'buscar' => $buscar,
        ],
      ],
    ];
  }

  /**
   * Construye la tabla de funcionarios con su designación y acción.
   *
   * @param array $registros
   *   Registros (ya filtrados/ordenados) con «funcionario» y «designacion».
   * @param int $cargo_id
   *   Identificador del cargo.
   * @param array $instituciones
   *   Mapa institucion_id => nombre.
   *
   * @return array
   *   Render array de tipo «table».
   */
  protected function construirTabla(array $registros, int $cargo_id, array $instituciones): array {
    $cabecera = [
      $this->t('ID'),
      $this->t('Funcionario'),
      $this->t('Designación actual'),
      $this->t('Tipo de vacante'),
      $this->t('Denominación'),
      $this->t('Acción'),
    ];

    $filas = [];
    foreach ($registros as $registro) {
      $funcionario = $registro['funcionario'];
      $designacion = $registro['designacion'];

      // Celda «Funcionario»: nombre + cédula + posición.
      $celda_funcionario = [
        'data' => [
          '#type' => 'inline_template',
          '#template' => '<div><strong>{{ nombre }}</strong><br><small>{{ "Cédula"|t }}: {{ documento }} · {{ "Posición"|t }}: {{ posicion }}</small></div>',
          '#context' => [
            'nombre' => $funcionario->nombre,
            'documento' => $funcionario->documento,
            'posicion' => $funcionario->posicion,
          ],
        ],
      ];

      // Celda «Designación actual».
      if ($designacion !== NULL) {
        $institucion = $instituciones[$designacion->institucion_id] ?? $this->t('(institución desconocida)');
        $celda_designacion = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<span style="color:#1a7f37;font-weight:bold;">{{ institucion }} — {{ vacante }}</span>',
            '#context' => [
              'institucion' => $institucion,
              'vacante' => $designacion->nombre,
            ],
          ],
        ];
      }
      else {
        $celda_designacion = [
          'data' => [
            '#type' => 'inline_template',
            '#template' => '<span style="color:#9a6700;font-style:italic;">{{ "Pendiente / Sin asignar"|t }}</span>',
          ],
        ];
      }

      // Celdas «Tipo de vacante» y «Denominación»: salen de la MISMA vacante ya
      // cargada en «designacion» (sin consultas por fila). Para los pendientes
      // (sin vacante) se muestra un guion largo en ambas.
      if ($designacion !== NULL) {
        $denominacion_legible = $this->denominacionLegible($designacion->denominacion ?? '');
        $celda_tipo = ['data' => $designacion->tipo_vacante ?: '—'];
        $celda_denominacion = ['data' => $denominacion_legible !== '' ? $denominacion_legible : '—'];
      }
      else {
        $celda_tipo = ['data' => '—'];
        $celda_denominacion = ['data' => '—'];
      }

      // Celda «Acción».
      if ($designacion === NULL) {
        // Funcionario pendiente: se incrusta el formulario por fila.
        /** @var \Drupal\audiencias\Form\AsignarFilaForm $form_object */
        $form_object = $this->classResolver->getInstanceFromDefinition(AsignarFilaForm::class);
        $form_object->setFuncionarioId((int) $funcionario->funcionario_id);
        $celda_accion = [
          'data' => $this->formBuilder->getForm($form_object, (int) $funcionario->funcionario_id, $cargo_id),
        ];
      }
      else {
        // Funcionario asignado: enlace para reversar, con regreso a esta pantalla.
        $destino = Url::fromRoute('audiencias.asignacion', ['cargo_id' => $cargo_id])->toString();
        $celda_accion = [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Reversar'),
            '#url' => Url::fromRoute('audiencias.vacante.desasignar', [
              'vacante_id' => $designacion->vacante_id,
            ], [
              'query' => ['destination' => $destino],
            ]),
          ],
        ];
      }

      $filas[] = [
        $funcionario->funcionario_id,
        $celda_funcionario,
        $celda_designacion,
        $celda_tipo,
        $celda_denominacion,
        $celda_accion,
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $cabecera,
      '#rows' => $filas,
    ];
  }

  /**
   * Construye el panel «Sorteo de vacantes».
   *
   * Muestra los conteos de funcionarios pendientes y de vacantes disponibles del
   * cargo. Si hay pendientes, ofrece el botón «Realizar sorteo» hacia la ruta del
   * formulario de sorteo; si no, muestra que no hay nada por sortear.
   *
   * @param int $cargo_id
   *   Identificador del cargo de la pantalla.
   * @param int $pendientes
   *   Cantidad de funcionarios pendientes del cargo.
   * @param int $libres
   *   Cantidad de vacantes disponibles del cargo.
   *
   * @return array
   *   Render array del panel de sorteo.
   */
  protected function construirSorteo(int $cargo_id, int $pendientes, int $libres): array {
    $panel = [
      '#type' => 'container',
      '#attributes' => ['class' => ['audiencias-sorteo']],
      'titulo' => [
        '#markup' => '<h3>' . $this->t('Sorteo de vacantes') . '</h3>',
      ],
      'conteos' => [
        '#markup' => '<p>' . $this->t('Funcionarios pendientes: <strong>@p</strong> · Vacantes disponibles: <strong>@v</strong>', [
          '@p' => $pendientes,
          '@v' => $libres,
        ]) . '</p>',
      ],
    ];

    if ($pendientes < 1) {
      // Sin pendientes no se muestra el botón.
      $panel['mensaje'] = [
        '#markup' => '<p><em>' . $this->t('No hay pendientes por sortear') . '</em></p>',
      ];
      return $panel;
    }

    $panel['boton'] = [
      '#type' => 'link',
      '#title' => $this->t('Realizar sorteo'),
      '#url' => Url::fromRoute('audiencias.asignacion.sorteo', ['cargo_id' => $cargo_id]),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    return $panel;
  }

  /**
   * Construye el bloque con los tres botones de exportación a Excel.
   *
   * Cada botón apunta a la ruta de exportación con un estado fijo, de modo que
   * exporta el listado completo de ese estado (independiente del filtro visual).
   *
   * @param int $cargo_id
   *   Identificador del cargo de la pantalla.
   *
   * @return array
   *   Render array del grupo de botones de exportación.
   */
  protected function construirExportar(int $cargo_id): array {
    $estados = [
      'todos' => $this->t('Exportar Todos'),
      'pendientes' => $this->t('Exportar Pendientes'),
      'asignados' => $this->t('Exportar Asignados'),
    ];

    $enlaces = [];
    foreach ($estados as $clave => $etiqueta) {
      $enlaces[$clave] = [
        '#type' => 'link',
        '#title' => $etiqueta,
        '#url' => Url::fromRoute('audiencias.asignacion.exportar', ['cargo_id' => $cargo_id], [
          'query' => ['estado' => $clave],
        ]),
        '#attributes' => ['class' => ['audiencias-filtro']],
      ];
    }

    return [
      '#type' => 'container',
      'titulo' => [
        '#markup' => '<p><strong>' . $this->t('Exportar a Excel:') . '</strong></p>',
      ],
      'botones' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['audiencias-filtros']],
      ] + $enlaces,
    ];
  }

  /**
   * Exporta a un archivo .xlsx los funcionarios de un estado para un cargo.
   *
   * @param int $cargo_id
   *   Identificador del cargo a exportar.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   La petición actual (lee el parámetro «estado»).
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   Respuesta de descarga del archivo Excel generado.
   */
  public function exportar($cargo_id, Request $request): BinaryFileResponse {
    $cargo_id = (int) $cargo_id;
    $cargo = $this->cargoRepository->load($cargo_id);
    if ($cargo === NULL) {
      throw new NotFoundHttpException('El cargo indicado no existe.');
    }

    $estado = $this->normalizarEstado($request->query->get('estado', 'todos'));

    // Registros del estado solicitado (sin término de búsqueda).
    $registros = $this->obtenerRegistros($cargo_id, $estado, '');

    // Mapa de instituciones con su registro completo (nombre y ubicación).
    $instituciones = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $instituciones[$institucion->institucion_id] = $institucion;
    }

    // Construye el libro con una hoja y el encabezado en negrita.
    $libro = new Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle((string) $this->t('Asignación'));

    $cabecera = [
      (string) $this->t('ID'),
      (string) $this->t('Funcionario'),
      (string) $this->t('Cédula'),
      (string) $this->t('Posición'),
      (string) $this->t('Cargo'),
      (string) $this->t('Estado'),
      (string) $this->t('Institución'),
      (string) $this->t('Vacante'),
      (string) $this->t('Tipo de vacante'),
      (string) $this->t('Denominación'),
      (string) $this->t('Ubicación'),
    ];
    $hoja->fromArray($cabecera, NULL, 'A1');
    $hoja->getStyle('A1:K1')->getFont()->setBold(TRUE);

    // Una fila por funcionario.
    $fila = 2;
    foreach ($registros as $registro) {
      $funcionario = $registro['funcionario'];
      $designacion = $registro['designacion'];
      $asignado = $designacion !== NULL;

      // En pendientes, las columnas de designación quedan vacías.
      $institucion_nombre = '';
      $ubicacion = '';
      $vacante = '';
      $tipo_vacante = '';
      $denominacion = '';
      if ($asignado) {
        $institucion = $instituciones[$designacion->institucion_id] ?? NULL;
        $institucion_nombre = $institucion !== NULL ? $institucion->nombre : (string) $this->t('(institución desconocida)');
        $ubicacion = $institucion !== NULL ? (string) ($institucion->ubicacion ?? '') : '';
        $vacante = $designacion->nombre;
        // Tipo y denominación salen de la misma vacante ya cargada (sin consulta
        // extra); la denominación con la inicial en mayúscula.
        $tipo_vacante = (string) ($designacion->tipo_vacante ?? '');
        $denominacion = $this->denominacionLegible($designacion->denominacion ?? '');
      }

      $hoja->fromArray([
        (int) $funcionario->funcionario_id,
        $funcionario->nombre,
        // La cédula como texto para no perder ceros a la izquierda.
        (string) $funcionario->documento,
        (int) $funcionario->posicion,
        $cargo->nombre,
        $asignado ? (string) $this->t('Asignado') : (string) $this->t('Pendiente'),
        $institucion_nombre,
        $vacante,
        $tipo_vacante,
        $denominacion,
        $ubicacion,
      ], NULL, 'A' . $fila);
      // Fuerza la cédula a texto explícito.
      $hoja->getCell('C' . $fila)->setValueExplicit((string) $funcionario->documento, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      $fila++;
    }

    // Ajuste automático del ancho de columnas.
    foreach (range('A', 'K') as $columna) {
      $hoja->getColumnDimension($columna)->setAutoSize(TRUE);
    }

    // Escribe a un archivo temporal y lo entrega como descarga.
    $nombre_archivo = sprintf(
      'asignacion-%s-%s-%s.xlsx',
      $this->normalizarNombre($cargo->nombre),
      $estado,
      date('Ymd'),
    );
    $ruta_temporal = $this->fileSystem->getTempDirectory() . '/' . uniqid('audiencias_', TRUE) . '.xlsx';

    $escritor = new Xlsx($libro);
    $escritor->save($ruta_temporal);
    // Libera memoria de PhpSpreadsheet.
    $libro->disconnectWorksheets();

    $respuesta = new BinaryFileResponse($ruta_temporal);
    $respuesta->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $respuesta->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nombre_archivo);
    $respuesta->deleteFileAfterSend(TRUE);

    return $respuesta;
  }

  /**
   * Devuelve la denominación con la inicial en mayúscula.
   *
   * La denominación se guarda en minúscula («audiencia»/«sorteo»); para mostrar
   * se capitaliza la primera letra («Audiencia»/«Sorteo»). Cadena vacía o NULL
   * devuelve cadena vacía (la presentación decide el marcador de «sin valor»).
   *
   * @param string|null $denominacion
   *   Denominación cruda de la vacante.
   *
   * @return string
   *   La denominación capitalizada, o cadena vacía si no hay valor.
   */
  protected function denominacionLegible(?string $denominacion): string {
    $denominacion = trim((string) $denominacion);
    if ($denominacion === '') {
      return '';
    }
    return mb_strtoupper(mb_substr($denominacion, 0, 1)) . mb_substr($denominacion, 1);
  }

  /**
   * Normaliza un texto para usarlo en el nombre del archivo descargado.
   *
   * Pasa a minúsculas y reemplaza por guiones todo lo que no sea letra o dígito.
   *
   * @param string $texto
   *   Texto a normalizar (p. ej. el nombre del cargo).
   *
   * @return string
   *   Texto apto para un nombre de archivo.
   */
  protected function normalizarNombre(string $texto): string {
    $texto = mb_strtolower(trim($texto));
    $texto = preg_replace('/[^a-z0-9]+/u', '-', $texto);
    return trim((string) $texto, '-') ?: 'cargo';
  }

}
