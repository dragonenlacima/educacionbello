<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\FuncionarioRepository;
use Drupal\audiencias\Repository\VacanteRepository;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de confirmación para eliminar un funcionario.
 *
 * Cascada: si el funcionario está asignado a una vacante, primero se libera esa
 * vacante (funcionario_id y denominacion a NULL) y luego se borra al
 * funcionario, todo dentro de una transacción.
 */
class FuncionarioDeleteForm extends ConfirmFormBase {

  /**
   * Repositorio de funcionarios.
   *
   * @var \Drupal\audiencias\Repository\FuncionarioRepository
   */
  protected FuncionarioRepository $funcionarioRepository;

  /**
   * Repositorio de vacantes (para liberar la vacante en cascada).
   *
   * @var \Drupal\audiencias\Repository\VacanteRepository
   */
  protected VacanteRepository $vacanteRepository;

  /**
   * La conexión a la base de datos (solo para la transacción de la cascada; el
   * acceso a datos sigue pasando por los repositorios).
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Identificador del funcionario a eliminar.
   *
   * @var int|null
   */
  protected ?int $id = NULL;

  /**
   * Registro del funcionario a eliminar.
   *
   * @var object|null
   */
  protected ?object $registro = NULL;

  /**
   * Vacante a la que está asignado el funcionario (o NULL si no lo está).
   *
   * @var object|null
   */
  protected ?object $vacanteAsignada = NULL;

  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\FuncionarioRepository $funcionario_repository
   *   Repositorio de funcionarios.
   * @param \Drupal\audiencias\Repository\VacanteRepository $vacante_repository
   *   Repositorio de vacantes.
   * @param \Drupal\Core\Database\Connection $database
   *   Conexión a la base de datos para la transacción.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(
    FuncionarioRepository $funcionario_repository,
    VacanteRepository $vacante_repository,
    Connection $database,
    MessengerInterface $messenger,
  ) {
    $this->funcionarioRepository = $funcionario_repository;
    $this->vacanteRepository = $vacante_repository;
    $this->database = $database;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.funcionario_repository'),
      $container->get('audiencias.vacante_repository'),
      $container->get('database'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_funcionario_delete_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $id
   *   Identificador del funcionario a eliminar.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $this->id = $id !== NULL ? (int) $id : NULL;
    $this->registro = $this->id !== NULL ? $this->funcionarioRepository->load($this->id) : NULL;
    if ($this->registro === NULL) {
      throw new NotFoundHttpException('El funcionario indicado no existe.');
    }

    $form = parent::buildForm($form, $form_state);

    // Si el funcionario ocupa una vacante, se avisa de la cascada: al borrarlo,
    // esa vacante quedará «Sin asignar». No bloquea (se permite eliminar).
    $this->vacanteAsignada = $this->vacanteRepository->buscarPorFuncionario($this->id);
    if ($this->vacanteAsignada !== NULL) {
      $form['cascada'] = [
        '#weight' => -10,
        '#markup' => '<div class="messages messages--warning">' . $this->t('Este funcionario está asignado a la vacante «@vacante». Al eliminarlo, esa vacante quedará <strong>«Sin asignar»</strong> automáticamente.', [
          '@vacante' => $this->vacanteAsignada->nombre,
        ]) . '</div>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('¿Está seguro de que desea eliminar al funcionario @nombre (cédula @documento)?', [
      '@nombre' => $this->registro->nombre,
      '@documento' => $this->registro->documento,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('audiencias.funcionario.list');
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
    // Cascada dentro de una transacción: si el funcionario ocupa una vacante,
    // se libera primero y luego se borra al funcionario. O todo, o nada.
    // Se lee la vacante SOLO para poder nombrarla en el mensaje de éxito; la
    // liberación de abajo no depende de esta lectura.
    $vacante = $this->vacanteRepository->buscarPorFuncionario($this->id);
    $liberada = NULL;

    $transaccion = $this->database->startTransaction();
    try {
      // Libera cualquier vacante que ocupe el funcionario, sea cual sea: si lo
      // movieron a otra entre la lectura de arriba y esta línea, también queda
      // liberada, de modo que el borrado no puede dejar una vacante huérfana.
      // Un 0 significa que no ocupaba ninguna: caso normal, sin aviso.
      if ($this->vacanteRepository->desasignarPorFuncionario($this->id) === 1) {
        $liberada = $vacante !== NULL ? $vacante->nombre : NULL;
      }
      $this->funcionarioRepository->delete($this->id);
    }
    catch (\Throwable $e) {
      // Revierte explícitamente y reporta; no deja datos a medias.
      $transaccion->rollBack();
      $this->messenger->addError($this->t('No se pudo eliminar al funcionario @nombre: @mensaje. No se hicieron cambios.', [
        '@nombre' => $this->registro->nombre,
        '@mensaje' => $e->getMessage(),
      ]));
      $form_state->setRedirect('audiencias.funcionario.list');
      return;
    }

    // Al salir del ámbito, la transacción se confirma automáticamente.
    unset($transaccion);

    if ($liberada !== NULL) {
      $this->messenger->addStatus($this->t('El funcionario @nombre se eliminó correctamente. La vacante «@vacante» quedó «Sin asignar».', [
        '@nombre' => $this->registro->nombre,
        '@vacante' => $liberada,
      ]));
    }
    else {
      $this->messenger->addStatus($this->t('El funcionario @nombre se eliminó correctamente.', [
        '@nombre' => $this->registro->nombre,
      ]));
    }
    $form_state->setRedirect('audiencias.funcionario.list');
  }

}
