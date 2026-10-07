<?php

declare(strict_types=1);

namespace Drupal\audiencias\Repository;

/**
 * Repositorio de acceso a datos para la tabla «audiencias_institucion».
 */
class InstitucionRepository extends RepositoryBase {

  /**
   * {@inheritdoc}
   */
  protected function getTabla(): string {
    return 'audiencias_institucion';
  }

  /**
   * {@inheritdoc}
   */
  protected function getClavePrimaria(): string {
    return 'institucion_id';
  }

  /**
   * Busca una institución por su nombre exacto.
   *
   * Permite validar la unicidad del nombre. Al editar, se puede excluir el
   * propio registro para no entrar en conflicto consigo mismo.
   *
   * @param string $nombre
   *   Nombre de institución a buscar.
   * @param int|null $excluir_id
   *   Identificador de institución a excluir (el propio registro al editar), o
   *   NULL para no excluir ninguno.
   *
   * @return object|null
   *   El registro de la institución que ya usa ese nombre, o NULL si está
   *   libre.
   */
  public function buscarPorNombre(string $nombre, ?int $excluir_id = NULL): ?object {
    $consulta = $this->database->select($this->getTabla(), 'i')
      ->fields('i')
      ->condition('nombre', $nombre);

    if ($excluir_id !== NULL) {
      $consulta->condition('institucion_id', $excluir_id, '<>');
    }

    $registro = $consulta->range(0, 1)->execute()->fetchObject();

    return $registro ?: NULL;
  }

}
