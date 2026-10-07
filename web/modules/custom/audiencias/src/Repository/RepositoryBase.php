<?php

declare(strict_types=1);

namespace Drupal\audiencias\Repository;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;

/**
 * Repositorio base con el CRUD genérico para las tablas del módulo Audiencias.
 *
 * Centraliza el acceso a datos: controladores y formularios nunca ejecutan SQL
 * directamente, siempre pasan por un repositorio. Toda consulta usa el query
 * builder de la API de base de datos con placeholders; no se concatena SQL.
 *
 * Las subclases concretas definen el nombre de la tabla y el nombre de su clave
 * primaria mediante getTabla() y getClavePrimaria().
 */
abstract class RepositoryBase {

  /**
   * La conexión a la base de datos.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Construye el repositorio.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   El servicio de conexión a la base de datos, inyectado.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Devuelve el nombre de la tabla gestionada por el repositorio.
   *
   * @return string
   *   Nombre de la tabla (con prefijo «audiencias_»).
   */
  abstract protected function getTabla(): string;

  /**
   * Devuelve el nombre de la columna que actúa como clave primaria.
   *
   * @return string
   *   Nombre de la clave primaria de la tabla.
   */
  abstract protected function getClavePrimaria(): string;

  /**
   * Carga todos los registros de la tabla.
   *
   * @return array
   *   Lista de registros como objetos stdClass; arreglo vacío si no hay datos.
   */
  public function loadAll(): array {
    $consulta = $this->database->select($this->getTabla(), 't')
      ->fields('t')
      ->orderBy($this->getClavePrimaria(), 'ASC');

    return $consulta->execute()->fetchAll();
  }

  /**
   * Carga una página de registros usando el paginador real de Drupal.
   *
   * Extiende la consulta con PagerSelectExtender para devolver SOLO la página
   * actual (según el parámetro «page» de la URL) y registrar el total, de modo
   * que un elemento «#type => pager» en el render array funcione de verdad.
   * No sustituye a loadAll(), que sigue cargando todos los registros.
   *
   * @param int $por_pagina
   *   Número de registros por página.
   *
   * @return array
   *   Registros de la página actual como objetos stdClass; arreglo vacío si no
   *   hay datos.
   */
  public function loadAllPaginado(int $por_pagina = 50): array {
    $consulta = $this->database->select($this->getTabla(), 't')
      ->extend(PagerSelectExtender::class);
    $consulta->fields('t')
      ->orderBy($this->getClavePrimaria(), 'ASC')
      ->limit($por_pagina);

    return $consulta->execute()->fetchAll();
  }

  /**
   * Carga un registro por su clave primaria.
   *
   * @param int|string $id
   *   Valor de la clave primaria.
   *
   * @return object|null
   *   El registro como objeto stdClass, o NULL si no existe.
   */
  public function load($id): ?object {
    $registro = $this->database->select($this->getTabla(), 't')
      ->fields('t')
      ->condition($this->getClavePrimaria(), $id)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $registro ?: NULL;
  }

  /**
   * Guarda un registro: inserta si no hay clave primaria, actualiza si la hay.
   *
   * @param array $valores
   *   Pares columna => valor. Si incluye la clave primaria con un valor no
   *   vacío, se actualiza el registro existente; en caso contrario, se inserta.
   *
   * @return int|string
   *   El identificador del registro guardado (id existente en actualización,
   *   id generado en inserción).
   */
  public function save(array $valores) {
    $clave = $this->getClavePrimaria();

    // Actualización: existe la clave primaria con un valor utilizable.
    if (!empty($valores[$clave])) {
      $id = $valores[$clave];
      // La clave primaria no se actualiza, solo se usa como condición.
      unset($valores[$clave]);

      $this->database->update($this->getTabla())
        ->fields($valores)
        ->condition($clave, $id)
        ->execute();

      return $id;
    }

    // Inserción: se ignora cualquier clave primaria vacía presente.
    unset($valores[$clave]);

    return $this->database->insert($this->getTabla())
      ->fields($valores)
      ->execute();
  }

  /**
   * Elimina un registro por su clave primaria.
   *
   * @param int|string $id
   *   Valor de la clave primaria del registro a eliminar.
   *
   * @return int
   *   Número de filas eliminadas.
   */
  public function delete($id): int {
    return $this->database->delete($this->getTabla())
      ->condition($this->getClavePrimaria(), $id)
      ->execute();
  }

}
