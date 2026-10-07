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
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de alta y edición de vacantes (sin asignación de funcionario).
 *
 * La asignación del funcionario es un paso posterior (VacanteAsignarForm), por
 * lo que aquí «funcionario_id» permanece sin modificarse: NULL al crear.
 */
class VacanteForm extends FormBase {

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
   * Construye el formulario.
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
    return 'audiencias_vacante_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $id
   *   Identificador de la vacante a editar, o NULL para alta.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $registro = $id !== NULL ? $this->vacanteRepository->load($id) : NULL;
    if ($id !== NULL && $registro === NULL) {
      throw new NotFoundHttpException('La vacante indicada no existe.');
    }

    // Opciones de institución (institucion_id => nombre).
    $opciones_institucion = [];
    foreach ($this->institucionRepository->loadAll() as $institucion) {
      $opciones_institucion[$institucion->institucion_id] = $institucion->nombre;
    }

    // Opciones de cargo (cargo_id => nombre).
    $opciones_cargo = [];
    foreach ($this->cargoRepository->loadAll() as $cargo) {
      $opciones_cargo[$cargo->cargo_id] = $cargo->nombre;
    }

    $form['vacante_id'] = [
      '#type' => 'value',
      '#value' => $registro->vacante_id ?? NULL,
    ];

    $form['nombre'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre'),
      '#required' => TRUE,
      '#maxlength' => 120,
      '#default_value' => $registro->nombre ?? '',
    ];

    $form['tipo_vacante'] = [
      '#type' => 'select',
      '#title' => $this->t('Tipo de vacante'),
      '#required' => TRUE,
      '#options' => [
        'Ascenso' => $this->t('Ascenso'),
        'Abierto' => $this->t('Abierto'),
      ],
      // Sin valor por defecto al crear: el operador debe elegir el tipo de forma
      // explícita. En edición se muestra el valor guardado.
      '#empty_option' => $this->t('- Seleccione -'),
      '#default_value' => $registro->tipo_vacante ?? NULL,
    ];

    $form['institucion_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Institución'),
      '#required' => TRUE,
      '#options' => $opciones_institucion,
      '#empty_option' => $this->t('- Seleccione -'),
      '#default_value' => $registro->institucion_id ?? NULL,
    ];

    $form['cargo_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Cargo'),
      '#required' => TRUE,
      '#options' => $opciones_cargo,
      '#empty_option' => $this->t('- Seleccione -'),
      '#default_value' => $registro->cargo_id ?? NULL,
    ];

    $form['estado'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activo'),
      '#default_value' => $registro->estado ?? 1,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Guardar'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Exige un tipo de vacante válido y no permite desactivar una vacante que
   * esté asignada: una vacante inactiva debe estar siempre libre.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $tipo_vacante = (string) $form_state->getValue('tipo_vacante');
    if (!in_array($tipo_vacante, ['Ascenso', 'Abierto'], TRUE)) {
      $form_state->setErrorByName('tipo_vacante', $this->t('Seleccione el tipo de vacante: «Ascenso» o «Abierto».'));
    }

    $id = $form_state->getValue('vacante_id');
    if (!empty($id) && (int) $form_state->getValue('estado') !== 1) {
      $vacante = $this->vacanteRepository->load($id);
      if ($vacante !== NULL && !empty($vacante->funcionario_id)) {
        $funcionario = $this->funcionarioRepository->load((int) $vacante->funcionario_id);
        $form_state->setErrorByName('estado', $this->t('La vacante está asignada a @nombre (cédula @documento). Reverse la asignación antes de desactivarla.', [
          '@nombre' => $funcionario !== NULL ? $funcionario->nombre : $this->t('(desconocido)'),
          '@documento' => $funcionario !== NULL ? $funcionario->documento : '—',
        ]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $valores = [
      'nombre' => trim((string) $form_state->getValue('nombre')),
      'tipo_vacante' => (string) $form_state->getValue('tipo_vacante'),
      'institucion_id' => (int) $form_state->getValue('institucion_id'),
      'cargo_id' => (int) $form_state->getValue('cargo_id'),
      'estado' => (int) $form_state->getValue('estado'),
    ];

    $id = $form_state->getValue('vacante_id');
    if (!empty($id)) {
      // En edición no se toca «funcionario_id»: la asignación es aparte.
      $valores['vacante_id'] = $id;
    }

    $this->vacanteRepository->save($valores);
    $this->messenger->addStatus($this->t('La vacante se guardó correctamente.'));
    $form_state->setRedirect('audiencias.vacante.list');
  }

}
