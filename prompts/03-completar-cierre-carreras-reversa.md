# Tarea 03: completar el cierre de carreras en la reversa

## Contexto

Módulo `audiencias` (Drupal 11). Continuación de
`prompts/02-fix-carreras-reversa-borrado.md`, que quedó **parcialmente
aplicado**: dos formularios se detuvieron a la espera de decisión.

**El módulo está en producción con vacantes asignadas.** Cambios mínimos y
acotados. No refactorices nada que no esté pedido aquí.

### Directorio de trabajo y rutas

Sesión ejecutada desde la raíz del proyecto (la que contiene `composer.json` y
`web/`). El módulo está en `web/modules/custom/audiencias/`. Las rutas escritas
como `src/...` son relativas a la raíz del módulo.

La raíz **no es un repositorio git**: `audiencias` es un repo independiente.
Cualquier operación de git va dentro del directorio del módulo. No hagas commit.

### Arquitectura vigente (respétala)

- Tablas crudas vía `hook_schema()`. Sin entidades de contenido ni Field API.
- Capa de repositorios: controladores y formularios **nunca** ejecutan SQL.
- Inyección de dependencias por constructor. Prohibido `\Drupal::` estático.
- Query builder con placeholders. Prohibido concatenar SQL.

### Decisiones ya tomadas

Tus dos propuestas de la tarea 02 quedan **aprobadas tal como las planteaste**:
campo oculto en el formulario individual, y pares en el `conjunto` del multipaso.
El análisis de seguridad del campo oculto es correcto y se acepta: un valor
alterado solo produce un UPDATE de 0 filas, y quien puede manipularlo ya tiene el
permiso `administrar vacantes`.

## Parte 1 — `VacanteDesasignarForm`

Agrega un `'#type' => 'hidden'` con `#default_value` igual al
`funcionario_id` de la vacante en el momento del GET, de modo que en el POST
conserve el ocupante que el operador realmente vio.

**No uses `'#type' => 'value'`**: se recalcula en cada construcción y reintroduce
exactamente el problema que se está cerrando.

En `submitForm()`, pásalo como `$funcionario_esperado` a `desasignar()` y aplica
el manejo del paso 4 de la tarea 02: ante un `0`, relee la vacante y distingue

- `funcionario_id` NULL → benigno (`addWarning`, no `addError`), flujo continúa;
- ocupada por otro → conflicto real (`addError` nombrando ocupante y cédula).

Si el valor oculto llega vacío o no numérico (formulario manipulado o petición
malformada), trátalo como conflicto y rechaza. No caigas al comportamiento
histórico de reversa sin comprobación.

## Parte 2 — `VacanteReversaMasivaForm`

Aplica tu propuesta: en el multipaso con `setRebuild(TRUE)`, guarda en el
`conjunto` pares `vacante_id => funcionario_id`, capturando el ocupante en el
momento en que el operador hace la selección, en lugar de solo los `vacante_id`.

En `confirmarReversa()`, pasa el ocupante esperado a `desasignar()`. Un fallo
**no aborta el lote**: cuenta aparte las benignas (ya reversadas) y los
conflictos reales, e informa ambos al final con el patrón ya usado en
`VacanteEliminacionMasivaForm` (`addWarning` para las benignas, `addError` para
los conflictos).

Verifica que el `conjunto` no se serialice a la salida HTML en ninguna parte.

## Parte 3 — Eliminar el riesgo de huérfanos en el borrado de funcionarios

Esta parte corrige el tercer punto de tu propio informe de la tarea 02, que es
más grave de lo que quedó reportado.

### El problema

En `FuncionarioDeleteForm` y `FuncionarioEliminacionMasivaForm`, si la reversa de
la vacante V devuelve `0` benigno porque el funcionario fue movido a otra vacante
W, el borrado del funcionario continúa y **W queda referenciando un
`funcionario_id` que ya no existe**.

Las `foreign keys` declaradas en `hook_schema()` son documentación: Drupal **no**
crea restricciones reales en MySQL. Nada impide el huérfano, y esa vacante
aparecería en el tablero asignada a un funcionario inexistente.

### La solución

Agrega a `VacanteRepository`:

```php
public function desasignarPorFuncionario(int $funcionario_id): int
```

Ejecuta el UPDATE poniendo `funcionario_id` y `denominacion` en NULL con
`->condition('funcionario_id', $funcionario_id)` — **sin** condicionar por
`vacante_id`. Libera de forma atómica cualquier vacante que ocupe ese funcionario
en ese instante, sea cual sea. La llave única sobre `funcionario_id` garantiza
que es a lo sumo una.

Cambia ambos formularios de borrado de funcionario para usar este método en lugar
de `desasignar($vacante_id, $funcionario_esperado)`. Consecuencias que debes
reflejar en el código y los mensajes:

- **Desaparece la distinción benigno/conflicto** en estos dos formularios. Un `0`
  ahora significa, sin ambigüedad, «este funcionario no ocupaba ninguna vacante»,
  que es un caso normal y no requiere aviso alguno.
- Sigue leyendo la vacante con `buscarPorFuncionario()` **antes** de liberar, solo
  para poder nombrarla en el mensaje de éxito. Si esa lectura devuelve NULL y el
  UPDATE devuelve 1, la vacante se liberó igual: no es un error.
- Elimina el contador `$ya_reversadas` y su `addWarning`, y la lógica de conflicto
  con `\RuntimeException` que introdujiste para estos dos casos: ya no aplican.
  **Conserva las transacciones existentes** y el resto del `catch (\Throwable)`.

Queda una ventana residual entre el UPDATE y el DELETE del funcionario. Es
despreciable y **no la persigas**: no agregues bloqueos ni `SELECT ... FOR
UPDATE`.

## Restricciones

- Diff mínimo. No reformatees, no reordenes métodos, no renombres nada.
- No toques el esquema. Esta tarea no lleva `hook_update_N`.
- No modifiques `audiencias.routing.yml`, `audiencias.permissions.yml` ni
  `RepositoryBase.php`.
- Conserva `desasignar()` con su firma actual: la siguen usando otros flujos.
- No optimices el N+1 de `AsignacionController` ni agregues paginación: fuera de
  alcance, el volumen real (29 y 7 funcionarios) no lo justifica.
- No salgas del directorio del módulo. No toques `bellodev` ni
  `create_public_official`.
- No agregues dependencias a `composer.json`.
- Sin PHPUnit ni linters en el proyecto: no inventes comandos de test. Verifica
  por lectura, y si compruebas SQL, hazlo sobre tabla temporal — **nunca contra
  las tablas reales**.
- Comentarios, docblocks y mensajes de UI en español, con el estilo del módulo.

## Entregable

1. Archivos modificados, una línea por cambio.
2. El SQL generado por `desasignarPorFuncionario()`.
3. Cómo quedó el manejo del `0` en cada uno de los cuatro formularios tocados.
4. Confirmación de que ningún flujo de borrado de funcionario puede ya dejar una
   vacante huérfana, o el flujo concreto que siga pudiendo.
5. Flujos que sigan expuestos a esta clase de carrera en el resto del módulo.

No hagas commit. Déjame revisar el diff.
