<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

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
 * Formulario de confirmación para eliminar una vacante.
 *
 * Regla de negocio: una vacante ASIGNADA no se puede eliminar. Si la vacante
 * tiene funcionario, el formulario BLOQUEA el borrado (oculta el botón de
 * confirmar) y ofrece reversar la asignación primero. Solo las vacantes sin
 * asignar pueden eliminarse.
 */
class VacanteDeleteForm extends ConfirmFormBase {

  /**
   * Repositorio de vacantes.
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * Repositorio de funcionarios (para nombrar al afectado en el aviso).
   *
   * @var \Drupal\audiencias\Repository\FuncionarioRepository
   */
  protected FuncionarioRepository $funcionarioRepository;

  /**
   * Identificador de la vacante a eliminar.
   *
   * @var int|null
   */
  protected ?int $id = NULL;

  /**
   * Registro de la vacante a eliminar.
   *
   * @var object|null
   */
  protected ?object $registro = NULL;

  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    VacanteRepository $vacante_repository,
    FuncionarioRepository $funcionario_repository,
    MessengerInterface $messenger,
  ) {
    $this->vacanteRepository = $vacante_repository;
    $this->funcionarioRepository = $funcionario_repository;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.vacante_repository'),
      $container->get('audiencias.funcionario_repository'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_vacante_delete_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $id
   *   Identificador de la vacante a eliminar.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $this->id = $id !== NULL ? (int) $id : NULL;
    $this->registro = $this->id !== NULL ? $this->vacanteRepository->load($this->id) : NULL;
    if ($this->registro === NULL) {
      throw new NotFoundHttpException('La vacante indicada no existe.');
    }

    $form = parent::buildForm($form, $form_state);

    // Regla: una vacante asignada NO se puede eliminar. Se bloquea el borrado
    // (se oculta el botón de confirmar) y se ofrece reversar la asignación
    // primero, volviendo a esta misma página tras hacerlo.
    if (!empty($this->registro->funcionario_id)) {
      $funcionario = $this->funcionarioRepository->load((int) $this->registro->funcionario_id);
      $nombre = $funcionario !== NULL ? $funcionario->nombre : (string) $this->t('(desconocido)');
      $form['aviso_bloqueo'] = [
        '#weight' => -10,
        '#markup' => '<div class="messages messages--error">' . $this->t('Esta vacante está asignada a <strong>@funcionario</strong>, por lo que <strong>no se puede eliminar</strong>. Reversa primero la asignación; luego podrás eliminarla.', [
          '@funcionario' => $nombre,
        ]) . '</div>',
      ];

      // Sin botón de confirmar: solo se permite reversar o volver.
      unset($form['actions']['submit']);
      $destino = Url::fromRoute('audiencias.vacante.delete', ['id' => $this->id])->toString();
      $form['actions']['reversar'] = [
        '#type' => 'link',
        '#title' => $this->t('Reversar asignación'),
        '#url' => Url::fromRoute('audiencias.vacante.desasignar', ['vacante_id' => $this->id], ['query' => ['destination' => $destino]]),
        '#attributes' => ['class' => ['button', 'button--primary']],
        '#weight' => 0,
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    // Si está asignada el borrado se bloquea: el título lo refleja.
    if (!empty($this->registro->funcionario_id)) {
      return $this->t('No se puede eliminar la vacante «@nombre»', [
        '@nombre' => $this->registro->nombre,
      ]);
    }
    return $this->t('¿Está seguro de que desea eliminar la vacante «@nombre»?', [
      '@nombre' => $this->registro->nombre,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('audiencias.vacante.list');
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
    // Defensa: reverifica que siga SIN asignar antes de borrar (su estado pudo
    // cambiar tras construir el formulario). Una vacante asignada nunca se borra.
    $actual = $this->vacanteRepository->load($this->id);
    if ($actual !== NULL && !empty($actual->funcionario_id)) {
      $this->messenger->addError($this->t('No se eliminó la vacante «@nombre» porque está asignada. Reversa la asignación primero.', [
        '@nombre' => $this->registro->nombre,
      ]));
      $form_state->setRedirect('audiencias.vacante.list');
      return;
    }

    // Capa de correctitud: el propio DELETE reconfirma que sigue libre, por si
    // se asignó entre la comprobación de arriba y esta línea.
    if ($this->vacanteRepository->deleteSiLibre($this->id) === 0) {
      $this->messenger->addError($this->t('No se eliminó la vacante «@nombre»: fue asignada mientras se confirmaba, o ya no existe. Actualice la pantalla y vuelva a intentarlo.', [
        '@nombre' => $this->registro->nombre,
      ]));
      $form_state->setRedirect('audiencias.vacante.list');
      return;
    }

    $this->messenger->addStatus($this->t('La vacante «@nombre» se eliminó correctamente.', [
      '@nombre' => $this->registro->nombre,
    ]));
    $form_state->setRedirect('audiencias.vacante.list');
  }

}
