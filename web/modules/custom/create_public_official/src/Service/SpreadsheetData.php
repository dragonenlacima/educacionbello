<?php

declare(strict_types=1);

namespace Drupal\create_public_official\Service;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Servicio para exportar datos a archivos Excel.
 */
final class SpreadsheetData {

  /**
   * Tamaño del chunk para procesamiento por lotes.
   */
  const CHUNK_SIZE = 500;

  /**
   * Ancho máximo de columna en caracteres.
   */
  const MAX_COLUMN_WIDTH = 100;

  /**
   * Número de filas a muestrear para calcular anchos de columna.
   */
  const SAMPLE_ROWS = 100;

  /**
   * Ajusta el ancho de las columnas con límite máximo y wrap text.
   *
   * Usa muestreo para conjuntos grandes de datos: analiza solo
   * el encabezado + las primeras SAMPLE_ROWS filas para calcular anchos.
   *
   * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
   *   La hoja de cálculo.
   * @param int $lastRow
   *   Última fila con datos.
   */
  protected function adjustColumnWidths($sheet, int $lastRow): void {
    $highestColumn = $sheet->getHighestColumn();
    $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

    // Muestrear solo las primeras N filas para conjuntos grandes.
    $sampleLimit = min($lastRow, self::SAMPLE_ROWS + 1);

    for ($col = 1; $col <= $highestColumnIndex; $col++) {
      $columnLetter = Coordinate::stringFromColumnIndex($col);

      // Calcular el ancho óptimo basado en muestra del contenido.
      $maxLength = 0;
      for ($row = 1; $row <= $sampleLimit; $row++) {
        $cellValue = $sheet->getCell($columnLetter . $row)->getValue();
        if ($cellValue !== null) {
          $cellLength = mb_strlen((string) $cellValue);
          if ($cellLength > $maxLength) {
            $maxLength = $cellLength;
          }
        }
      }

      // Si excede el máximo, aplicar ancho fijo y wrap text.
      if ($maxLength > self::MAX_COLUMN_WIDTH) {
        $sheet->getColumnDimension($columnLetter)->setWidth(self::MAX_COLUMN_WIDTH);
        // Aplicar wrap text a toda la columna (excepto encabezado).
        $sheet->getStyle($columnLetter . '2:' . $columnLetter . $lastRow)
          ->getAlignment()
          ->setWrapText(true);
      }
      else {
        // Usar autosize para columnas que no exceden el límite.
        $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
      }
    }
  }

  /**
   * Genera el contenido del archivo Excel.
   *
   * @param array $header
   *   Array con los encabezados de las columnas.
   * @param array $rows
   *   Array bidimensional con los datos de las filas.
   *
   * @return string
   *   El contenido del archivo Excel en formato binario.
   */
  public function exportXLSX(array $header = [], array $rows = []): string {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Agregar encabezados
    if (!empty($header)) {
      $sheet->fromArray($header, null, 'A1');
      
      // Aplicar negrita a los encabezados
      $highestColumnHeader = $sheet->getHighestColumn();
      $sheet->getStyle('A1:' . $highestColumnHeader . '1')->getFont()->setBold(true);
    }
    
    // Agregar datos
    if (!empty($rows)) {
      $sheet->fromArray($rows, null, 'A2');
    }
    
    // Ajustar ancho de columnas con límite máximo.
    $lastRow = $sheet->getHighestRow();
    $this->adjustColumnWidths($sheet, $lastRow);
    
    // Generar el archivo en memoria
    $writer = new Xlsx($spreadsheet);
    
    // Usar un buffer de salida para capturar el contenido
    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();
    
    return $content;
  }

  /**
   * Exporta datos grandes a Excel usando chunks y archivo temporal.
   *
   * @param array $header
   *   Array con los encabezados de las columnas.
   * @param callable $dataCallback
   *   Callback que recibe ($offset, $limit) y retorna array de filas.
   * @param int $totalRows
   *   Número total de filas a procesar.
   * @param int $chunkSize
   *   Tamaño del chunk (default: 500).
   *
   * @return string
   *   Ruta al archivo temporal generado.
   */
  public function exportXLSXChunked(array $header, callable $dataCallback, int $totalRows, int $chunkSize = self::CHUNK_SIZE): string {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Agregar encabezados.
    if (!empty($header)) {
      $sheet->fromArray($header, null, 'A1');
      $highestColumnHeader = $sheet->getHighestColumn();
      $sheet->getStyle('A1:' . $highestColumnHeader . '1')->getFont()->setBold(true);
    }

    // Procesar datos en chunks.
    $currentRow = 2;
    $offset = 0;

    while ($offset < $totalRows) {
      $rows = $dataCallback($offset, $chunkSize);

      if (empty($rows)) {
        break;
      }

      $sheet->fromArray($rows, null, 'A' . $currentRow);

      $currentRow += count($rows);
      $offset += $chunkSize;

      unset($rows);
    }

    if ($currentRow > 2) {
      $this->adjustColumnWidths($sheet, $currentRow - 1);
    }

    $tempFile = \Drupal::service('file_system')->getTempDirectory() . '/export_' . uniqid() . '.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return $tempFile;
  }

  /**
   * Inicializa un archivo Excel temporal con encabezados para uso con Batch API.
   *
   * @param array $header
   *   Array con los encabezados de las columnas.
   *
   * @return string
   *   Ruta al archivo temporal creado.
   */
  public function initBatchExport(array $header): string {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    if (!empty($header)) {
      $sheet->fromArray($header, null, 'A1');
      $highestColumnHeader = $sheet->getHighestColumn();
      $sheet->getStyle('A1:' . $highestColumnHeader . '1')->getFont()->setBold(true);
    }

    $tempFile = \Drupal::service('file_system')->getTempDirectory() . '/export_batch_' . uniqid() . '.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return $tempFile;
  }

  /**
   * Agrega un chunk de datos a un archivo Excel existente (Batch API).
   *
   * @param string $tempFile
   *   Ruta al archivo temporal existente.
   * @param array $rows
   *   Array bidimensional con los datos a agregar.
   *
   * @return int
   *   Número de la siguiente fila disponible.
   */
  public function appendChunkToExport(string $tempFile, array $rows): int {
    $spreadsheet = IOFactory::load($tempFile);
    $sheet = $spreadsheet->getActiveSheet();

    $currentRow = $sheet->getHighestRow() + 1;
    $sheet->fromArray($rows, null, 'A' . $currentRow);

    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    $nextRow = $currentRow + count($rows);

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return $nextRow;
  }

  /**
   * Finaliza el archivo Excel: aplica estilos de formato de columna.
   *
   * @param string $tempFile
   *   Ruta al archivo temporal.
   */
  public function finalizeBatchExport(string $tempFile): void {
    $spreadsheet = IOFactory::load($tempFile);
    $sheet = $spreadsheet->getActiveSheet();
    $lastRow = $sheet->getHighestRow();

    if ($lastRow > 1) {
      $this->adjustColumnWidths($sheet, $lastRow);
    }

    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);
  }

  /**
   * Lee el contenido de un archivo temporal y lo elimina.
   *
   * @param string $tempFile
   *   Ruta al archivo temporal.
   *
   * @return string
   *   Contenido del archivo.
   */
  public function getAndCleanTempFile(string $tempFile): string {
    $content = file_get_contents($tempFile);
    unlink($tempFile);
    return $content;
  }

}
