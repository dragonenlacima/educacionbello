<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de alta y edición de cargos administrativos.
 */
class CargoForm extends FormBase {

  /**
   * Repositorio de cargos.
   *
   * @var \Drupal\audiencias\Repository\CargoRepository
   */
  protected CargoRepository $cargoRepository;


  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(CargoRepository $cargo_repository, MessengerInterface $messenger) {
    $this->cargoRepository = $cargo_repository;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.cargo_repository'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_cargo_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $id
   *   Identificador del cargo a editar, o NULL para alta.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    // En edición, carga el registro existente.
    $registro = $id !== NULL ? $this->cargoRepository->load($id) : NULL;
    if ($id !== NULL && $registro === NULL) {
      throw new NotFoundHttpException('El cargo indicado no existe.');
    }

    $form['cargo_id'] = [
      '#type' => 'value',
      '#value' => $registro->cargo_id ?? NULL,
    ];

    $form['nombre'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre'),
      '#required' => TRUE,
      '#maxlength' => 120,
      '#default_value' => $registro->nombre ?? '',
    ];

    $form['estado'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activo'),
      // Por defecto activo en alta; conserva el estado guardado en edición.
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
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $valores = [
      'nombre' => trim((string) $form_state->getValue('nombre')),
      'estado' => (int) $form_state->getValue('estado'),
    ];

    $id = $form_state->getValue('cargo_id');
    if (!empty($id)) {
      $valores['cargo_id'] = $id;
    }

    $this->cargoRepository->save($valores);
    $this->messenger->addStatus($this->t('El cargo se guardó correctamente.'));
    $form_state->setRedirect('audiencias.cargo.list');
  }

}
