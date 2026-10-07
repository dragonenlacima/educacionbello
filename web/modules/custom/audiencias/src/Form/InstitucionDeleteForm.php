<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de confirmación para eliminar una institución.
 *
 * Integridad referencial: una institución con vacantes asociadas NO se puede
 * eliminar; primero hay que reasignar o eliminar esas vacantes.
 */
class InstitucionDeleteForm extends ConfirmFormBase {

  /**
   * Repositorio de instituciones.
   *
   * @var \Drupal\audiencias\Repository\InstitucionRepository
   */
  protected InstitucionRepository $institucionRepository;

  /**
   * Repositorio de vacantes (para comprobar la integridad referencial).
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * Identificador de la institución a eliminar.
   *
   * @var int|null
   */
  protected ?int $id = NULL;

  /**
   * Registro de la institución a eliminar.
   *
   * @var object|null
   */
  protected ?object $registro = NULL;

  /**
   * Número de vacantes asociadas a la institución (bloquea si es > 0).
   *
   * @var int
   */
  protected int $vacantes = 0;

  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    InstitucionRepository $institucion_repository,
    VacanteRepository $vacante_repository,
    MessengerInterface $messenger,
  ) {
    $this->institucionRepository = $institucion_repository;
    $this->vacanteRepository = $vacante_repository;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.institucion_repository'),
      $container->get('audiencias.vacante_repository'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_institucion_delete_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $id
   *   Identificador de la institución a eliminar.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $this->id = $id !== NULL ? (int) $id : NULL;
    $this->registro = $this->id !== NULL ? $this->institucionRepository->load($this->id) : NULL;
    if ($this->registro === NULL) {
      throw new NotFoundHttpException('La institución indicada no existe.');
    }

    $form = parent::buildForm($form, $form_state);

    // Integridad referencial: si la institución tiene vacantes, se bloquea el
    // borrado (se avisa con el conteo y se retira el botón de confirmar).
    $this->vacantes = $this->vacanteRepository->contarPorInstitucion($this->id);
    if ($this->vacantes > 0) {
      $form['bloqueo'] = [
        '#weight' => -10,
        '#markup' => '<div class="messages messages--error">' . $this->t('No se puede eliminar la institución «@nombre» porque tiene @n vacantes asociadas. Reasigne o elimine esas vacantes primero (por ejemplo, desde el listado de vacantes) y vuelva a intentarlo.', [
          '@nombre' => $this->registro->nombre,
          '@n' => $this->vacantes,
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
  public function getQuestion(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('¿Está seguro de que desea eliminar la institución «@nombre»?', [
      '@nombre' => $this->registro->nombre,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('audiencias.institucion.list');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Eliminar');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Salvaguarda: re-comprueba la integridad referencial por si cambió entre
    // la confirmación y el envío (no se borra si tiene vacantes).
    $vacantes = $this->vacanteRepository->contarPorInstitucion($this->id);
    if ($vacantes > 0) {
      $this->messenger->addError($this->t('No se eliminó la institución «@nombre»: tiene @n vacantes asociadas. Reasígnelas o elimínelas primero.', [
        '@nombre' => $this->registro->nombre,
        '@n' => $vacantes,
      ]));
      $form_state->setRedirect('audiencias.institucion.list');
      return;
    }

    $this->institucionRepository->delete($this->id);
    $this->messenger->addStatus($this->t('La institución «@nombre» se eliminó correctamente.', [
      '@nombre' => $this->registro->nombre,
    ]));
    $form_state->setRedirect('audiencias.institucion.list');
  }

}
