<?php

declare(strict_types=1);

namespace Drupal\create_public_official\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\create_public_official\Service\UsersData;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns responses for Create Public Official routes.
 */
final class DownloadOfficialController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly UsersData $usersData,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('create_public_official.users'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(): array {

    $form = $this->formBuilder()->getForm('Drupal\create_public_official\Form\DownloadForm');

     $build['form'] = $form;

    return $build;
  }

}
