<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Formulario de asignación inmediata de una vacante a un funcionario, por fila.
 *
 * Se incrusta una instancia por cada funcionario pendiente en la pantalla de
 * asignación. El desplegable ofrece únicamente vacantes del mismo cargo y
 * disponibles, agrupadas por institución. Revalida en el envío para tolerar
 * concurrencia.
 */
class AsignarFilaForm extends FormBase {

  /**
   * Repositorio de vacantes.
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * Repositorio de funcionarios.
   *
   * @var \Drupal\audiencias\Repository\FuncionarioRepository
   */
  protected FuncionarioRepository $funcionarioRepository;

  /**
   * Repositorio de instituciones.
   *
   * @var \Drupal\audiencias\Repository\InstitucionRepository
   */
  protected InstitucionRepository $institucionRepository;

  /**
   * Identificador del funcionario de esta fila.
   *
   * Se fija antes de construir el formulario para que cada instancia tenga un
   * identificador de formulario único.
   *
   * @var int|null
   */
  protected ?int $funcionarioId = NULL;

  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    VacanteRepository $vacante_repository,
    FuncionarioRepository $funcionario_repository,
    InstitucionRepository $institucion_repository,
    MessengerInterface $messenger,
  ) {
    $this->vacanteRepository = $vacante_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->institucionRepository = $institucion_repository;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.vacante_repository'),
      $container->get('audiencias.funcionario_repository'),
      $container->get('audiencias.institucion_repository'),
      $container->get('messenger'),
    );
  }

  /**
   * Fija el funcionario de esta fila (para el identificador único del formulario).
   *
   * @param int $funcionario_id
   *   Identificador del funcionario.
   *
   * @return $this
   */
  public function setFuncionarioId(int $funcionario_id): static {
    $this->funcionarioId = $funcionario_id;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_asignar_fila_' . ($this->funcionarioId ?? 0);
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $funcionario_id
   *   Identificador del funcionario al que se asignará la vacante.
   * @param int|null $cargo_id
   *   Identificador del cargo del funcionario (filtra las vacantes ofrecidas).
   */
  public function buildForm(array $form, FormStateInterface $form_state, $funcionario_id = NULL, $cargo_id = NULL): array {
    $funcionario_id = (int) $funcionario_id;
    $cargo_id = (int) $cargo_id;

    // Conserva los identificadores a través del ciclo de vida del formulario.
    $form['funcionario_id'] = [
      '#type' => 'value',
      '#value' => $funcionario_id,
    ];
    $form['cargo_id'] = [
      '#type' => 'value',
      '#value' => $cargo_id,
    ];

    // Mapa de instituciones para etiquetar y agrupar las opciones. Se reutiliza
    // el registro completo para incluir la ubicación en la etiqueta del grupo
    // (optgroup), conservando aparte el nombre simple para la opción interna.
    $nombres = [];
    $grupos = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $nombres[$institucion->institucion_id] = $institucion->nombre;
      // Etiqueta del grupo: "{institución} — {ubicación}"; si no hay ubicación,
      // solo el nombre (sin guion).
      $ubicacion = trim((string) ($institucion->ubicacion ?? ''));
      $grupos[$institucion->institucion_id] = $ubicacion !== ''
        ? $this->t('@institucion — @ubicacion', [
          '@institucion' => $institucion->nombre,
          '@ubicacion' => $ubicacion,
        ])
        : $institucion->nombre;
    }

    // Opciones del desplegable: solo vacantes disponibles del cargo, agrupadas
    // por institución (optgroups). El grupo lleva la ubicación; la opción interna
    // conserva el nombre simple de la institución.
    $opciones = [];
    foreach ($this->vacanteRepository->disponiblesPorCargo($cargo_id) as $vacante) {
      $grupo = $grupos[$vacante->institucion_id] ?? $this->t('(institución desconocida)');
      $institucion = $nombres[$vacante->institucion_id] ?? $this->t('(institución desconocida)');
      $opciones[(string) $grupo][$vacante->vacante_id] = $this->t('@institucion — @vacante', [
        '@institucion' => $institucion,
        '@vacante' => $vacante->nombre,
      ]);
    }

    $form['vacante_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Vacante a asignar'),
      '#title_display' => 'invisible',
      '#options' => $opciones,
      '#empty_value' => '',
      '#empty_option' => $this->t('— Seleccione una vacante —'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Asignar'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Revalida la disponibilidad y la coincidencia de cargo aunque el desplegable
   * ya filtre, para tolerar la concurrencia entre varias asignaciones.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $vacante_id = (int) $form_state->getValue('vacante_id');
    $funcionario_id = (int) $form_state->getValue('funcionario_id');

    $vacante = $this->vacanteRepository->load($vacante_id);
    if ($vacante === NULL) {
      $form_state->setErrorByName('vacante_id', $this->t('La vacante seleccionada no existe.'));
      return;
    }

    // 1. La vacante debe estar activa: una inactiva no se asigna.
    if ((int) $vacante->estado !== 1) {
      $form_state->setErrorByName('vacante_id', $this->t('La vacante «@vacante» está inactiva; no se puede asignar.', [
        '@vacante' => $vacante->nombre,
      ]));
      return;
    }

    // 2. La vacante debe seguir disponible (funcionario_id NULL).
    if (!empty($vacante->funcionario_id)) {
      $form_state->setErrorByName('vacante_id', $this->t('Esa vacante ya fue asignada; recargue la pantalla.'));
      return;
    }

    // 3. El cargo de la vacante debe coincidir con el del funcionario.
    $funcionario = $this->funcionarioRepository->load($funcionario_id);
    if ($funcionario === NULL) {
      $form_state->setErrorByName('vacante_id', $this->t('El funcionario indicado no existe.'));
      return;
    }
    if ((int) $vacante->cargo_id !== (int) $funcionario->cargo_id) {
      $form_state->setErrorByName('vacante_id', $this->t('La vacante pertenece a otro cargo distinto al del funcionario; no se puede asignar.'));
      return;
    }

    // 4. El funcionario no debe estar ya asignado a otra vacante.
    $asignada = $this->vacanteRepository->buscarPorFuncionario($funcionario_id);
    if ($asignada !== NULL) {
      $form_state->setErrorByName('vacante_id', $this->t('El funcionario ya está asignado a la vacante «@vacante». Reverse esa asignación antes de reasignarlo.', [
        '@vacante' => $asignada->nombre,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vacante_id = (int) $form_state->getValue('vacante_id');
    $funcionario_id = (int) $form_state->getValue('funcionario_id');
    $cargo_id = (int) $form_state->getValue('cargo_id');

    // Asignación por botones: la denominación es «audiencia». Se comprueba el
    // resultado porque la validación y el guardado no son simultáneos: otro
    // proceso pudo tomar la vacante en el intervalo.
    try {
      $afectadas = $this->vacanteRepository->asignar($vacante_id, $funcionario_id, 'audiencia');
    }
    catch (IntegrityConstraintViolationException $e) {
      // Saltó la llave única sobre «funcionario_id»: al funcionario le
      // asignaron otra vacante en paralelo.
      $this->messenger->addError($this->t('No se pudo asignar: al funcionario le asignaron otra vacante mientras tanto. Actualice la pantalla y vuelva a intentarlo.'));
      $form_state->setRedirect('audiencias.asignacion', ['cargo_id' => $cargo_id]);
      return;
    }

    // Cero filas afectadas: la vacante ya está ocupada por OTRO funcionario.
    if ($afectadas === 0) {
      $this->messenger->addError($this->t('La vacante ya fue asignada a otro funcionario. Actualice la pantalla y vuelva a intentarlo.'));
      $form_state->setRedirect('audiencias.asignacion', ['cargo_id' => $cargo_id]);
      return;
    }

    $funcionario = $this->funcionarioRepository->load($funcionario_id);
    $vacante = $this->vacanteRepository->load($vacante_id);
    // Mensaje de éxito descriptivo: funcionario, vacante, institución y ubicación.
    $nombre_funcionario = $funcionario !== NULL ? $funcionario->nombre : (string) $funcionario_id;
    $this->messenger->addStatus($this->mensajeAsignacion($nombre_funcionario, $vacante, (int) $vacante_id));

    // Vuelve a la pantalla del mismo cargo para refrescar el tablero.
    $form_state->setRedirect('audiencias.asignacion', ['cargo_id' => $cargo_id]);
  }

  /**
   * Construye el mensaje de éxito descriptivo de una asignación.
   *
   * Incluye el funcionario, la vacante, la institución y —si existe— la
   * ubicación de la institución. Todos los valores se pasan como placeholders
   * (@funcionario, @vacante, @institucion, @ubicacion) para que se escapen.
   *
   * @param string $funcionario
   *   Nombre del funcionario asignado.
   * @param object|null $vacante
   *   Registro de la vacante (se usa su nombre e institucion_id).
   * @param int $vacante_id
   *   Identificador de la vacante (respaldo si el registro no cargó).
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   El mensaje traducible, en su variante con o sin ubicación.
   */
  protected function mensajeAsignacion(string $funcionario, ?object $vacante, int $vacante_id) {
    $nombre_vacante = $vacante !== NULL ? $vacante->nombre : (string) $vacante_id;

    // Resuelve la institución de la vacante para nombrarla y ubicarla.
    $institucion = $vacante !== NULL ? $this->institucionRepository->load((int) $vacante->institucion_id) : NULL;
    $nombre_institucion = $institucion !== NULL ? $institucion->nombre : (string) $this->t('(institución desconocida)');
    $ubicacion = $institucion !== NULL ? trim((string) ($institucion->ubicacion ?? '')) : '';

    // Con ubicación: variante completa.
    if ($ubicacion !== '') {
      return $this->t('Se asignó a @funcionario la vacante «@vacante» de la institución @institucion, ubicada en @ubicacion.', [
        '@funcionario' => $funcionario,
        '@vacante' => $nombre_vacante,
        '@institucion' => $nombre_institucion,
        '@ubicacion' => $ubicacion,
      ]);
    }

    // Sin ubicación: variante corta, sin la parte «, ubicada en ...».
    return $this->t('Se asignó a @funcionario la vacante «@vacante» de la institución @institucion.', [
      '@funcionario' => $funcionario,
      '@vacante' => $nombre_vacante,
      '@institucion' => $nombre_institucion,
    ]);
  }

}
