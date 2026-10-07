<?php

declare(strict_types=1);

namespace Drupal\audiencias\Repository;

/**
 * Repositorio de acceso a datos para la tabla «audiencias_funcionario».
 *
 * Añade consultas específicas de validación de unicidad de la cédula y de la
 * posición dentro de un cargo.
 */
class FuncionarioRepository extends RepositoryBase {

  /**
   * {@inheritdoc}
   */
  protected function getTabla(): string {
    return 'audiencias_funcionario';
  }

  /**
   * {@inheritdoc}
   */
  protected function getClavePrimaria(): string {
    return 'funcionario_id';
  }

  /**
   * Busca un funcionario por su número de documento (cédula).
   *
   * @param string $documento
   *   Número de documento a buscar.
   *
   * @return object|null
   *   El registro del funcionario, o NULL si no existe ninguno con esa cédula.
   */
  public function buscarPorDocumento(string $documento): ?object {
    $registro = $this->database->select($this->getTabla(), 'f')
      ->fields('f')
      ->condition('documento', $documento)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $registro ?: NULL;
  }

  /**
   * Busca el funcionario que ya ocupa una posición dentro de un cargo.
   *
   * Sirve para validar que la combinación (cargo_id, posicion) sea única. Al
   * editar, se puede excluir el propio registro para no entrar en conflicto
   * consigo mismo.
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   * @param int $posicion
   *   Posición a verificar dentro del cargo.
   * @param int|null $excluir_id
   *   Identificador de funcionario a excluir de la búsqueda (el propio registro
   *   al editar), o NULL para no excluir ninguno.
   *
   * @return object|null
   *   El registro del funcionario que ya ocupa esa (cargo, posición), o NULL
   *   si la combinación está libre.
   */
  public function buscarPorCargoYPosicion(int $cargo_id, int $posicion, ?int $excluir_id = NULL): ?object {
    $consulta = $this->database->select($this->getTabla(), 'f')
      ->fields('f')
      ->condition('cargo_id', $cargo_id)
      ->condition('posicion', $posicion);

    // Evita el conflicto del registro consigo mismo durante la edición.
    if ($excluir_id !== NULL) {
      $consulta->condition('funcionario_id', $excluir_id, '<>');
    }

    $registro = $consulta->range(0, 1)->execute()->fetchObject();

    return $registro ?: NULL;
  }

  /**
   * Devuelve los funcionarios de un cargo, ordenados por posición.
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   *
   * @return array
   *   Lista de funcionarios del cargo ordenados por posición ascendente;
   *   arreglo vacío si no hay ninguno.
   */
  public function buscarPorCargo(int $cargo_id): array {
    return $this->database->select($this->getTabla(), 'f')
      ->fields('f')
      ->condition('cargo_id', $cargo_id)
      ->orderBy('posicion', 'ASC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Cuenta cuántos funcionarios pertenecen a un cargo.
   *
   * Permite bloquear la eliminación de un cargo que aún tenga funcionarios
   * asociados (conviene inactivarlo en lugar de borrarlo).
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   *
   * @return int
   *   Número de funcionarios cuyo «cargo_id» coincide.
   */
  public function contarPorCargo(int $cargo_id): int {
    return (int) $this->database->select($this->getTabla(), 'f')
      ->condition('cargo_id', $cargo_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
