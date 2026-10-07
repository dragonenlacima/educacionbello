<?php

declare(strict_types=1);

namespace Drupal\create_public_official\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\create_public_official\Service\SpreadsheetData;
use Drupal\create_public_official\Service\UsersData;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Create Public Official form.
 */
final class DownloadForm extends FormBase {

  /**
   * The users data service.
   *
   * @var \Drupal\create_public_official\Service\UsersData
   */
  protected UsersData $usersData;

  /**
   * The spreadsheet export service.
   *
   * @var \Drupal\create_public_official\Service\SpreadsheetData
   */
  protected SpreadsheetData $spreadsheetData;

  /**
   * Constructor.
   */
  public function __construct(UsersData $users_data, SpreadsheetData $spreadsheet_data) {
    $this->usersData = $users_data;
    $this->spreadsheetData = $spreadsheet_data;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('create_public_official.users'),
      $container->get('service_spreadsheet.export_data'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'create_public_official_download';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {

    // Si hay un archivo pendiente de descarga en la sesión, disparar la descarga.
    $session = $this->getRequest()->getSession();
    if ($session->has('export_temp_file')) {
      $downloadUrl = Url::fromRoute('create_public_official.view_excel')->toString();

      $form['download_message'] = [
        '#type' => 'inline_template',
        '#template' => '
        <div class="relative mb-6 rounded-2xl border border-blue-100 bg-white p-6 shadow-xl ring-1 ring-blue-50">
          <div class="flex items-start gap-5">
            <div class="relative flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 shadow-md">
              <svg class="h-7 w-7 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
              <span class="absolute -right-1 -top-1 flex h-4 w-4">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75"></span>
                <span class="relative inline-flex h-4 w-4 rounded-full bg-green-500"></span>
              </span>
            </div>
            <div class="flex flex-col gap-1.5 pt-1">
              <h3 class="text-lg font-bold text-gray-900">{{ title }}</h3>
              <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg shadow-lg" style="background-color: #0c4a6e;">
                  <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v15m0 0l-5-5m5 5l5-5M2 19h20"/></svg>
                </div>
                <p class="text-sm leading-relaxed text-gray-500">{{ description }}</p>
              </div>
            </div>
          </div>
          <div class="mt-4 overflow-hidden rounded-full bg-gray-100">
            <div class="h-1.5 animate-[pulse_1.5s_ease-in-out_infinite] rounded-full bg-gradient-to-r from-blue-500 via-indigo-500 to-blue-500"></div>
          </div>
        </div>',
        '#context' => [
          'title' => $this->t('Archivo listo para descargar'),
          'description' => $this->t('Su archivo Excel se ha generado correctamente. La descarga comenzará de forma automática.'),
        ],
        '#weight' => -100,
      ];

      // Meta refresh para disparar la descarga automáticamente.
      // Content-Disposition: attachment hace que el navegador descargue sin navegar.
      $form['#attached']['html_head'][] = [
        [
          '#tag' => 'meta',
          '#attributes' => [
            'http-equiv' => 'refresh',
            'content' => '1;url=' . $downloadUrl,
          ],
        ],
        'export_download_redirect',
      ];
    }

    $form['container_filters'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => 'grid grid-cols-1 lg:grid-cols-4 gap-4',
      ],
    ];

    $form['container_filters']['nid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Documento'),
      '#placeholder' => $this->t('Ejemplo: 91515151'),
    ];

    $form['container_filters']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombres completos'),
      '#placeholder' => $this->t('Ejemplo: John Alexander'),
    ];

    $form['container_filters']['typical'] = [
      '#type' => 'select',
      '#title' => $this->t('Asistencia típica'),
      '#options' => [
        '' => $this->t('Todos'),
        '1' => $this->t('Sí'),
        '0' => $this->t('No'),
      ],
    ];

    $form['container_filters']['gender'] = [
      '#type' => 'select',
      '#title' => $this->t('Género'),
      '#options' => [
        '' => $this->t('Todos'),
        'FEMENINO' => $this->t('Femenino'),
        'MASCULINO' => $this->t('Masculino'),
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Descargar'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $filters = $this->getFilters($form_state);
    $total = $this->usersData->countDataRegister($filters);

    if ($total === 0) {
      $form_state->setErrorByName('nid', $this->t('No se encontraron registros con los filtros seleccionados.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $filters = $this->getFilters($form_state);
    $totalRows = $this->usersData->countDataRegister($filters);
    $chunkSize = SpreadsheetData::CHUNK_SIZE;

    $header = [
      'Documento',
      'Nombre',
      'Género',
      'Cargo',
      'Dependencia',
      'Típica',
      'Fecha de registro',
    ];

    // Inicializar Excel con encabezados y obtener ruta temporal.
    $tempFile = $this->spreadsheetData->initBatchExport($header);

    // Calcular número de operaciones batch.
    $numChunks = (int) ceil($totalRows / $chunkSize);

    $operations = [];
    for ($i = 0; $i < $numChunks; $i++) {
      $offset = $i * $chunkSize;
      $operations[] = [
        [static::class, 'processChunk'],
        [$tempFile, $offset, $chunkSize, $filters],
      ];
    }

    // Operación final: aplicar estilos al archivo.
    $operations[] = [
      [static::class, 'finalizeExport'],
      [$tempFile],
    ];

    $batch = [
      'title' => $this->t('Exportando @total registros...', ['@total' => $totalRows]),
      'operations' => $operations,
      'finished' => [static::class, 'batchFinished'],
      'progressive' => TRUE,
    ];

    batch_set($batch);

    // Redirigir de vuelta al formulario. La descarga se disparará
    // automáticamente mediante meta refresh en buildForm.
    $form_state->setRedirect('create_public_official.download_official');
  }

  /**
   * Batch callback: procesa un chunk de datos y lo agrega al Excel.
   */
  public static function processChunk(string $tempFile, int $offset, int $chunkSize, array $filters, array &$context): void {
    /** @var \Drupal\create_public_official\Service\UsersData $usersData */
    $usersData = \Drupal::service('create_public_official.users');
    /** @var \Drupal\create_public_official\Service\SpreadsheetData $spreadsheetData */
    $spreadsheetData = \Drupal::service('service_spreadsheet.export_data');

    $records = $usersData->getDataForExport($offset, $chunkSize, $filters);

    if (!empty($records)) {
      $rows = [];
      foreach ($records as $record) {
        $typical = $record->typical ? 'Sí' : 'No';
        $date = \Drupal::service('date.formatter')->format((int) $record->created, 'custom', 'd/m/Y H:i', 'America/Bogota');
        $rows[] = [
          $record->official_id,
          $record->name,
          $record->gender,
          $record->position,
          $record->dependency,
          $typical,
          $date,
        ];
      }

      $spreadsheetData->appendChunkToExport($tempFile, $rows);
    }

    // Información de progreso.
    $context['message'] = t('Procesados @count registros...', ['@count' => $offset + count($records)]);
    $context['results']['temp_file'] = $tempFile;
  }

  /**
   * Batch callback: aplica formato final al archivo Excel.
   */
  public static function finalizeExport(string $tempFile, array &$context): void {
    /** @var \Drupal\create_public_official\Service\SpreadsheetData $spreadsheetData */
    $spreadsheetData = \Drupal::service('service_spreadsheet.export_data');
    $spreadsheetData->finalizeBatchExport($tempFile);
    $context['results']['temp_file'] = $tempFile;
    $context['message'] = t('Finalizando exportación...');
  }

  /**
   * Batch finished callback: guarda el archivo en sesión para la descarga.
   */
  public static function batchFinished(bool $success, array $results, array $operations): void {
    if ($success && !empty($results['temp_file'])) {
      $tempFile = $results['temp_file'];

      // Guardar la ruta del archivo temporal en la sesión para descargarlo.
      $session = \Drupal::request()->getSession();
      $session->set('export_temp_file', $tempFile);
    }
    else {
      \Drupal::messenger()->addError(t('Ocurrió un error durante la exportación.'));
    }
  }

  /**
   * Extrae los filtros del formulario.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   El estado del formulario.
   *
   * @return array
   *   Array con los filtros.
   */
  protected function getFilters(FormStateInterface $form_state): array {
    return [
      'nid' => $form_state->getValue('nid', ''),
      'name' => $form_state->getValue('name', ''),
      'typical' => $form_state->getValue('typical', ''),
      'gender' => $form_state->getValue('gender', ''),
    ];
  }

}
