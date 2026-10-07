<?php

declare(strict_types=1);

namespace Drupal\create_public_official\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\create_public_official\Service\UsersData;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns responses for Create Public Official routes.
 */
final class ListOfficialController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly UsersData $usersData,
    private readonly RequestStack $requestStack,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('create_public_official.users'),
      $container->get('request_stack'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(): array {
    $request = $this->requestStack->getCurrentRequest();
    $params = [
      'nid' => $request->query->get('nid', ''),
      'name' => $request->query->get('name', ''),
      'typical' => $request->query->get('typical', ''),
      'gender' => $request->query->get('gender', ''),
    ];
    $data = $this->usersData->getDataRegisterAll($params, 15);

    $form = $this->formBuilder()->getForm('Drupal\create_public_official\Form\SearchOfficial', $params);

    $rows = [];
    foreach ($data as $record) {
      $rows[] = [
        $record->official_id,
        $record->name,
        $record->gender,
        $record->position,
        $record->dependency,
        $record->typical ? $this->t('Sí') : $this->t('No'),
        $this->dateFormatter->format($record->created, 'custom', 'd/m/Y H:i', 'America/Bogota'),
      ];
    }

    $build = [];
    $build['#title'] = $this->t('Asistencia de funcionarios. En total %total', ['%total' => $this->usersData->getTotalRegisters()]);
    $build['form'] = $form;
    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Documento'),
        $this->t('Nombre'),
        $this->t('Género'),
        $this->t('Cargo'),
        $this->t('Dependencia'),
        $this->t('Típica'),
        $this->t('Fecha de registro'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No hay registros de asistencia.'),
    ];

    $build['pager'] = [
      '#type' => 'pager',
    ];

    return $build;
  }

}
