# Tarea 04: el sorteo debe aplicar exactamente lo que muestra

## Contexto

Módulo `audiencias` (Drupal 11), en `web/modules/custom/audiencias/`. Sesión
ejecutada desde la raíz del proyecto (la que contiene `composer.json` y `web/`).
Las rutas escritas como `src/...` son relativas a la raíz del módulo.

La raíz **no es un repositorio git**: `audiencias` es un repo independiente. Git
se ejecuta dentro del módulo. No hagas commit.

**El módulo está en producción.** Las tareas 01, 02 y 03 (condiciones de carrera
en asignación, reversa y borrado) ya están aplicadas y commiteadas. Respeta ese
trabajo: no lo deshagas.

### Arquitectura vigente (respétala)

- Tablas crudas vía `hook_schema()`. Sin entidades de contenido ni Field API.
- Capa de repositorios: controladores y formularios **nunca** ejecutan SQL.
- Inyección de dependencias por constructor. Prohibido `\Drupal::` estático.
- Query builder con placeholders. Prohibido concatenar SQL.

## El defecto (confirmado en producción, no es hipótesis)

`SorteoVacantesForm` genera el emparejamiento en `buildForm()` (línea ~216) y lo
guarda en `$form_state`. `confirmarSorteo()` aplica lo que haya en `$form_state`.

**El estado del formulario no se persiste.** Verificado: `SELECT COUNT(*) FROM
key_value_expire WHERE collection = 'form'` devuelve 0 con la pantalla del sorteo
abierta. Al confirmar, Drupal reconstruye el formulario, `$form_state->get('sorteo')`
devuelve NULL, `buildForm()` ejecuta `generarSorteo()` **otra vez**, y
`confirmarSorteo()` aplica ese emparejamiento nuevo.

Reproducido de forma controlada: la vista previa mostró «I.E. SAN GABRIEL
ARCÁNGEL — VACANTE 1» y quedó aplicada «I.E. CARLOS PEREZ MEJIA — VACANTE 2».

Esto ya causó un daño real en una audiencia pública: una persona quedó asignada a
una institución distinta de la que arrojó el sorteo.

Causa probable de que el estado dejara de persistirse: el único elemento con
`#ajax` del formulario es el botón «Realizar sorteo», que quedó con
`'#access' => FALSE`. Un elemento sin acceso no se renderiza ni procesa su
`#ajax`, y con ello pudo desaparecer la razón por la que Drupal cacheaba el
formulario.

## La solución: sortear y aplicar en una sola operación

Se elimina la ventana entre lo mostrado y lo aplicado, en lugar de intentar
cerrarla. **No** persistas el `form_state`: rediseña el flujo.

### Comportamiento nuevo

1. Al entrar a `/admin/audiencias/asignacion/{cargo_id}/sorteo`, la pantalla
   **NO sortea nada**. Muestra un resumen del estado: cuántos funcionarios están
   pendientes y cuántas vacantes hay disponibles para ese cargo, y un botón
   «Realizar sorteo» (visible y habilitado solo si hay al menos un pendiente y al
   menos una vacante disponible).
2. Al pulsar el botón: se genera el emparejamiento **y se aplica en la misma
   petición, dentro de una transacción**.
3. Terminado, se muestra el resultado ya aplicado, con el mismo formato de tabla
   de la vista previa actual (funcionario, cédula, posición, institución,
   vacante), y un mensaje que deje claro que ya quedó aplicado.

Desaparece el paso «Confirmar y aplicar el sorteo»: ya no hay nada que
confirmar. Elimina también el botón «Repetir sorteo» y su manejador.

### Reglas del sorteo (se conservan; no las cambies)

- Solo entran funcionarios **pendientes** del cargo (sin vacante asignada).
- Solo entran vacantes **libres** del mismo cargo (`funcionario_id` NULL). Las
  vacantes asignadas NUNCA entran al sorteo. Esto es crítico.
- Las vacantes ya vienen ligadas a su institución y a su cargo: el sorteo decide
  **quién ocupa cada vacante**, jamás mueve una vacante de institución.
- Se barajan **ambas** listas y se empareja índice a índice hasta
  `min(pendientes, disponibles)`. Cuando sobran vacantes, cuáles se adjudican
  también sale al azar (no las primeras por id).
- Los sobrantes de cualquiera de los dos lados se informan al usuario.
- `denominacion` se fija en `'sorteo'`.
- El sorteo se puede ejecutar **en cualquier momento** en que haya pendientes,
  incluidos los que quedaron pendientes por una reversa. No agregues bloqueos,
  límites de ejecuciones ni restricciones temporales.

### Aleatoriedad

Sustituye `shuffle()` por un barajado Fisher-Yates con `random_int()`. `shuffle()`
usa Mersenne Twister, un generador no criptográfico; en un acto administrativo
público corresponde usar la fuente aleatoria del sistema.

Implementa un método privado `barajar(array $items): array` en el formulario y
úsalo para ambas listas.

### Aplicación

- Todo el lote va en **una transacción** (`$this->database->startTransaction()`),
  siguiendo el patrón que ya usan `FuncionarioEliminacionMasivaForm` y
  `VacanteEliminacionMasivaForm`.
- Conserva el manejo de errores que introdujo la tarea 01 en `confirmarSorteo()`:
  captura el retorno de `asignar()`, trata el `0` como fallo, y envuelve en
  `try/catch` de `IntegrityConstraintViolationException`. Un fallo individual se
  cuenta aparte e informa al final.
- Como ahora no hay ventana entre generar y aplicar, la revalidación previa de
  cada par (que el funcionario siga pendiente y la vacante siga libre) es
  redundante pero **inofensiva**: consérvala como defensa en profundidad.

### El overlay de 5 segundos

Hoy el resultado ya viaja en el HTML oculto con `is-hidden` y el overlay solo
simula un cálculo que ya terminó. Con el flujo nuevo, el overlay debe cubrir la
petición real del sorteo. Ajusta `js/sorteo.js` lo mínimo necesario para que se
muestre al pulsar «Realizar sorteo» y se oculte cuando llegue el resultado.

Si mantener el overlay obliga a reintroducir AJAX —y con ello la complejidad de
estado que causó este bug—, **prefiere un envío normal sin AJAX y dilo en el
informe**. La corrección del defecto tiene prioridad absoluta sobre la animación.

## Restricciones

- No toques el esquema. Esta tarea **no** lleva `hook_update_N`. (Si en el futuro
  se agrega uno, debe numerarse `11001`, no `9002`.)
- No modifiques `audiencias.routing.yml`, `audiencias.permissions.yml` ni
  `RepositoryBase.php`.
- No deshagas los cambios de las tareas 01, 02 y 03.
- No optimices el N+1 de `obtenerPendientes()` ni de `AsignacionController`: el
  volumen real (29 y 7 funcionarios) no lo justifica.
- No corrijas el menú invertido de `audiencias.links.menu.yml`: es otra tarea.
- No salgas del directorio del módulo. No toques `bellodev` ni
  `create_public_official`.
- Sin PHPUnit ni linters en el proyecto: no inventes comandos de test. Verifica
  por lectura y, si compruebas SQL, hazlo sobre tabla temporal — **nunca contra
  las tablas reales**.
- Comentarios, docblocks y mensajes de UI en español, con el estilo del módulo.

## Entregable

1. Archivos modificados, una línea por cambio.
2. Cómo quedó el flujo: qué ocurre al entrar y qué al pulsar el botón.
3. Confirmación explícita de que ya no existe ninguna ruta de código en la que se
   genere un emparejamiento distinto del que se aplica.
4. Si mantuviste AJAX o pasaste a envío normal, y por qué.
5. Qué pasa si dos operadores pulsan «Realizar sorteo» simultáneamente.

No hagas commit. Déjame revisar el diff.
