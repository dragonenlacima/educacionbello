# Tarea 02: cerrar las carreras en la reversa y en el borrado de vacantes

## Contexto

Módulo `audiencias` (Drupal 11), continuación de
`prompts/01-fix-carrera-asignacion.md`, que ya fue aplicado y verificado.

**El módulo está en producción con vacantes asignadas.** Cambios mínimos,
acotados y no destructivos. No refactorices nada que no esté pedido aquí.

### Directorio de trabajo y rutas

La sesión se ejecuta desde la raíz del proyecto (la que contiene `composer.json`
y `web/`). El módulo está en `web/modules/custom/audiencias/`. Todas las rutas de
este documento escritas como `src/...` son **relativas a la raíz del módulo**;
prefíjalas con esa ruta.

La raíz del proyecto **no es un repositorio git**: `audiencias` es un repo
independiente. Cualquier `git status` o `git diff` va ejecutado dentro del
directorio del módulo. En esta tarea no debes hacer commit.

### Estado actual de los datos (contexto real, ya verificado)

- Cargo 1 «Secretario»: 29 funcionarios, 60 vacantes, 31 libres.
- Cargo 2 «Auxiliar Administrativo»: 7 funcionarios, 31 vacantes, 24 libres.
- No hay funcionarios pendientes. La audiencia en curso terminó.

Volumen bajo: **no optimices consultas ni agregues paginación.** No es el objeto
de esta tarea y no hace falta.

### Arquitectura vigente (respétala)

- Tablas crudas vía `hook_schema()`. Sin entidades de contenido ni Field API.
- Capa de repositorios: controladores y formularios **nunca** ejecutan SQL.
- Inyección de dependencias por constructor. Prohibido `\Drupal::` estático.
- Query builder con placeholders. Prohibido concatenar SQL.

## El problema

La tarea 01 cerró la carrera en `asignar()`. Quedaron dos de la misma clase, ya
diagnosticadas:

### Problema A — `desasignar()` condiciona solo por clave primaria

`VacanteRepository::desasignar()` pone `funcionario_id` en NULL filtrando solo
por `vacante_id`. Si un operador abre la pantalla de reversa de una vacante
ocupada por el funcionario A y, mientras tanto, otro la reversa y la reasigna a
B, al confirmar se desasigna a **B**, que no tenía nada que ver.

Llamadores afectados (verifícalos, las líneas son de referencia):
`VacanteDesasignarForm`, `VacanteReversaMasivaForm`, `FuncionarioDeleteForm`,
`FuncionarioEliminacionMasivaForm`.

### Problema B — el borrado de vacantes no reconfirma que sigan libres

`VacanteDeleteForm` y `VacanteEliminacionMasivaForm` verifican que la vacante no
esté asignada **antes** de borrar, pero el DELETE en sí
(`RepositoryBase::delete()`) filtra solo por clave primaria. Una vacante asignada
en esa ventana se borraría igual, destruyendo la asignación sin dejar rastro.

Agravante: hay 55 vacantes libres y ninguna pendiente, así que una depuración del
listado es un escenario plausible a corto plazo.

## Paso 1 — Verificación previa OBLIGATORIA

Antes de modificar nada, lee los seis formularios llamadores y responde:

1. Para cada llamada a `desasignar()`: ¿el formulario **conoce el
   `funcionario_id` que espera desasignar** en el momento de confirmar, sin
   agregar campos nuevos al formulario?
   - En `FuncionarioDeleteForm` y `FuncionarioEliminacionMasivaForm` debería ser
     trivial: el ocupante esperado es el funcionario que se está borrando.
   - En `VacanteReversaMasivaForm` puede que solo se disponga de los
     `vacante_id` seleccionados. **Si es así, DETENTE en ese formulario, no lo
     modifiques, y propónme cómo obtener el ocupante esperado** (campo oculto por
     fila, o releer y comparar). No lo rediseñes por tu cuenta.

2. ¿`RepositoryBase::delete()` se usa desde algún otro sitio para borrar
   vacantes, además de los dos formularios mencionados?

Reporta ambas respuestas. Continúa con los pasos siguientes solo en los
formularios donde la respuesta a (1) sea afirmativa; los demás quedan pendientes
de mi decisión.

## Paso 2 — `VacanteRepository::desasignar()`

Agrega un segundo parámetro **opcional** con el ocupante esperado, para no romper
ninguna llamada existente mientras se actualizan:

```php
public function desasignar(int $vacante_id, ?int $funcionario_esperado = NULL): int
```

Cuando `$funcionario_esperado` no sea NULL, añade al UPDATE la condición
`->condition('funcionario_id', $funcionario_esperado)`. Cuando sea NULL,
comportamiento actual sin cambios.

Documenta en el docblock que el retorno `0` con `$funcionario_esperado` no nulo
significa **«la vacante ya no la ocupa ese funcionario»**, y que el llamador debe
distinguir dos subcasos (ver paso 4).

## Paso 3 — Borrado seguro de vacantes

**No modifiques `RepositoryBase::delete()`**: lo comparten los cuatro
repositorios y cambiarlo afectaría instituciones, cargos y funcionarios sin
necesidad.

En su lugar, agrega a `VacanteRepository` un método nuevo:

```php
public function deleteSiLibre(int $vacante_id): int
```

Ejecuta el DELETE con `->condition($this->getClavePrimaria(), $vacante_id)` **y**
`->isNull('funcionario_id')`. Retorno `0` = la vacante no existe o fue asignada
en el intervalo; en ambos casos NO se borró.

Cambia `VacanteDeleteForm` y `VacanteEliminacionMasivaForm` para que usen
`deleteSiLibre()` en lugar de `delete()`. **Conserva las validaciones previas que
ya tienen**: siguen siendo la capa de experiencia (mensaje claro y temprano); el
nuevo método es la capa de correctitud. Ninguna sustituye a la otra.

En el masivo, un `0` no aborta el lote: cuéntalo aparte e infórmalo al final,
igual que se hizo con `$fallidos` en `SorteoVacantesForm`.

## Paso 4 — Manejo de error en los llamadores de `desasignar()`

Pasa el ocupante esperado en cada llamada y captura el retorno. Ante un `0`,
**relee la vacante y distingue**, porque no todo `0` es un conflicto:

- `funcionario_id` es NULL → **ya estaba reversada**. Es benigno (doble clic,
  recarga, reenvío). Informa con `addStatus` o `addWarning`, nunca con
  `addError`, y continúa el flujo normalmente.
- `funcionario_id` es otro funcionario → **conflicto real**. `addError` nombrando
  al ocupante actual y pidiendo actualizar la pantalla. No continúes como si
  hubiera tenido éxito.

Todos los mensajes en español, vía el servicio `messenger` **inyectado** (nunca
`\Drupal::messenger()`), siguiendo el estilo de los mensajes existentes.

Si `FuncionarioDeleteForm` o `FuncionarioEliminacionMasivaForm` ya envuelven la
operación en una transacción, **respétala**: el fallo de la reversa debe impedir
el borrado del funcionario, no dejarlo a medias. No agregues transacciones donde
no existan; si detectas que hace falta una, anótalo como observación.

## Restricciones

- Diff mínimo. No reformatees, no reordenes métodos, no renombres nada.
- No toques el esquema. Esta tarea no lleva `hook_update_N`.
- No modifiques `audiencias.routing.yml` ni `audiencias.permissions.yml`.
- No modifiques `RepositoryBase.php`.
- No optimices el N+1 de `AsignacionController::obtenerRegistros()` ni agregues
  paginación: está fuera de alcance y el volumen no lo justifica.
- No salgas del directorio del módulo. No toques el tema `bellodev` ni el módulo
  `create_public_official`.
- No agregues dependencias a `composer.json`.
- El proyecto no tiene PHPUnit ni linters: no inventes ni ejecutes comandos de
  test. Verifica por lectura del código y, si quieres confirmar el SQL generado,
  hazlo sobre una tabla temporal como en la tarea 01 — **nunca contra las tablas
  reales**.
- Comentarios, docblocks y mensajes de UI en español, con el estilo del módulo.

## Entregable

1. Las dos respuestas del paso 1.
2. Archivos modificados, con una línea por cambio.
3. Cada llamada a `desasignar()` y a `deleteSiLibre()`, con su manejo de error.
4. El SQL generado por `desasignar()` con ocupante esperado y por
   `deleteSiLibre()`.
5. Cualquier flujo que siga expuesto a esta clase de carrera tras el cambio.

No hagas commit. Déjame revisar el diff.
