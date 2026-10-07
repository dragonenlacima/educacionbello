<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de asignación de un funcionario a una vacante.
 *
 * Segundo paso del flujo de la vacante: recibe la vacante por la ruta y postula
 * un funcionario por su cédula. Aplica validaciones estrictas (existencia del
 * funcionario, coincidencia de cargo y disponibilidad) antes de asignar.
 */
class VacanteAsignarForm extends FormBase {

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
   * Repositorio de cargos.
   *
   * @var \Drupal\audiencias\Repository\CargoRepository
   */
  protected CargoRepository $cargoRepository;

  /**
   * Repositorio de instituciones.
   *
   * @var \Drupal\audiencias\Repository\InstitucionRepository
   */
  protected InstitucionRepository $institucionRepository;

  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    VacanteRepository $vacante_repository,
    FuncionarioRepository $funcionario_repository,
    CargoRepository $cargo_repository,
    InstitucionRepository $institucion_repository,
    MessengerInterface $messenger,
  ) {
    $this->vacanteRepository = $vacante_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->cargoRepository = $cargo_repository;
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
      $container->get('audiencias.cargo_repository'),
      $container->get('audiencias.institucion_repository'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_vacante_asignar_form';
  }

  /**
   * Devuelve el nombre de un cargo a partir de su identificador.
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   *
   * @return string
   *   Nombre del cargo, o una cadena con el identificador si no se encuentra.
   */
  protected function nombreCargo(int $cargo_id): string {
    $cargo = $this->cargoRepository->load($cargo_id);
    return $cargo !== NULL ? $cargo->nombre : (string) $cargo_id;
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $vacante_id
   *   Identificador de la vacante a la que se asignará el funcionario.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $vacante_id = NULL): array {
    $vacante = $vacante_id !== NULL ? $this->vacanteRepository->load($vacante_id) : NULL;
    if ($vacante === NULL) {
      throw new NotFoundHttpException('La vacante indicada no existe.');
    }

    // Conserva el identificador de la vacante a través del ciclo del formulario.
    $form['vacante_id'] = [
      '#type' => 'value',
      '#value' => $vacante->vacante_id,
    ];

    // Información de contexto sobre la vacante y el cargo requerido.
    $form['resumen'] = [
      '#markup' => '<p>' . $this->t('Vacante: <strong>@vacante</strong>. Cargo requerido: <strong>@cargo</strong>.', [
        '@vacante' => $vacante->nombre,
        '@cargo' => $this->nombreCargo((int) $vacante->cargo_id),
      ]) . '</p>',
    ];

    // Una vacante inactiva no se asigna: se avisa y no se ofrece el formulario.
    if ((int) $vacante->estado !== 1) {
      $form['inactiva'] = [
        '#markup' => '<p>' . $this->t('La vacante está inactiva; no se puede asignar. Actívela antes de asignarle un funcionario.') . '</p>',
      ];
      return $form;
    }

    $form['documento'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Documento (cédula) del funcionario'),
      '#required' => TRUE,
      '#maxlength' => 20,
      '#description' => $this->t('Cédula del funcionario que se postulará a esta vacante.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Asignar funcionario'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Valida que el funcionario exista, que su cargo coincida con el de la
   * vacante y que no esté ya asignado a otra vacante.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $vacante = $this->vacanteRepository->load($form_state->getValue('vacante_id'));
    if ($vacante === NULL) {
      $form_state->setErrorByName('documento', $this->t('La vacante indicada no existe.'));
      return;
    }

    // 1. La vacante debe estar activa: una inactiva no se asigna.
    if ((int) $vacante->estado !== 1) {
      $form_state->setErrorByName('documento', $this->t('La vacante «@vacante» está inactiva; no se puede asignar.', [
        '@vacante' => $vacante->nombre,
      ]));
      return;
    }

    $documento = trim((string) $form_state->getValue('documento'));

    // 2. El funcionario debe existir.
    $funcionario = $this->funcionarioRepository->buscarPorDocumento($documento);
    if ($funcionario === NULL) {
      $form_state->setErrorByName('documento', $this->t('No existe un funcionario con la cédula indicada.'));
      return;
    }

    // 3. El cargo del funcionario debe coincidir con el de la vacante.
    if ((int) $funcionario->cargo_id !== (int) $vacante->cargo_id) {
      $form_state->setErrorByName('documento', $this->t('El funcionario tiene el cargo «@cargo_funcionario», pero la vacante requiere «@cargo_vacante». Los cargos no coinciden.', [
        '@cargo_funcionario' => $this->nombreCargo((int) $funcionario->cargo_id),
        '@cargo_vacante' => $this->nombreCargo((int) $vacante->cargo_id),
      ]));
      return;
    }

    // 4. La vacante no debe estar ya ocupada por OTRO funcionario. El mismo
    // funcionario sobre su propia vacante sigue siendo válido (reenvío
    // idempotente, que además permite reescribir la «denominacion»).
    if (!empty($vacante->funcionario_id) && (int) $vacante->funcionario_id !== (int) $funcionario->funcionario_id) {
      $ocupante = $this->funcionarioRepository->load((int) $vacante->funcionario_id);
      $form_state->setErrorByName('documento', $this->t('La vacante «@vacante» ya está ocupada por el funcionario @nombre (cédula @documento). Reverse esa asignación antes de asignarla a otro funcionario.', [
        '@vacante' => $vacante->nombre,
        '@nombre' => $ocupante !== NULL ? $ocupante->nombre : $this->t('(desconocido)'),
        '@documento' => $ocupante !== NULL ? $ocupante->documento : '—',
      ]));
      return;
    }

    // 5. El funcionario no debe estar ya asignado a otra vacante.
    $asignada = $this->vacanteRepository->buscarPorFuncionario((int) $funcionario->funcionario_id);
    if ($asignada !== NULL && (int) $asignada->vacante_id !== (int) $vacante->vacante_id) {
      $form_state->setErrorByName('documento', $this->t('El funcionario @nombre (cédula @documento) ya está asignado a la vacante «@vacante». Libérelo antes de reasignarlo.', [
        '@nombre' => $funcionario->nombre,
        '@documento' => $funcionario->documento,
        '@vacante' => $asignada->nombre,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $vacante_id = $form_state->getValue('vacante_id');
    $documento = trim((string) $form_state->getValue('documento'));
    $funcionario = $this->funcionarioRepository->buscarPorDocumento($documento);

    // Asignación por botones (cédula): la denominación es «audiencia». Se
    // comprueba el resultado porque la validación y el guardado no son
    // simultáneos: otro proceso pudo tomar la vacante en el intervalo.
    try {
      $afectadas = $this->vacanteRepository->asignar((int) $vacante_id, (int) $funcionario->funcionario_id, 'audiencia');
    }
    catch (IntegrityConstraintViolationException $e) {
      // Saltó la llave única sobre «funcionario_id»: al funcionario le
      // asignaron otra vacante en paralelo.
      $this->messenger->addError($this->t('No se pudo asignar: al funcionario @nombre (cédula @documento) le asignaron otra vacante mientras tanto. Actualice la pantalla y vuelva a intentarlo.', [
        '@nombre' => $funcionario->nombre,
        '@documento' => $funcionario->documento,
      ]));
      $form_state->setRedirect('audiencias.vacante.list');
      return;
    }

    // Cero filas afectadas: la vacante ya está ocupada por OTRO funcionario.
    if ($afectadas === 0) {
      $this->messenger->addError($this->t('La vacante ya fue asignada a otro funcionario. Actualice la pantalla y vuelva a intentarlo.'));
      $form_state->setRedirect('audiencias.vacante.list');
      return;
    }

    // Mensaje de éxito descriptivo: funcionario, vacante, institución y ubicación.
    $vacante = $this->vacanteRepository->load((int) $vacante_id);
    $this->messenger->addStatus($this->mensajeAsignacion($funcionario->nombre, $vacante, (int) $vacante_id));
    $form_state->setRedirect('audiencias.vacante.list');
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
