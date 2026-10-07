# Audiencias

Módulo personalizado de Drupal 11 para gestionar la **asignación de vacantes
administrativas del municipio de Bello** mediante un CRUD completo sobre tablas
personalizadas (no entidades de contenido).

---

## Propósito

El módulo permite administrar instituciones, cargos, funcionarios y vacantes, y
asignar funcionarios a vacantes en un flujo de **dos pasos** (primero se crea la
vacante sin funcionario; la asignación es una operación posterior). Incluye,
además, una pantalla de asignación por cargo, un **sorteo aleatorio** de
vacantes para los funcionarios pendientes, reversa de asignaciones (individual y
masiva), eliminación masiva con integridad referencial y exportación a Excel.

---

## Requisitos

- **Drupal 11** (`core_version_requirement: ^11`).
- **PhpSpreadsheet** (`phpoffice/phpspreadsheet`), ya disponible en el proyecto.
  Se usa para exportar listados a Excel.
- Permiso **`administrar vacantes`** para acceder a todas las rutas del módulo.
  Está declarado con `restrict access: true` en `audiencias.permissions.yml`.

---

## Arquitectura

Decisiones de diseño obligatorias del módulo:

- **Tablas crudas vía `hook_schema()`.** Las cuatro tablas (prefijo
  `audiencias_`) se definen en `audiencias.install`. **No** se usan
  `ContentEntityBase` ni la Field API.
- **Capa de repositorios.** Una clase abstracta `RepositoryBase` concentra el
  CRUD genérico (`loadAll()`, `loadAllPaginado()`, `load()`, `save()`,
  `delete()`) y hay **un repositorio concreto por tabla**
  (`InstitucionRepository`, `CargoRepository`, `FuncionarioRepository`,
  `VacanteRepository`). Controladores y formularios son delgados: **nunca**
  ejecutan SQL; siempre pasan por los repositorios.
- **Inyección de dependencias.** Controladores, formularios y servicios reciben
  sus dependencias por constructor a través de `create()`. **Prohibido** usar
  `\Drupal::` de forma estática dentro de las clases (se inyectan `Connection`,
  `Messenger`, los repositorios, etc.).
- **Query builder con placeholders.** Toda consulta usa la API de base de datos
  (`insert`/`select`/`update`/`delete`) con marcadores. **Prohibido** concatenar
  SQL.

### Estructura de carpetas

```
audiencias/
├── audiencias.info.yml
├── audiencias.install                 # hook_schema() + hook_update_N
├── audiencias.permissions.yml         # permiso «administrar vacantes»
├── audiencias.routing.yml             # rutas (todas exigen el permiso)
├── audiencias.services.yml            # los 4 repositorios como servicios
├── audiencias.libraries.yml           # CSS/JS (overlay del sorteo, etc.)
├── audiencias.links.menu.yml          # menú de administración (Toolbar)
├── audiencias.links.action.yml        # botones «+ Agregar» por listado
├── audiencias.links.task.yml          # pestañas locales
└── src/
    ├── Repository/                    # capa de acceso a datos
    ├── Controller/                    # overview, listados, asignación, export
    └── Form/                          # CRUD, asignación, sorteo, masivos
```

---

## Modelo de datos

Cuatro tablas con prefijo `audiencias_`. El detalle canónico vive en
`audiencias.install`; este es el resumen.

### `audiencias_institucion`

| Campo            | Tipo           | Reglas                              |
|------------------|----------------|-------------------------------------|
| `institucion_id` | serial         | Llave primaria, autoincremental.    |
| `nombre`         | varchar(120)   | Obligatorio. **Único.**             |
| `ubicacion`      | varchar(200)   | Opcional.                           |

- Llave única: `nombre`.

### `audiencias_cargo`

| Campo      | Tipo             | Reglas                            |
|------------|------------------|-----------------------------------|
| `cargo_id` | serial           | Llave primaria, autoincremental.  |
| `nombre`   | varchar(120)     | Obligatorio.                      |
| `estado`   | int tiny (def 1) | 1 = activo, 0 = inactivo.         |

### `audiencias_funcionario`

| Campo            | Tipo         | Reglas                                          |
|------------------|--------------|-------------------------------------------------|
| `funcionario_id` | serial       | Llave primaria, autoincremental.                |
| `nombre`         | varchar(120) | Obligatorio.                                    |
| `documento`      | varchar(20)  | Cédula. Obligatorio. **Único global.**          |
| `posicion`       | int          | Obligatorio. **Único por cargo.**               |
| `cargo_id`       | int          | Obligatorio. FK → `audiencias_cargo.cargo_id`.  |

- Llaves únicas: `documento` (simple) y `cargo_posicion` **compuesta**
  `(cargo_id, posicion)`. Como `cargo_id` es la columna líder de esa llave, **no**
  se declara un índice independiente para él.

### `audiencias_vacante`

| Campo            | Tipo             | Reglas                                                  |
|------------------|------------------|---------------------------------------------------------|
| `vacante_id`     | serial           | Llave primaria, autoincremental.                        |
| `nombre`         | varchar(120)     | Obligatorio.                                            |
| `estado`         | int tiny (def 1) | 1 = activa, 0 = inactiva.                               |
| `institucion_id` | int              | Obligatorio. FK → `audiencias_institucion`.             |
| `cargo_id`       | int              | Obligatorio. FK → `audiencias_cargo`.                   |
| `funcionario_id` | int (nullable)   | **NULL** al crear; se llena al asignar. **Único.** FK.  |
| `tipo_vacante`   | varchar(32)      | `Ascenso` (por defecto) o `Abierto`.                    |
| `denominacion`   | varchar(32)      | Método de asignación (audiencia/sorteo); NULL si está sin asignar. |

- Llave única: `funcionario_id` (garantiza la relación uno a uno).
- Índices: `institucion_id` y `cargo_id`.

### Relaciones y cardinalidad

- **Cargo → Funcionario** (uno a muchos): cada funcionario tiene un solo cargo
  (FK `cargo_id` en funcionario).
- **Vacante → Institución** (muchos a uno): FK `institucion_id`.
- **Vacante → Cargo** (muchos a uno): FK `cargo_id`.
- **Vacante → Funcionario** (uno a uno): un funcionario solo puede ocupar una
  vacante (llave única sobre `funcionario_id`).

### Campos `tipo_vacante` y `denominacion`

Se incorporaron a `audiencias_vacante` después del esquema inicial mediante
`audiencias_update_9001()` (no destructivo, idempotente):

- `tipo_vacante`: distingue las vacantes de **Ascenso** de las **Abierto**.
  Entra con `default 'Ascenso'`, así que las vacantes preexistentes quedan como
  Ascenso.
- `denominacion`: registra **cómo** se asignó la vacante (por audiencia o por
  sorteo). Es NULL mientras la vacante esté sin asignar.

---

## Flujos

### CRUD por entidad

Cada entidad tiene alta, edición y borrado (`/admin/audiencias/{ent}/…`). Los
formularios validan en `validateForm()` con mensajes claros en español **antes**
de que la base de datos lance una excepción:

- **Institución:** nombre único (excluye el propio registro al editar).
- **Cargo:** nombre obligatorio; `estado` con checkbox «Activo».
- **Funcionario:** documento numérico y único global; `posicion` única dentro
  del cargo (el mensaje de conflicto nombra al funcionario y la cédula que ya
  ocupan esa posición); `cargo_id` obligatorio (select de cargos).
- **Vacante:** se crea **sin funcionario** (`funcionario_id` = NULL); la
  asignación es un paso aparte. El **tipo** («Ascenso» o «Abierto») es
  obligatorio y no tiene valor por defecto: hay que elegirlo. No se puede
  **desactivar** una vacante asignada: primero hay que reversar su asignación,
  de modo que toda vacante inactiva está siempre libre.

El borrado respeta la **integridad referencial**:

- No se elimina una **institución** con vacantes asociadas.
- No se elimina un **cargo** con funcionarios o vacantes (se sugiere
  inactivarlo).
- Eliminar un **funcionario** libera su vacante en una transacción (la vacante
  no se borra; queda «Sin asignar»).
- No se elimina una **vacante** que esté **asignada**: primero hay que reversar
  su asignación. El borrado individual lo bloquea (oculta el botón de confirmar
  y ofrece reversar) y el masivo solo borra las vacantes sin asignar, informando
  cuántas asignadas se excluyeron.

### Asignación por cargo

`AsignacionController::pantalla()` (`/admin/audiencias/asignacion/{cargo_id}`)
ofrece un tablero por cargo con los funcionarios y las vacantes de ese cargo.
Solo se ofrecen y se cuentan como libres las vacantes **activas** sin
funcionario (`VacanteRepository::disponiblesPorCargo()`); una vacante inactiva
no aparece en la audiencia. La asignación directa de un funcionario a una
vacante valida que:

0. La vacante esté **activa** (además, `VacanteRepository::asignar()` exige
   `estado` = 1 en el propio UPDATE).
1. El funcionario exista (búsqueda por cédula).
2. El **cargo del funcionario coincida con el de la vacante** (si no, se rechaza
   nombrando ambos cargos).
3. El funcionario no esté ya asignado a otra vacante.

### Sorteo

`SorteoVacantesForm` (`/admin/audiencias/asignacion/{cargo_id}/sorteo`) reparte
de forma **aleatoria** las vacantes disponibles (**activas** y sin funcionario)
entre los funcionarios pendientes del cargo, marcando `denominacion` como
sorteo. Las vacantes inactivas nunca entran al sorteo. El overlay visual del
sorteo se sirve desde `audiencias.libraries.yml` / `css/sorteo.css`.

### Reversa de asignación

- **Individual:** `VacanteDesasignarForm`
  (`/admin/audiencias/vacante/{vacante_id}/desasignar`) deja la vacante «Sin
  asignar». Respeta un `destination` **interno** si viene en la URL; si no,
  regresa a la pantalla de asignación del cargo.
- **Masiva:** `VacanteReversaMasivaForm` (el propio listado de vacantes) permite
  seleccionar varias y reversarlas de una vez.

### Eliminación masiva

`InstitucionEliminacionMasivaForm` y `FuncionarioEliminacionMasivaForm` son los
listados de esas entidades y permiten borrar varios registros a la vez,
aplicando las mismas reglas de integridad referencial del borrado individual.

Las **vacantes** tienen su propia pestaña «Eliminar vacantes»
(`VacanteEliminacionMasivaForm`, ruta `audiencias.vacante.eliminar_masivo`),
separada del listado/reversa. Ofrece «Eliminar seleccionadas» y «Eliminar todas
las no asignadas», con paso de confirmación. Solo borra vacantes **sin asignar**:
las asignadas aparecen con la casilla deshabilitada, se excluyen de «Eliminar
todas» y, si se colaran, se omiten al confirmar; todo el borrado va en una
transacción.

### Listados y paginación

Los listados de **solo lectura** (p. ej. cargos) usan paginación real vía
`loadAllPaginado()` (`PagerSelectExtender`) y `'#type' => 'pager'`. Los listados
que son **formularios de operación masiva** muestran todas las filas (sin pager)
para no romper la selección «todos».

---

## Instalación y actualización

```bash
# Instalar el módulo (crea las cuatro tablas vía hook_schema()).
drush en audiencias

# Aplicar los cambios de esquema posteriores (hook_update_N), p. ej. los campos
# tipo_vacante y denominacion (audiencias_update_9001).
drush updb

# Limpiar caché de rutas/menús tras cambios.
drush cr
```

`hook_update_9001` es **idempotente**: solo agrega `tipo_vacante` y
`denominacion` si aún no existen, sin alterar datos en producción.

---

## Operación

El módulo no incluye scripts de carga de datos. Las instituciones, los
funcionarios y las vacantes se registran desde la interfaz de administración
(`/admin/audiencias/...`), respetando el orden que imponen las llaves foráneas:

1. **Cargos** (`/admin/audiencias/cargo`).
2. **Instituciones** (`/admin/audiencias/institucion`).
3. **Funcionarios** (`/admin/audiencias/funcionario`), cada uno con su cargo.
4. **Vacantes** (`/admin/audiencias/vacante`), con institución y cargo.

Durante la audiencia, los funcionarios presentes se asignan directamente desde
la pantalla de asignación del cargo; a los que faltan se les adjudica vacante
con el **sorteo** entre las vacantes que quedan libres.
