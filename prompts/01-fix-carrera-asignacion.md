# Tarea: cerrar la condición de carrera en la asignación de vacantes

## Contexto

Módulo `audiencias` (Drupal 11). Gestiona la asignación de vacantes
administrativas a funcionarios mediante audiencia pública y sorteo.

### Directorio de trabajo y rutas

Esta sesión se ejecuta desde la raíz del proyecto. **Antes de cualquier otra
cosa**, resuelve la ruta base del módulo y úsala como prefijo de todas las rutas
que aparecen más abajo:

```bash
pwd
find . -maxdepth 5 -type d -name audiencias -path "*modules/custom*" -not -path "*/node_modules/*"
```

En este documento, toda ruta escrita como `src/...` o `audiencias.*.yml` es
**relativa a la raíz del módulo**, no a tu directorio actual. Prefíjala con la
ruta que acabas de resolver (típicamente `web/modules/custom/audiencias/`).

### Advertencia sobre git

La raíz del proyecto **no es un repositorio git**. El módulo `audiencias` es un
repositorio independiente con su propio remoto. Cualquier operación de git
(`status`, `diff`) debe ejecutarse **dentro del directorio del módulo**; desde la
raíz no mostrará estos cambios. De todos modos, en esta tarea no debes hacer
commit.

**El módulo está en producción y YA TIENE VACANTES ASIGNADAS.** Cualquier cambio
debe ser mínimo, acotado y no destructivo. No refactorices nada que no esté
explícitamente pedido aquí.

Arquitectura vigente (respétala):

- Tablas crudas vía `hook_schema()`. No hay entidades de contenido ni Field API.
- Capa de repositorios: controladores y formularios **nunca** ejecutan SQL.
- Inyección de dependencias por constructor. Prohibido `\Drupal::` estático.
- Query builder con placeholders. Prohibido concatenar SQL.

## El problema

`VacanteRepository::asignar()` ejecuta el UPDATE condicionando solo por la clave
primaria de la vacante:

```php
->condition($this->getClavePrimaria(), $vacante_id)
```

La llave única sobre `audiencias_vacante.funcionario_id` impide que un
funcionario ocupe dos vacantes, pero **no** impide que dos funcionarios ocupen la
misma vacante: el segundo UPDATE sobrescribe el `funcionario_id` del primero.

Consecuencia: el primer funcionario queda sin asignación de forma silenciosa. No
se lanza excepción, no se muestra error, no queda rastro en ningún log.

La validación (funcionario existe, cargo coincide, funcionario libre) ocurre en
el formulario, y el UPDATE ocurre después. Entre ambos momentos hay una ventana
de carrera que se materializa con dos operadores simultáneos, con un doble clic
sobre el botón de asignar, o con el sorteo ejecutándose mientras alguien asigna
manualmente.

## Paso 1 — Verificación previa OBLIGATORIA

Antes de modificar cualquier archivo, lee estos tres formularios (rutas
relativas a la raíz del módulo resuelta arriba):

- `src/Form/AsignarFilaForm.php`
- `src/Form/VacanteAsignarForm.php`
- `src/Form/SorteoVacantesForm.php`

Y responde explícitamente a esta pregunta:

> ¿Existe algún flujo en el que se asigne un funcionario a una vacante que **ya
> tiene** `funcionario_id` no nulo, sin pasar antes por `desasignar()`?

- **Si la respuesta es NO** (lo esperado): continúa con el paso 2.
- **Si la respuesta es SÍ**: **DETENTE**, no modifiques nada, y explica cuál es
  ese flujo y en qué archivo y línea ocurre. El arreglo propuesto rompería ese
  caso y hay que rediseñarlo antes.

## Paso 2 — Corregir el repositorio

En `src/Repository/VacanteRepository.php`, método `asignar()`: agrega la
condición de que la vacante siga libre en el momento exacto del UPDATE. Esto
hace la operación atómica sin necesidad de transacción ni de `SELECT ... FOR
UPDATE`.

```php
->condition($this->getClavePrimaria(), $vacante_id)
// La vacante debe seguir libre en el momento exacto del UPDATE: cierra la
// ventana de carrera entre la validación del formulario y el guardado.
->isNull('funcionario_id')
```

Actualiza el docblock del método para documentar el nuevo contrato: el valor de
retorno `0` ya no significa solo "sin cambios", sino **"la vacante fue tomada por
otro proceso"**, y quien llama debe tratarlo como fallo.

## Paso 3 — Corregir a quienes lo llaman

Busca todas las llamadas a `asignar()` en el módulo. En cada una:

1. **Captura el valor de retorno.** Si es `0`, la asignación NO ocurrió. Muestra
   un mensaje de error claro en español mediante el servicio `messenger`
   inyectado (nunca `\Drupal::messenger()`), del estilo de: «La vacante ya fue
   asignada a otro funcionario. Actualiza la pantalla y vuelve a intentarlo.»
   No continúes el flujo como si hubiera tenido éxito.

2. **Envuelve la llamada en `try/catch`** de
   `\Drupal\Core\Database\IntegrityConstraintViolationException`. Hoy, si el
   funcionario fue asignado a otra vacante en paralelo, la llave única lanza esa
   excepción y el usuario ve una pantalla blanca. Debe convertirse en un mensaje
   de error legible.

3. **Caso especial del sorteo** (`SorteoVacantesForm`): reparte muchas
   asignaciones en un solo envío. Si alguna devuelve `0` o lanza la excepción, NO
   abortes todo el lote en silencio. Cuenta cuántas se lograron y cuántas
   fallaron, e informa ambos números al final. Si el sorteo ya usa una
   transacción, respétala; si no la usa, **no la agregues en esta tarea** —
   déjalo anotado como observación al final.

## Restricciones

- Diff mínimo. No reformatees archivos, no reordenes métodos, no renombres nada.
- No toques el esquema. Esta tarea no lleva `hook_update_N`.
- No modifiques `audiencias.routing.yml` ni `audiencias.permissions.yml` (ambos
  en la raíz del módulo).
- No salgas del directorio del módulo. Ningún archivo fuera de
  `web/modules/custom/audiencias/` debe cambiar en esta tarea — en particular, no
  toques el tema `bellodev` ni el módulo `create_public_official`.
- No agregues dependencias a `composer.json`.
- El proyecto no tiene PHPUnit ni linters instalados: no inventes ni ejecutes
  comandos de test. Verifica por lectura del código.
- Todos los comentarios, docblocks y mensajes de UI van en español, siguiendo el
  estilo existente del módulo.

## Entregable

Al terminar, reporta:

1. La respuesta a la pregunta del paso 1.
2. La lista de archivos modificados con una línea explicando el cambio en cada uno.
3. Las llamadas a `asignar()` que encontraste y cómo quedó el manejo de error en
   cada una.
4. Cualquier flujo que hayas detectado y que siga quedando expuesto a la carrera.

No hagas commit. Déjame revisar el diff.
