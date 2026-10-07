<?php

declare(strict_types=1);

namespace Drupal\audiencias\Repository;

/**
 * Repositorio de acceso a datos para la tabla «audiencias_vacante».
 *
 * Añade una consulta para detectar si un funcionario ya está asignado a una
 * vacante (relación uno a uno).
 */
class VacanteRepository extends RepositoryBase {

  /**
   * {@inheritdoc}
   */
  protected function getTabla(): string {
    return 'audiencias_vacante';
  }

  /**
   * {@inheritdoc}
   */
  protected function getClavePrimaria(): string {
    return 'vacante_id';
  }

  /**
   * {@inheritdoc}
   *
   * En la inserción garantiza los valores por defecto de los campos nuevos:
   * «tipo_vacante» = «Ascenso» si no llega (o llega vacío) y «denominacion» =
   * NULL si no se recibe. En la actualización se respetan los valores recibidos.
   */
  public function save(array $valores) {
    // Solo al insertar (sin clave primaria) se aplican los valores por defecto.
    if (empty($valores[$this->getClavePrimaria()])) {
      if (!isset($valores['tipo_vacante']) || $valores['tipo_vacante'] === '') {
        $valores['tipo_vacante'] = 'Ascenso';
      }
      if (!array_key_exists('denominacion', $valores)) {
        $valores['denominacion'] = NULL;
      }
    }

    return parent::save($valores);
  }

  /**
   * Busca la vacante asignada a un funcionario determinado.
   *
   * @param int $funcionario_id
   *   Identificador del funcionario.
   *
   * @return object|null
   *   La vacante asignada a ese funcionario, o NULL si no está asignado a
   *   ninguna.
   */
  public function buscarPorFuncionario(int $funcionario_id): ?object {
    $registro = $this->database->select($this->getTabla(), 'v')
      ->fields('v')
      ->condition('funcionario_id', $funcionario_id)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $registro ?: NULL;
  }

  /**
   * Reversa la asignación de una vacante dejando «funcionario_id» en NULL.
   *
   * Escribe NULL de forma EXPLÍCITA en «funcionario_id» y en «denominacion» (no
   * las omite), de modo que el funcionario quede libre para asignarse a otra
   * vacante y la vacante quede sin método de asignación.
   *
   * @param int $vacante_id
   *   Identificador de la vacante a desasignar.
   * @param int|null $funcionario_esperado
   *   Identificador del funcionario que el llamador espera encontrar ocupando la
   *   vacante. Si se indica, la reversa SOLO se aplica mientras la vacante siga
   *   ocupada por ese funcionario, lo que cierra la ventana de carrera entre lo
   *   que vio el operador y el guardado. Si es NULL, el comportamiento es el
   *   histórico: reversa por clave primaria sin comprobar el ocupante.
   *
   * @return int
   *   Número de filas afectadas. Con «$funcionario_esperado» no nulo, un 0
   *   significa que LA VACANTE YA NO LA OCUPA ESE FUNCIONARIO, es decir, que la
   *   reversa NO se aplicó. Quien llama debe releer la vacante y distinguir dos
   *   subcasos: si «funcionario_id» quedó en NULL, ya estaba reversada (benigno,
   *   p. ej. un reenvío); si la ocupa otro funcionario, es un conflicto real y no
   *   debe continuarse como si hubiera tenido éxito.
   */
  public function desasignar(int $vacante_id, ?int $funcionario_esperado = NULL): int {
    $consulta = $this->database->update($this->getTabla())
      ->fields([
        'funcionario_id' => NULL,
        'denominacion' => NULL,
      ])
      ->condition($this->getClavePrimaria(), $vacante_id);

    if ($funcionario_esperado !== NULL) {
      $consulta->condition('funcionario_id', $funcionario_esperado);
    }

    return $consulta->execute();
  }

  /**
   * Libera la vacante que ocupe un funcionario, sea cual sea.
   *
   * A diferencia de desasignar(), NO se condiciona por «vacante_id»: el UPDATE
   * ataca directamente al funcionario, de modo que libera de forma ATÓMICA
   * cualquier vacante que esté ocupando en ese instante, incluso si lo movieron
   * a otra entre una consulta previa y esta llamada. La llave única sobre
   * «funcionario_id» garantiza que a lo sumo es una.
   *
   * Es la operación que deben usar los borrados de funcionario: evita que quede
   * una vacante apuntando a un funcionario ya eliminado. Las «foreign keys» de
   * hook_schema() son documentación —Drupal no crea restricciones reales en
   * MySQL—, así que nada impediría ese huérfano.
   *
   * @param int $funcionario_id
   *   Identificador del funcionario cuya vacante se libera.
   *
   * @return int
   *   Número de filas afectadas: 1 si ocupaba una vacante y quedó liberada, 0 si
   *   no ocupaba ninguna. El 0 es un caso NORMAL, no un fallo.
   */
  public function desasignarPorFuncionario(int $funcionario_id): int {
    return $this->database->update($this->getTabla())
      ->fields([
        'funcionario_id' => NULL,
        'denominacion' => NULL,
      ])
      ->condition('funcionario_id', $funcionario_id)
      ->execute();
  }

  /**
   * Devuelve las vacantes de un cargo que están disponibles para asignar.
   *
   * Disponible = ACTIVA («estado» = 1) y sin funcionario. Es la fuente única de
   * las vacantes ofrecidas en la audiencia (desplegable del tablero), de las que
   * entran al sorteo y del conteo de «libres»: una vacante inactiva no aparece
   * en ninguno de ellos.
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   *
   * @return array
   *   Lista de vacantes activas con «funcionario_id» NULL, ordenadas por
   *   institución y nombre; arreglo vacío si no hay ninguna.
   */
  public function disponiblesPorCargo(int $cargo_id): array {
    return $this->database->select($this->getTabla(), 'v')
      ->fields('v')
      ->condition('cargo_id', $cargo_id)
      ->condition('estado', 1)
      ->isNull('funcionario_id')
      ->orderBy('institucion_id', 'ASC')
      ->orderBy('nombre', 'ASC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Devuelve todas las vacantes ASIGNADAS (con funcionario), de ambos cargos.
   *
   * Sirve para la reversa masiva «todas las asignadas», que no depende de la
   * selección ni de la paginación.
   *
   * @return array
   *   Lista de vacantes con «funcionario_id» no nulo, ordenadas por cargo,
   *   institución y nombre; arreglo vacío si no hay ninguna.
   */
  public function buscarAsignadas(): array {
    return $this->database->select($this->getTabla(), 'v')
      ->fields('v')
      ->isNotNull('funcionario_id')
      ->orderBy('cargo_id', 'ASC')
      ->orderBy('institucion_id', 'ASC')
      ->orderBy('nombre', 'ASC')
      ->execute()
      ->fetchAll();
  }

  /**
   * Cuenta cuántas vacantes referencian a una institución.
   *
   * Permite bloquear la eliminación de una institución que aún tenga vacantes
   * asociadas (hay que quitar sus vacantes primero).
   *
   * @param int $institucion_id
   *   Identificador de la institución.
   *
   * @return int
   *   Número de vacantes cuyo «institucion_id» coincide.
   */
  public function contarPorInstitucion(int $institucion_id): int {
    return (int) $this->database->select($this->getTabla(), 'v')
      ->condition('institucion_id', $institucion_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Cuenta cuántas vacantes pertenecen a un cargo.
   *
   * Permite bloquear la eliminación de un cargo que aún tenga vacantes
   * asociadas (conviene inactivarlo en lugar de borrarlo).
   *
   * @param int $cargo_id
   *   Identificador del cargo.
   *
   * @return int
   *   Número de vacantes cuyo «cargo_id» coincide.
   */
  public function contarPorCargo(int $cargo_id): int {
    return (int) $this->database->select($this->getTabla(), 'v')
      ->condition('cargo_id', $cargo_id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Asigna un funcionario a una vacante (simétrico a desasignar()).
   *
   * Además del funcionario, fija la «denominacion» (método de asignación), que
   * indica cómo se realizó: «audiencia» (por botones) o «sorteo».
   *
   * La actualización es ATÓMICA: solo escribe si la vacante está ACTIVA y sigue
   * libre —o si ya la ocupa ESTE mismo funcionario—, de modo que dos operaciones
   * simultáneas no puedan sobrescribirse entre sí y una vacante desactivada en
   * el intervalo no llegue a asignarse. No necesita transacción ni «SELECT ...
   * FOR UPDATE».
   *
   * @param int $vacante_id
   *   Identificador de la vacante.
   * @param int $funcionario_id
   *   Identificador del funcionario a asignar.
   * @param string|null $denominacion
   *   Método de asignación a registrar (p. ej. «audiencia» o «sorteo»); NULL si
   *   no se especifica.
   *
   * @return int
   *   Número de filas afectadas. Un 0 ya NO significa «sin cambios»: significa
   *   que la vacante no existe, que está INACTIVA o que YA ESTÁ OCUPADA POR OTRO
   *   funcionario, es decir, que la asignación NO se realizó. Quien llama debe tratarlo como
   *   FALLO e informarlo al usuario. El reenvío del mismo funcionario sobre su
   *   propia vacante sí afecta 1 fila aunque no cambie ningún valor (el driver
   *   MySQL de Drupal activa FOUND_ROWS, que cuenta coincidencias y no solo
   *   cambios), así que ese caso sigue siendo idempotente.
   */
  public function asignar(int $vacante_id, int $funcionario_id, ?string $denominacion = NULL): int {
    // La vacante debe seguir libre en el momento exacto del UPDATE, o estar ya
    // ocupada por este mismo funcionario: cierra la ventana de carrera entre la
    // validación del formulario y el guardado, sin romper el reenvío idempotente
    // (que además permite reescribir la «denominacion»).
    $condicion = $this->database->condition('OR');
    $condicion->isNull('funcionario_id')
      ->condition('funcionario_id', $funcionario_id);

    return $this->database->update($this->getTabla())
      ->fields([
        'funcionario_id' => $funcionario_id,
        'denominacion' => $denominacion,
      ])
      ->condition($this->getClavePrimaria(), $vacante_id)
      // Una vacante inactiva nunca se asigna, ni en la audiencia ni por sorteo.
      ->condition('estado', 1)
      ->condition($condicion)
      ->execute();
  }

  /**
   * Borra una vacante SOLO si sigue sin funcionario asignado.
   *
   * Complementa —no sustituye— las comprobaciones previas de los formularios:
   * aquellas dan el mensaje claro y temprano, y esta condición garantiza que una
   * vacante asignada en la ventana entre la comprobación y el borrado no se
   * elimine igualmente, destruyendo la asignación sin dejar rastro.
   *
   * No se modifica RepositoryBase::delete() porque lo comparten los cuatro
   * repositorios y esta regla solo aplica a las vacantes.
   *
   * @param int $vacante_id
   *   Identificador de la vacante a eliminar.
   *
   * @return int
   *   Número de filas eliminadas. Un 0 significa que la vacante no existe o que
   *   fue asignada en el intervalo; en ambos casos NO se borró, y quien llama
   *   debe informarlo en lugar de dar por hecho el borrado.
   */
  public function deleteSiLibre(int $vacante_id): int {
    return $this->database->delete($this->getTabla())
      ->condition($this->getClavePrimaria(), $vacante_id)
      ->isNull('funcionario_id')
      ->execute();
  }

}
