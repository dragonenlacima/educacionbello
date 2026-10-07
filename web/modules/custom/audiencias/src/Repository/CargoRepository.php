<?php

declare(strict_types=1);

namespace Drupal\audiencias\Repository;

/**
 * Repositorio de acceso a datos para la tabla «audiencias_cargo».
 */
class CargoRepository extends RepositoryBase {

  /**
   * {@inheritdoc}
   */
  protected function getTabla(): string {
    return 'audiencias_cargo';
  }

  /**
   * {@inheritdoc}
   */
  protected function getClavePrimaria(): string {
    return 'cargo_id';
  }

  /**
   * Busca un cargo por su nombre exacto.
   *
   * Permite resolver el cargo_id real a partir del nombre, sin hardcodear
   * identificadores.
   *
   * @param string $nombre
   *   Nombre del cargo a buscar.
   *
   * @return object|null
   *   El registro del cargo, o NULL si no existe ninguno con ese nombre.
   */
  public function buscarPorNombre(string $nombre): ?object {
    $registro = $this->database->select($this->getTabla(), 'c')
      ->fields('c')
      ->condition('nombre', $nombre)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $registro ?: NULL;
  }

}
