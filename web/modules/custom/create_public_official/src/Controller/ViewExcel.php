<?php

declare(strict_types=1);

namespace Drupal\create_public_official\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Returns responses for Create Public Official routes.
 */
final class ViewExcel extends ControllerBase {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructor.
   */
  public function __construct(RequestStack $request_stack) {
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('request_stack'),
    );
  }

  /**
   * Descarga el archivo Excel generado por el Batch API.
   */
  public function __invoke(): BinaryFileResponse {
    $session = $this->requestStack->getCurrentRequest()->getSession();
    $tempFile = $session->get('export_temp_file');

    // Validar que el archivo existe.
    if (empty($tempFile) || !file_exists($tempFile)) {
      throw new NotFoundHttpException('No se encontró el archivo para descargar. Por favor, genere la exportación nuevamente.');
    }

    // Limpiar la referencia de la sesión.
    $session->remove('export_temp_file');

    $filename = 'asistencia_' . date('Y-m-d_His') . '.xlsx';

    $response = new BinaryFileResponse($tempFile);
    $response->setContentDisposition(
      ResponseHeaderBag::DISPOSITION_ATTACHMENT,
      $filename
    );
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
    $response->headers->set('Pragma', 'public');

    // Eliminar el archivo temporal después de enviarlo.
    $response->deleteFileAfterSend(true);

    return $response;
  }

}
