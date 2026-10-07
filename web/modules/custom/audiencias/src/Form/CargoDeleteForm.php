<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\CargoRepository;
use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de confirmación para eliminar un cargo.
 *
 * Integridad referencial: un cargo con funcionarios o vacantes asociados NO se
 * puede eliminar; se sugiere INACTIVARLO en su lugar.
 */
class CargoDeleteForm extends ConfirmFormBase {

  /**
   * Repositorio de cargos.
   *
   * @var \Drupal\audiencias\Repository\CargoRepository
   */
  protected CargoRepository $cargoRepository;

  /**
   * Repositorio de funcionarios (para la integridad referencial).
   *
   * @var \Drupal\audiencias\Repository\FuncionarioRepository
   */
  protected FuncionarioRepository $funcionarioRepository;

  /**
   * Repositorio de vacantes (para la integridad referencial).
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * Identificador del cargo a eliminar.
   *
   * @var int|null
   */
  protected ?int $id = NULL;

  /**
   * Registro del cargo a eliminar.
   *
   * @var object|null
   */
  protected ?object $registro = NULL;

  /**
   * Número de funcionarios asociados al cargo.
   *
   * @var int
   */
  protected int $funcionarios = 0;

  /**
   * Número de vacantes asociadas al cargo.
   *
   * @var int
   */
  protected int $vacantes = 0;

  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\CargoRepository $cargo_repository
   *   Repositorio de cargos.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    CargoRepository $cargo_repository,
    FuncionarioRepository $funcionario_repository,
    VacanteRepository $vacante_repository,
    MessengerInterface $messenger,
  ) {
    $this->cargoRepository = $cargo_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->vacanteRepository = $vacante_repository;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.cargo_repository'),
      $container->get('audiencias.funcionario_repository'),
      $container->get('audiencias.vacante_repository'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_cargo_delete_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $id
   *   Identificador del cargo a eliminar.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $this->id = $id !== NULL ? (int) $id : NULL;
    $this->registro = $this->id !== NULL ? $this->cargoRepository->load($this->id) : NULL;
    if ($this->registro === NULL) {
      throw new NotFoundHttpException('El cargo indicado no existe.');
    }

    $form = parent::buildForm($form, $form_state);

    // Integridad referencial: si el cargo tiene funcionarios o vacantes, se
    // bloquea el borrado y se sugiere inactivarlo en su lugar.
    $this->funcionarios = $this->funcionarioRepository->contarPorCargo($this->id);
    $this->vacantes = $this->vacanteRepository->contarPorCargo($this->id);
    if ($this->funcionarios > 0 || $this->vacantes > 0) {
      $form['bloqueo'] = [
        '#weight' => -10,
        '#markup' => '<div class="messages messages--error">' . $this->t('No se puede eliminar el cargo «@nombre» porque tiene @f funcionarios y @v vacantes asociados. En lugar de eliminarlo, considere <strong>inactivarlo</strong> (edítelo y desmarque «Activo»); así deja de usarse sin perder la información asociada.', [
          '@nombre' => $this->registro->nombre,
          '@f' => $this->funcionarios,
          '@v' => $this->vacantes,
        ]) . '</div>',
      ];
      // Sin botón de confirmación: la eliminación no está permitida.
      unset($form['actions']['submit']);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('¿Está seguro de que desea eliminar el cargo «@nombre»?', [
      '@nombre' => $this->registro->nombre,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('audiencias.cargo.list');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Eliminar');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Salvaguarda: re-comprueba la integridad referencial por si cambió entre
    // la confirmación y el envío (no se borra si tiene funcionarios o vacantes).
    $funcionarios = $this->funcionarioRepository->contarPorCargo($this->id);
    $vacantes = $this->vacanteRepository->contarPorCargo($this->id);
    if ($funcionarios > 0 || $vacantes > 0) {
      $this->messenger->addError($this->t('No se eliminó el cargo «@nombre»: tiene @f funcionarios y @v vacantes asociados. Considere inactivarlo en su lugar.', [
        '@nombre' => $this->registro->nombre,
        '@f' => $funcionarios,
        '@v' => $vacantes,
      ]));
      $form_state->setRedirect('audiencias.cargo.list');
      return;
    }

    $this->cargoRepository->delete($this->id);
    $this->messenger->addStatus($this->t('El cargo «@nombre» se eliminó correctamente.', [
      '@nombre' => $this->registro->nombre,
    ]));
    $form_state->setRedirect('audiencias.cargo.list');
  }

}
