<?php

declare(strict_types=1);

namespace Drupal\audiencias\Controller;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Controlador de la sección de administración del módulo Audiencias.
 *
 * Expone la página resumen y un listado por entidad. Obtiene los datos
 * exclusivamente a través de la capa de repositorios; no ejecuta SQL.
 */
class AudienciasController extends ControllerBase {

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
   * Repositorio de vacantes.
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * Sistema de archivos (para el directorio temporal de la exportación).
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Construye el controlador con sus repositorios.
   *
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   Servicio del sistema de archivos.
   */
  public function __construct(
    InstitucionRepository $institucion_repository,
    CargoRepository $cargo_repository,
    FuncionarioRepository $funcionario_repository,
    VacanteRepository $vacante_repository,
    FileSystemInterface $file_system,
  ) {
    $this->institucionRepository = $institucion_repository;
    $this->cargoRepository = $cargo_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->vacanteRepository = $vacante_repository;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.institucion_repository'),
      $container->get('audiencias.cargo_repository'),
      $container->get('audiencias.funcionario_repository'),
      $container->get('audiencias.vacante_repository'),
      $container->get('file_system'),
    );
  }

  /**
   * Página resumen con enlaces a las cuatro secciones de gestión.
   *
   * @return array
   *   Render array con la lista de enlaces a cada listado.
   */
  public function overview(): array {
    $secciones = [
      [
        'titulo' => $this->t('Instituciones'),
        'ruta' => 'audiencias.institucion.list',
        'descripcion' => $this->t('Gestione las instituciones del municipio.'),
      ],
      [
        'titulo' => $this->t('Cargos'),
        'ruta' => 'audiencias.cargo.list',
        'descripcion' => $this->t('Gestione los cargos administrativos.'),
      ],
      [
        'titulo' => $this->t('Funcionarios'),
        'ruta' => 'audiencias.funcionario.list',
        'descripcion' => $this->t('Gestione los funcionarios candidatos a vacantes.'),
      ],
      [
        'titulo' => $this->t('Vacantes'),
        'ruta' => 'audiencias.vacante.list',
        'descripcion' => $this->t('Gestione las vacantes administrativas y su asignación.'),
      ],
    ];

    $items = [];
    foreach ($secciones as $seccion) {
      $items[] = [
        '#type' => 'link',
        '#title' => $seccion['titulo'],
        '#url' => Url::fromRoute($seccion['ruta']),
        '#suffix' => ' — ' . $seccion['descripcion'],
      ];
    }

    // -------------------------------------------------------------------------
    // PROVISIONAL: accesos directos a la pantalla de asignación por cargo.
    // Pensados para pruebas; retirar este bloque (y «accesos» del return) cuando
    // exista una navegación definitiva a las audiencias. Se resuelve el cargo_id
    // por nombre para no depender de identificadores fijos; si no se resuelve,
    // se usa el respaldo (Secretario = 2, Auxiliar Administrativo = 1).
    // -------------------------------------------------------------------------
    $atajos = [
      ['titulo' => $this->t('Audiencia Secretario'), 'nombre' => 'Secretario', 'respaldo' => 2],
      ['titulo' => $this->t('Audiencia Auxiliar Administrativo'), 'nombre' => 'Auxiliar Administrativo', 'respaldo' => 1],
    ];

    $items_atajos = [];
    foreach ($atajos as $atajo) {
      $cargo = $this->cargoRepository->buscarPorNombre($atajo['nombre']);
      $cargo_id = $cargo !== NULL ? (int) $cargo->cargo_id : $atajo['respaldo'];
      $items_atajos[] = [
        '#type' => 'link',
        '#title' => $atajo['titulo'],
        '#url' => Url::fromRoute('audiencias.asignacion', ['cargo_id' => $cargo_id]),
      ];
    }

    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('Seleccione una sección para gestionar la información de vacantes administrativas.') . '</p>',
      ],
      'secciones' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
      // PROVISIONAL: bloque de accesos directos a las audiencias por cargo.
      'accesos' => [
        'titulo' => [
          '#markup' => '<h2>' . $this->t('Accesos directos (provisional)') . '</h2>',
        ],
        'lista' => [
          '#theme' => 'item_list',
          '#items' => $items_atajos,
        ],
      ],
    ];
  }

  /**
   * Exporta a un archivo .xlsx todas las instituciones registradas.
   *
   * Construye un libro con una hoja (ID, Nombre, Ubicación), encabezado en
   * negrita y ancho de columnas automático, y lo entrega como descarga. Los
   * datos se obtienen solo a través del repositorio.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   Respuesta de descarga del archivo Excel generado.
   */
  public function exportarInstituciones(): BinaryFileResponse {
    $registros = $this->institucionRepository->loadAll();

    // Construye el libro con una hoja y el encabezado en negrita.
    $libro = new Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle((string) $this->t('Instituciones'));

    $cabecera = [
      (string) $this->t('ID'),
      (string) $this->t('Nombre'),
      (string) $this->t('Ubicación'),
    ];
    $hoja->fromArray($cabecera, NULL, 'A1');
    $hoja->getStyle('A1:C1')->getFont()->setBold(TRUE);

    // Una fila por institución.
    $fila = 2;
    foreach ($registros as $registro) {
      $hoja->fromArray([
        (int) $registro->institucion_id,
        $registro->nombre,
        (string) ($registro->ubicacion ?? ''),
      ], NULL, 'A' . $fila);
      $fila++;
    }

    // Ajuste automático del ancho de columnas.
    foreach (range('A', 'C') as $columna) {
      $hoja->getColumnDimension($columna)->setAutoSize(TRUE);
    }

    // Escribe a un archivo temporal y lo entrega como descarga.
    $nombre_archivo = sprintf('instituciones-%s.xlsx', date('Ymd'));
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
   * Exporta a un archivo .xlsx todas las vacantes con sus campos resueltos.
   *
   * Resuelve las llaves foráneas (cargo, institución, funcionario) a valores
   * legibles. Para evitar el problema N+1, carga UNA sola vez instituciones,
   * cargos y funcionarios en mapas indexados por su id y resuelve cada vacante
   * en memoria (sin una consulta por fila).
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   Respuesta de descarga del archivo Excel generado.
   */
  public function exportarVacantes(): BinaryFileResponse {
    $vacantes = $this->vacanteRepository->loadAll();

    // Mapas id => registro, cargados una sola vez (evita el N+1).
    $instituciones = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $instituciones[(int) $institucion->institucion_id] = $institucion;
    }
    $cargos = [];
    foreach ($this->cargoRepository->loadAll() as $cargo) {
      $cargos[(int) $cargo->cargo_id] = $cargo;
    }
    $funcionarios = [];
    foreach ($this->funcionarioRepository->loadAll() as $funcionario) {
      $funcionarios[(int) $funcionario->funcionario_id] = $funcionario;
    }

    // Construye el libro con una hoja y el encabezado en negrita.
    $libro = new Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle((string) $this->t('Vacantes'));

    $cabecera = [
      (string) $this->t('ID'),
      (string) $this->t('Nombre'),
      (string) $this->t('Cargo'),
      (string) $this->t('Tipo de vacante'),
      (string) $this->t('Institución'),
      (string) $this->t('Ubicación'),
      (string) $this->t('Estado'),
      (string) $this->t('Funcionario asignado'),
      (string) $this->t('Cédula'),
      (string) $this->t('Denominación'),
    ];
    $hoja->fromArray($cabecera, NULL, 'A1');
    $hoja->getStyle('A1:J1')->getFont()->setBold(TRUE);

    // Una fila por vacante, resolviendo cada FK desde los mapas en memoria.
    $fila = 2;
    foreach ($vacantes as $vacante) {
      $cargo = $cargos[(int) $vacante->cargo_id] ?? NULL;
      $institucion = $instituciones[(int) $vacante->institucion_id] ?? NULL;

      $nombre_cargo = $cargo !== NULL ? $cargo->nombre : (string) $this->t('(desconocido)');
      $nombre_institucion = $institucion !== NULL ? $institucion->nombre : (string) $this->t('(desconocida)');
      $ubicacion = $institucion !== NULL ? (string) ($institucion->ubicacion ?? '') : '';
      $estado = $vacante->estado ? (string) $this->t('Activo') : (string) $this->t('Inactivo');

      // Funcionario asignado: nombre y cédula, o «Sin asignar» y cédula vacía.
      $asignado = !empty($vacante->funcionario_id) && isset($funcionarios[(int) $vacante->funcionario_id]);
      $nombre_funcionario = $asignado
        ? $funcionarios[(int) $vacante->funcionario_id]->nombre
        : (string) $this->t('Sin asignar');
      $documento = $asignado ? (string) $funcionarios[(int) $vacante->funcionario_id]->documento : '';

      // Tipo de vacante tal cual (Ascenso/Abierto) y denominación con inicial
      // mayúscula (Audiencia/Sorteo) o «—» si está vacía o NULL.
      $tipo_vacante = (string) ($vacante->tipo_vacante ?? '');
      $denominacion = trim((string) ($vacante->denominacion ?? ''));
      $denominacion = $denominacion !== '' ? ucfirst($denominacion) : '—';

      $hoja->fromArray([
        (int) $vacante->vacante_id,
        $vacante->nombre,
        $nombre_cargo,
        $tipo_vacante,
        $nombre_institucion,
        $ubicacion,
        $estado,
        $nombre_funcionario,
        $documento,
        $denominacion,
      ], NULL, 'A' . $fila);
      // Fuerza la cédula a texto explícito para no perder ceros a la izquierda.
      $hoja->getCell('I' . $fila)->setValueExplicit($documento, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
      $fila++;
    }

    // Ajuste automático del ancho de columnas.
    foreach (range('A', 'J') as $columna) {
      $hoja->getColumnDimension($columna)->setAutoSize(TRUE);
    }

    // Escribe a un archivo temporal y lo entrega como descarga.
    $nombre_archivo = sprintf('vacantes-%s.xlsx', date('Ymd'));
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
   * Listado de cargos (solo lectura) con paginación real.
   *
   * @return array
   *   Render array con una tabla paginada de cargos; el paginador es funcional
   *   porque los datos se cargan página a página vía loadAllPaginado().
   */
  public function listarCargos(): array {
    $registros = $this->cargoRepository->loadAllPaginado(50);

    $cabecera = [
      $this->t('ID'),
      $this->t('Nombre'),
      $this->t('Estado'),
      $this->t('Operaciones'),
    ];

    $filas = [];
    foreach ($registros as $registro) {
      $filas[] = [
        $registro->cargo_id,
        $registro->nombre,
        $registro->estado ? $this->t('Activo') : $this->t('Inactivo'),
        $this->celdaOperaciones('cargo', $registro->cargo_id),
      ];
    }

    return $this->construirTabla($cabecera, $filas, $this->t('No hay cargos registrados.'));
  }

  /**
   * Construye la celda de operaciones (Editar / Borrar) para una fila.
   *
   * @param string $entidad
   *   Nombre de la entidad: institucion, cargo, funcionario o vacante.
   * @param int|string $id
   *   Identificador del registro.
   * @param array $extra
   *   Enlaces adicionales a anteponer (clave => ['title' => ..., 'url' => ...]).
   *
   * @return array
   *   Render array de tipo «operations» con los enlaces de la fila.
   */
  protected function celdaOperaciones(string $entidad, $id, array $extra = []): array {
    $enlaces = $extra + [
      'editar' => [
        'title' => $this->t('Editar'),
        'url' => Url::fromRoute('audiencias.' . $entidad . '.edit', ['id' => $id]),
      ],
      'borrar' => [
        'title' => $this->t('Borrar'),
        'url' => Url::fromRoute('audiencias.' . $entidad . '.delete', ['id' => $id]),
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
   * Construye un render array de tabla con paginador.
   *
   * @param array $cabecera
   *   Encabezados de columna.
   * @param array $filas
   *   Filas de datos ya preparadas.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $vacia
   *   Mensaje a mostrar cuando no hay registros.
   *
   * @return array
   *   Render array con la tabla y el paginador.
   */
  protected function construirTabla(array $cabecera, array $filas, $vacia): array {
    return [
      'tabla' => [
        '#type' => 'table',
        '#header' => $cabecera,
        '#rows' => $filas,
        '#empty' => $vacia,
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];
  }

}
