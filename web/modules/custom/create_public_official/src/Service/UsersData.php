<?php

declare(strict_types=1);

namespace Drupal\create_public_official\Service;

use Drupal\Core\Database\Connection;

/**
 * @todo Add class description.
 */
final class UsersData {

  /**
   * Constructs an UsersData object.
   */
  public function __construct(
    private readonly Connection $connection,
  ) {}

  /**
   * @todo Add method description.
   */
  public function getData(string $nid): array {
    $query = $this->connection->select('create_public_official_list', 'users')
      ->fields('users', ['id', 'nid', 'name', 'gender', 'position', 'dependency', 'typical'])
      ->condition('nid', $nid)
      ->execute();

    return $query->fetchAll();
  }

  /**
   * @description: Este método valida si el usuario tiene asistencia registrada y me trae
   * los datos de asistencia correspondientes. Si el usuario no tiene asistencia registrada, 
   * devuelve un array vacío.
   * @param int $id_register
   * @return array
   */
  public function getDataRegister(string $id_register): array {
    $query = $this->connection->select('create_public_official_register_attendance', 'register')
      ->fields('register', ['official_id', 'typical']);
    
    $query->innerJoin('create_public_official_attendance_data', 'attendance', 'register.id = attendance.id_register');
    $query->fields('attendance', ['name', 'gender', 'position', 'dependency', 'created']);
    $query->condition('attendance.id_register', $id_register);
    
    return $query->execute()->fetchAll();
  }

  /**
   * Get all registered attendance data with pagination.
   *
   * @param int $limit
   *   Number of records per page.
   *
   * @return array
   *   Array of attendance records.
   */
  public function getDataRegisterAll(array $data, int $limit = 50): array {
    $query = $this->connection->select('create_public_official_register_attendance', 'register');
    $query->fields('register', ['id', 'official_id', 'typical']);
    
    $query->innerJoin('create_public_official_attendance_data', 'attendance', 'register.id = attendance.id_register');
    $query->fields('attendance', ['name', 'gender', 'position', 'dependency', 'created']);
    
    $query->orderBy('register.created', 'DESC');
    if (!empty($data['nid'])) {
      $query->condition('register.official_id', $data['nid'], '=');
    }
    if (!empty($data['name'])) {
      $query->condition('attendance.name', '%' . $this->connection->escapeLike($data['name']) . '%', 'LIKE');
    }

    if (isset($data['typical']) && $data['typical'] !== '') {
      $query->condition('register.typical', $data['typical'], '=');
    }
    if (!empty($data['gender'])) {
      $query->condition('attendance.gender', $data['gender'], '=');
    }
    $query = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender');
    $query->limit($limit);
    
    return $query->execute()->fetchAll();
  }

  /**
   * @todo Add method description.
   */
  public function validateRegister(string $nid): array {
    $query = $this->connection->select('create_public_official_register_attendance', 'register')
      ->fields('register', ['id', 'typical'])
      ->condition('official_id', $nid)
      ->execute();

    return $query->fetchAll();
  }

  /**
   * Cuenta el total de registros de asistencia según filtros.
   *
   * @param array $filters
   *   Filtros opcionales: nid, name, typical.
   *
   * @return int
   *   Número total de registros.
   */
  public function countDataRegister(array $filters = []): int {
    $query = $this->connection->select('create_public_official_register_attendance', 'register');
    $query->addExpression('COUNT(*)', 'total');

    $query->innerJoin('create_public_official_attendance_data', 'attendance', 'register.id = attendance.id_register');

    if (!empty($filters['nid'])) {
      $query->condition('register.official_id', $filters['nid'], '=');
    }
    if (!empty($filters['name'])) {
      $query->condition('attendance.name', '%' . $this->connection->escapeLike($filters['name']) . '%', 'LIKE');
    }
    if (isset($filters['typical']) && $filters['typical'] !== '') {
      $query->condition('register.typical', $filters['typical'], '=');
    }
    if (!empty($filters['gender'])) {
      $query->condition('attendance.gender', $filters['gender'], '=');
    }

    return (int) $query->execute()->fetchField();
  }

  /**
   * Obtiene registros de asistencia paginados para exportación (sin PagerExtender).
   *
   * @param int $offset
   *   Posición inicial.
   * @param int $limit
   *   Cantidad de registros a obtener.
   * @param array $filters
   *   Filtros opcionales: nid, name, typical.
   *
   * @return array
   *   Array de registros de asistencia.
   */
  public function getDataForExport(int $offset, int $limit, array $filters = []): array {
    $query = $this->connection->select('create_public_official_register_attendance', 'register');
    $query->fields('register', ['official_id', 'typical']);

    $query->innerJoin('create_public_official_attendance_data', 'attendance', 'register.id = attendance.id_register');
    $query->fields('attendance', ['name', 'gender', 'position', 'dependency', 'created']);

    $query->orderBy('register.created', 'DESC');

    if (!empty($filters['nid'])) {
      $query->condition('register.official_id', $filters['nid'], '=');
    }
    if (!empty($filters['name'])) {
      $query->condition('attendance.name', '%' . $this->connection->escapeLike($filters['name']) . '%', 'LIKE');
    }
    if (isset($filters['typical']) && $filters['typical'] !== '') {
      $query->condition('register.typical', $filters['typical'], '=');
    }
    if (!empty($filters['gender'])) {
      $query->condition('attendance.gender', $filters['gender'], '=');
    }

    $query->range($offset, $limit);

    return $query->execute()->fetchAll();
  }

  public function getTotalRegisters(): int {
    $query = $this->connection->select('create_public_official_register_attendance', 'register');
    $query->addExpression('COUNT(*)', 'total');
    return (int) $query->execute()->fetchField();
  }

  public function getDependency(): array {
    $dependencies = [
      'CARLOS PÉREZ' => 'CARLOS PÉREZ',
      'I.E. Abraham Reyes' => 'I.E. Abraham Reyes',
      'I.E. Alberto Diaz Muñoz' => 'I.E. Alberto Diaz Muñoz',
      'I.E. Alberto Lebrun Munera' => 'I.E. Alberto Lebrun Munera',
      'I.E. Andres Bello' => 'I.E. Andres Bello',
      'I.E. Antonio Maria Bedoya' => 'I.E. Antonio Maria Bedoya',
      'I.E. Atanasio Girardot' => 'I.E. Atanasio Girardot',
      'I.E. Barrio Paris' => 'I.E. Barrio Paris',
      'I.E. Betsabé Espinal' => 'I.E. Betsabé Espinal',
      'I.E. Carlos Perez Mejia' => 'I.E. Carlos Perez Mejia',
      'I.E. Centenario de Bello' => 'I.E. Centenario de Bello',
      'I.E. Cincuentenario de Fabricato' => 'I.E. Cincuentenario de Fabricato',
      'I.E. Comercial Antonio Roldan Betancur' => 'I.E. Comercial Antonio Roldan Betancur',
      'I.E. Concejo De Bello' => 'I.E. Concejo De Bello',
      'I.E. Divina Eucaristia' => 'I.E. Divina Eucaristia',
      'I.E. Federico Sierra Arango' => 'I.E. Federico Sierra Arango',
      'I.E. Fernando Velez' => 'I.E. Fernando Velez',
      'I.E. Fontidueño Jaime Arango Rojas' => 'I.E. Fontidueño Jaime Arango Rojas',
      'I.E. Gilberto Echeverry Mejia' => 'I.E. Gilberto Echeverry Mejia',
      'I.E. Hernan Villa Baena' => 'I.E. Hernan Villa Baena',
      'I.E. Jorge Eliecer Gaitan Ayala' => 'I.E. Jorge Eliecer Gaitan Ayala',
      'I.E. Josefa Campos' => 'I.E. Josefa Campos',
      'I.E. La Camila' => 'I.E. La Camila',
      'I.E. La Gabriela' => 'I.E. La Gabriela',
      'I.E. La Milagrosa' => 'I.E. La Milagrosa',
      'I.E. La Primavera' => 'I.E. La Primavera',
      'I.E. La Union' => 'I.E. La Union',
      'I.E. Liceo Antioqueno' => 'I.E. Liceo Antioqueno',
      'I.E. Marco Fidel Suarez' => 'I.E. Marco Fidel Suarez',
      'I.E. Navarra' => 'I.E. Navarra',
      'I.E. Nueva Generación' => 'I.E. Nueva Generación',
      'I.E. Playa Rica' => 'I.E. Playa Rica',
      'I.E. Raquel Jaramillo' => 'I.E. Raquel Jaramillo',
      'I.E. Sagrado Corazon' => 'I.E. Sagrado Corazon',
      'I.E. San Felix' => 'I.E. San Felix',
      'I.E. San Gabriel Arcangel' => 'I.E. San Gabriel Arcangel',
      'I.E. Santa Catalina' => 'I.E. Santa Catalina',
      'I.E. Tomas Cadavid Restrepo' => 'I.E. Tomas Cadavid Restrepo',
      'I.E. Villa Del Sol' => 'I.E. Villa Del Sol',
      'Secretaría De Educación Municipal De Bello' => 'Secretaría De Educación Municipal De Bello',
    ];
    return $dependencies;
  }

  /**
   * Returns a list of available positions.
   *
   * @return array
   *   An associative array of positions where keys and values are the same.
   */
  public function getListPosition(): array {
    $positions = [
      'Coordinador' => 'Coordinador',
      'Director De Nucleo' => 'Director De Nucleo',
      'Docente Orientador' => 'Docente Orientador',
      'Docente con funciones de apoyo' => 'Docente con funciones de apoyo',
      'Docente de aula' => 'Docente de aula',
      'Rector' => 'Rector',
    ];
    return $positions;
  }

}
