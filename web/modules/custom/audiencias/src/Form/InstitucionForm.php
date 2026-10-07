<?php

declare(strict_types=1);

namespace Drupal\audiencias\Form;

use Drupal\audiencias\Repository\InstitucionRepository;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Formulario de alta y edición de instituciones.
 *
 * Valida que el nombre de la institución sea único antes de guardar.
 */
class InstitucionForm extends FormBase {

  /**
   * Repositorio de instituciones.
   *
   * @var \Drupal\audiencias\Repository\InstitucionRepository
   */
  protected InstitucionRepository $institucionRepository;


  /**
   * Construye el formulario.
   *
   * @param \Drupal\audiencias\Repository\InstitucionRepository $institucion_repository
   *   Repositorio de instituciones.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Servicio de mensajería.
   */
  public function __construct(InstitucionRepository $institucion_repository, MessengerInterface $messenger) {
    $this->institucionRepository = $institucion_repository;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('audiencias.institucion_repository'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'audiencias_institucion_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param int|null $id
   *   Identificador de la institución a editar, o NULL para alta.
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $registro = $id !== NULL ? $this->institucionRepository->load($id) : NULL;
    if ($id !== NULL && $registro === NULL) {
      throw new NotFoundHttpException('La institución indicada no existe.');
    }

    $form['institucion_id'] = [
      '#type' => 'value',
      '#value' => $registro->institucion_id ?? NULL,
    ];

    $form['nombre'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre'),
      '#required' => TRUE,
      '#maxlength' => 120,
      '#default_value' => $registro->nombre ?? '',
    ];

    $form['ubicacion'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Ubicación'),
      '#required' => FALSE,
      '#maxlength' => 200,
      '#default_value' => $registro->ubicacion ?? '',
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
   * Rechaza el guardado si ya existe otra institución con el mismo nombre.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $nombre = trim((string) $form_state->getValue('nombre'));
    $id = $form_state->getValue('institucion_id');
    $excluir = !empty($id) ? (int) $id : NULL;

    $existente = $this->institucionRepository->buscarPorNombre($nombre, $excluir);
    if ($existente !== NULL) {
      $form_state->setErrorByName('nombre', $this->t('Ya existe una institución con el nombre «@nombre». El nombre debe ser único.', [
        '@nombre' => $nombre,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $ubicacion = trim((string) $form_state->getValue('ubicacion'));
    $valores = [
      'nombre' => trim((string) $form_state->getValue('nombre')),
      // La ubicación es opcional: se guarda NULL cuando está vacía.
      'ubicacion' => $ubicacion !== '' ? $ubicacion : NULL,
    ];

    $id = $form_state->getValue('institucion_id');
    if (!empty($id)) {
      $valores['institucion_id'] = $id;
    }

    $this->institucionRepository->save($valores);
    $this->messenger->addStatus($this->t('La institución se guardó correctamente.'));
    $form_state->setRedirect('audiencias.institucion.list');
  }

}
