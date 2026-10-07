<?php

declare(strict_types=1);

namespace Drupal\create_public_official\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Database\Connection;
use Drupal\create_public_official\Service\UsersData;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @todo Add a description for the form.
 */
final class ValidateUserForm extends FormBase {

  /**
   * The users data service.
   *
   * @var \Drupal\create_public_official\Service\UsersData
   */
  protected UsersData $usersData;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a ValidateUserForm object.
   */
  public function __construct(
    UsersData $usersData,
    Connection $database,
    AccountProxyInterface $currentUser,
  ) {
    $this->usersData = $usersData;
    $this->database = $database;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('create_public_official.users'),
      $container->get('database'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'create_public_official_validate_user';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $step = $form_state->get('step') ?? 1;

    switch ($step) {
      case 1:
        $form = $this->step1($form, $form_state);
        break;

      case 2:
        $form = $this->step2($form, $form_state);
        break;
    }

    return $form;
  }

  public function step1(array $form, FormStateInterface $form_state): array {
    $form = [];

    $form['nid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Número de Identificación'),
      '#required' => TRUE,
      '#placeholder' => $this->t('Ejemplo: 7195112'),
      '#default_value' => $form_state->get('nid') ?? '',
      '#pattern' => '[1-9][0-9]{5,10}',
      '#description' => $this->t('Solo se permiten números enteros positivos de 6 a 11 dígitos sin comenzar en cero.'),
      '#attributes' => [
        'inputmode' => 'numeric',
        'autocomplete' => 'off',
      ],
    ];

    $form['#validate'] = ['::validateStep1'];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Siguiente'),
      '#submit' => ['::submitStep1'],
    ];

    return $form;
  }

  public function step2(array $form, FormStateInterface $form_state): array {
    $dependency = $this->usersData->getDependency();
    $form = [];
    $form['validation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Registrar asistencia del funcionario'),
      '#default_value' => $form_state->get('validation') ?? FALSE,
      '#description' => $this->t('Me hago responsable de que la información del funcionario es auténtica y confirmo su asistencia al evento.'),
      '#required' => TRUE,
    ];
    $form['fields'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => 'grid grid-cols-1 gap-4',
      ],
    ];
    $form['fields']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre Completo'),
      '#required' => TRUE,
      '#default_value' => $form_state->get('name') ?? '',
      '#placeholder' => $this->t('Ejemplo: John Alexander Rodríguez Sereno'),
    ];
    $form['fields']['nid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Número de Identificación'),
      '#required' => TRUE,
      '#default_value' => $form_state->get('nid') ?? '',
      '#disabled' => TRUE,
      '#placeholder' => $this->t('Ejemplo: 7195112'),
      '#attributes' => [
        'inputmode' => 'numeric',
        'autocomplete' => 'off',
      ],
    ];
    $form['fields']['repeat_nid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Repetir Número de Identificación'),
      '#required' => TRUE,
      '#default_value' => $form_state->get('repeat_nid') ?? '',
      '#pattern' => '[1-9][0-9]{5,10}',
      '#placeholder' => $this->t('Ejemplo: 7195112'),
      '#description' => $this->t('Ingrese nuevamente el número de identificación para confirmarlo. Solo se permiten números enteros positivos de 6 a 11 dígitos sin comenzar en cero.'),
      '#attributes' => [
        'inputmode' => 'numeric',
        'autocomplete' => 'off',
      ],
    ];
    $form['fields']['position'] = [
      '#type' => 'select',
      '#title' => $this->t('Cargo'),
      '#required' => TRUE,
      '#options' => $this->usersData->getListPosition(),
      '#empty_option' => $this->t('- Seleccione un cargo -'),
      '#default_value' => $form_state->get('position') ?? '',
    ];
    $form['fields']['gender'] = [
      '#type' => 'select',
      '#title' => $this->t('Género'),
      '#required' => TRUE,
      '#options' => [
        'FEMENINO' => $this->t('Femenino'),
        'MASCULINO' => $this->t('Masculino'),
      ],
      '#empty_option' => $this->t('- Seleccione un género -'),
      '#default_value' => $form_state->get('gender') ?? '',
    ];
    $form['fields']['dependency'] = [
      '#type' => 'select',
      '#title' => $this->t('Dependencia'),
      '#options' => $dependency,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Seleccione una dependencia -'),
      '#default_value' => $form_state->get('dependency') ?? '',
    ];
    $form['#validate'] = ['::validateStep2'];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['back'] = [
      '#type' => 'submit',
      '#value' => $this->t('Atrás'),
      '#submit' => ['::submitBack'],
      '#limit_validation_errors' => [],
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirmar'),
    ];
    return $form;
  }

  public function validateStep1(array &$form, FormStateInterface $form_state): void {
    $nid = $form_state->getValue('nid');
    $validate_register = $this->usersData->validateRegister($nid);
    if (!empty($validate_register)) {
      $form_state->setErrorByName('nid', $this->t(
        'El funcionario con número de identificación @documento ya tiene una asistencia registrada para este evento.',
        ['@documento' => $nid]
      ));
    }
  }

  public function validateStep2(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('validation') === FALSE) {
      $form_state->setErrorByName('validation', $this->t('Debe aceptar que la información del funcionario es auténtica y confirmar su asistencia al evento para continuar.'));
    }
    if ($form_state->getValue('nid') !== $form_state->getValue('repeat_nid')) {
      $form_state->setErrorByName('repeat_nid', $this->t('El número de identificación no coincide. Por favor, verifique e intente nuevamente.'));
    }
  }

  /**
   * Gets current DateTime with Bogota timezone.
   *
   * @return \DateTime
   *   DateTime object configured for America/Bogota timezone.
   */
  private function getCurrentTime(): \DateTime {
    return new \DateTime('now', new \DateTimeZone('America/Bogota'));
  }

  public function submitBack(array &$form, FormStateInterface $form_state): void {
    // Usar getUserInput() porque #limit_validation_errors => [] impide
    // que getValue() devuelva los valores de los campos.
    $input = $form_state->getUserInput();
    $form_state->set('validation', !empty($input['validation']));
    $form_state->set('name', $input['name'] ?? '');
    // El nid está deshabilitado, así que lo obtenemos de form_state.
    $form_state->set('nid', $form_state->get('nid'));
    $form_state->set('position', $input['position'] ?? '');
    $form_state->set('gender', $input['gender'] ?? '');
    $form_state->set('dependency', $input['dependency'] ?? '');
    $form_state->set('repeat_nid', $input['repeat_nid'] ?? '');
    $form_state->set('step', 1);
    $form_state->setRebuild(TRUE);
  }

  public function submitStep1(array &$form, FormStateInterface $form_state): void {
    $data = $this->usersData->getData($form_state->getValue('nid'));
    $form_state->set('nid', $form_state->getValue('nid'));

    // El usuario está en la base de datos y puede asistir al evento
    if (!empty($data)) {
      $registered = $this->usersData->validateRegister($data[0]->nid);
      if (!empty($registered)) {
        $this->messenger()->addWarning(
          $this->t(
            'El funcionario con identificación @documento ya tiene asistencia registrada para este evento.',
            ['@documento' => $data[0]->nid]
          )
        );
      }
      else {
        $this->messenger()->addStatus(
          $this->t(
            'Funcionario registrado exitosamente para el evento: cc @documento, @name, @position en @dependency.',
            [
              '@documento' => $data[0]->nid,
              '@name' => $data[0]->name,
              '@position' => $data[0]->position,
              '@dependency' => $data[0]->dependency,
            ]
          )
        );

        $timestamp = $this->getCurrentTime()->getTimestamp();

        $this->database->insert('create_public_official_register_attendance')
          ->fields([
            'official_id' => $data[0]->nid,
            'attendance_date' => $timestamp,
            'typical' => $data[0]->typical,
            'created' => $timestamp,
            'updated' => $timestamp,
            'id_user_register' => $this->currentUser->id(),
            'user_register' => $this->currentUser->getAccountName(),
          ])
          ->execute();

        $registered_id = $this->usersData->validateRegister($data[0]->nid);

        $this->database->insert('create_public_official_attendance_data')
          ->fields([
            'id_register' => $registered_id[0]->id,
            'name' => $data[0]->name,
            'nid' => $data[0]->nid,
            'gender' => $data[0]->gender,
            'position' => $data[0]->position,
            'dependency' => $data[0]->dependency,
            'created' => $timestamp,
            'updated' => $timestamp,
          ])
          ->execute();
      }
    } else {
      $this->messenger()->addWarning(
        $this->t(
          'No se encontró ningún funcionario con el número de identificación proporcionado. Ingrese los campos necesarios para registrar sus asistencia'
        )
      );
      // Preservar datos del paso 2 si es que existen
      $form_state->set('validation', $form_state->get('validation') ?? FALSE);
      $form_state->set('name', $form_state->get('name') ?? '');
      $form_state->set('position', $form_state->get('position') ?? '');
      $form_state->set('gender', $form_state->get('gender') ?? '');
      $form_state->set('dependency', $form_state->get('dependency') ?? '');
      $form_state->set('repeat_nid', $form_state->get('repeat_nid') ?? '');
      $form_state->set('step', 2);
      $form_state->setRebuild(TRUE);
    }
  }
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = $form_state->getValue('nid');
    $repeat_nid = $form_state->getValue('repeat_nid');
    $validation = $form_state->getValue('validation');
    $name = $form_state->getValue('name');
    $position = $form_state->getValue('position');
    $gender = $form_state->getValue('gender');
    $dependency = $form_state->getValue('dependency');
    $typical = 0;

    $timestamp = $this->getCurrentTime()->getTimestamp();

    // Insertar datos en la información de la asistencia
    $this->database->insert('create_public_official_register_attendance')
      ->fields([
        'official_id' => $nid,
        'attendance_date' => $timestamp,
        'typical' => $typical,
        'created' => $timestamp,
        'updated' => $timestamp,
        'id_user_register' => $this->currentUser->id(),
        'user_register' => $this->currentUser->getAccountName(),
      ])
      ->execute();

    $registered_id = $this->usersData->validateRegister($nid);

    $this->database->insert('create_public_official_attendance_data')
      ->fields([
        'id_register' => $registered_id[0]->id,
        'name' => $name,
        'nid' => $nid,
        'gender' => $gender,
        'position' => $position,
        'dependency' => $dependency,
        'created' => $timestamp,
        'updated' => $timestamp,
      ])
      ->execute();

    // @todo Place your code here.
    $this->messenger()->addStatus($this->t('Registro de funcionario ingresado exitosamente. NID: @nid', ['@nid' => $nid]));
    $form_state->setRedirectUrl(Url::fromRoute('create_public_official.validate_user'));
  }

}
