<?php

declare(strict_types=1);

namespace Drupal\create_public_official\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides a Create Public Official form.
 */
final class SearchOfficial extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'create_public_official_search_official';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, array $params = []): array {

    $form['container_1'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => 'grid grid-cols-1 lg:grid-cols-4 gap-4',
      ],
    ];

    $form['container_1']['nid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Documento'),
      '#placeholder' => $this->t('Ejemplo: 91515151'),
      '#default_value' => $params['nid'] ?? '',
    ];

    $form['container_1']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombres completos'),
      '#placeholder' => $this->t('Ejemplo: John Alexander'),
      '#default_value' => $params['name'] ?? '',
    ];

    $form['container_1']['typical'] = [
      '#type' => 'select',
      '#title' => $this->t('Asistencia típica'),
      '#options' => [
        '' => $this->t('Seleccione una opción'),
        '1' => $this->t('Sí'),
        '0' => $this->t('No'),
      ],
      '#default_value' => $params['typical'] ?? '',
    ];

    $form['container_1']['gender'] = [
      '#type' => 'select',
      '#title' => $this->t('Género'),
      '#options' => [
        '' => $this->t('Seleccione una opción'),
        'FEMENINO' => $this->t('Femenino'),
        'MASCULINO' => $this->t('Masculino'),
      ],
      '#default_value' => $params['gender'] ?? '',
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Buscar asistente'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // @todo Validate the form here.
    // Example:
    // @code
    //   if (mb_strlen($form_state->getValue('message')) < 10) {
    //     $form_state->setErrorByName(
    //       'message',
    //       $this->t('Message should be at least 10 characters.'),
    //     );
    //   }
    // @endcode
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('create_public_official.list_official', [
      'nid' => $form_state->getValue('nid'),
      'name' => $form_state->getValue('name'),
      'typical' => $form_state->getValue('typical'),
      'gender' => $form_state->getValue('gender'),
    ]);
  }

}
