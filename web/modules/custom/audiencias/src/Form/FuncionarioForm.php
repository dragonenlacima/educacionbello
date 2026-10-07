<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de alta y edición de funcionarios.
 *
 * Valida la cédula (numérica y única global) y que la posición sea única
 * dentro de cada cargo, antes de que la base de datos lance una excepción.
 */
class FuncionarioForm extends FormBase {

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
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    FuncionarioRepository $funcionario_repository,
    CargoRepository $cargo_repository,
    MessengerInterface $messenger,
  ) {
    $this->funcionarioRepository = $funcionario_repository;
    $this->cargoRepository = $cargo_repository;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.funcionario_repository'),
      $container->get('audiencias.cargo_repository'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_funcionario_form';
  }

  /**
   * Construye el listado de opciones de cargo (cargo_id => nombre).
   *
   * @return array
   *   Opciones para el select de cargos.
   */
  protected function opcionesCargo(): array {
    $opciones = [];
    foreach ($this->cargoRepository->loadAll() as $cargo) {
      $opciones[$cargo->cargo_id] = $cargo->nombre;
    }
    return $opciones;
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $id
   *   Identificador del funcionario a editar, o NULL para alta.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $registro = $id !== NULL ? $this->funcionarioRepository->load($id) : NULL;
    if ($id !== NULL && $registro === NULL) {
      throw new NotFoundHttpException('El funcionario indicado no existe.');
    }

    $form['funcionario_id'] = [
      '#type' => 'value',
      '#value' => $registro->funcionario_id ?? NULL,
    ];

    $form['nombre'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre'),
      '#required' => TRUE,
      '#maxlength' => 120,
      '#default_value' => $registro->nombre ?? '',
    ];

    $form['documento'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Documento (cédula)'),
      '#required' => TRUE,
      '#maxlength' => 20,
      '#default_value' => $registro->documento ?? '',
      '#description' => $this->t('Solo dígitos.'),
    ];

    $form['posicion'] = [
      '#type' => 'number',
      '#title' => $this->t('Posición'),
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
      '#default_value' => $registro->posicion ?? NULL,
    ];

    $form['cargo_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Cargo'),
      '#required' => TRUE,
      '#options' => $this->opcionesCargo(),
      '#empty_option' => $this->t('- Seleccione -'),
      '#default_value' => $registro->cargo_id ?? NULL,
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
   * Valida cédula numérica, cédula única global y posición única por cargo.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $id = $form_state->getValue('funcionario_id');
    $excluir = !empty($id) ? (int) $id : NULL;

    $documento = trim((string) $form_state->getValue('documento'));
    // La cédula debe contener únicamente dígitos.
    if ($documento !== '' && !ctype_digit($documento)) {
      $form_state->setErrorByName('documento', $this->t('El documento debe ser numérico (solo dígitos).'));
    }
    else {
      // Cédula única a nivel global.
      $existente = $this->funcionarioRepository->buscarPorDocumento($documento);
      if ($existente !== NULL && (int) $existente->funcionario_id !== $excluir) {
        $form_state->setErrorByName('documento', $this->t('Ya existe un funcionario con la cédula @documento.', [
          '@documento' => $documento,
        ]));
      }
    }

    // Posición única dentro de cada cargo.
    $cargo_id = (int) $form_state->getValue('cargo_id');
    $posicion = (int) $form_state->getValue('posicion');
    if ($cargo_id > 0 && $posicion > 0) {
      $ocupante = $this->funcionarioRepository->buscarPorCargoYPosicion($cargo_id, $posicion, $excluir);
      if ($ocupante !== NULL) {
        $opciones = $this->opcionesCargo();
        $nombre_cargo = $opciones[$cargo_id] ?? (string) $cargo_id;
        $form_state->setErrorByName('posicion', $this->t('La posición @posicion ya está ocupada en el cargo «@cargo» por el funcionario @nombre (cédula @documento). La posición debe ser única dentro de cada cargo.', [
          '@posicion' => $posicion,
          '@cargo' => $nombre_cargo,
          '@nombre' => $ocupante->nombre,
          '@documento' => $ocupante->documento,
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
      'documento' => trim((string) $form_state->getValue('documento')),
      'posicion' => (int) $form_state->getValue('posicion'),
      'cargo_id' => (int) $form_state->getValue('cargo_id'),
    ];

    $id = $form_state->getValue('funcionario_id');
    if (!empty($id)) {
      $valores['funcionario_id'] = $id;
    }

    $this->funcionarioRepository->save($valores);
    $this->messenger->addStatus($this->t('El funcionario se guardó correctamente.'));
    $form_state->setRedirect('audiencias.funcionario.list');
  }

}
