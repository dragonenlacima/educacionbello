# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Qué es este proyecto

Sitio Drupal 11 de la Secretaría de Educación de Bello (educacionbello.gov.co), montado sobre
`drupal/recommended-project` con docroot en `web/`. Entorno: PHP 8.4, MySQL, Drush 13, Node 24.
Tema por defecto `bellodev`; tema de administración `claro`. Único módulo contrib: `role_delegation`.

## Repositorios git (leer antes de cualquier commit)

**La raíz del proyecto NO es un repositorio git.** Hay tres repos independientes, cada uno con su
propio remoto en GitHub (`dragonenlacima/*`):

- `web/modules/custom/audiencias`
- `web/modules/custom/create_public_official`
- `web/themes/custom/bellodev`

Cualquier operación git debe ejecutarse dentro de esos directorios (`git -C <dir> …`). `vendor/`,
`web/core/` y `web/sites/default/files/` no están versionados aquí.

## Comandos

Drush se ejecuta desde la raíz del proyecto:

```bash
vendor/bin/drush status          # estado del sitio / conexión a BD
vendor/bin/drush cr              # limpiar caché (obligatorio tras cambios de rutas, menús, twig, libraries)
vendor/bin/drush updb            # aplicar hook_update_N
vendor/bin/drush en <modulo>
```

Tema (`web/themes/custom/bellodev`):

```bash
npm install
npm run dev      # vite build --watch
npm run build    # producción; después siempre `drush cr`
```

`assets/dist/` está en `.gitignore` y **no** está versionado: tras un clon del tema hay que
`npm install && npm run build` o el sitio queda sin CSS ni JS. El tema tiene además un submódulo
(`assets/src/js/modules/detect-resolution`) que requiere `git submodule update --init`.

**No hay suite de pruebas.** `composer.json` no declara `require-dev`; no hay PHPUnit, PHPCS ni
PHPStan instalados. No inventes comandos de test ni de lint: la verificación es manual (`drush cr`
+ recorrer las rutas del módulo afectado).

## Configuración del sitio

- `$settings['config_sync_directory']` apunta a `sites/default/files/config_*/sync`, que está
  **vacío**. La configuración vive únicamente en la base de datos: **no existe flujo
  `drush cex` / `drush cim`**. Los cambios de bloques, vistas, permisos y roles se hacen en la UI
  y no se reflejan en el código.
- `web/sites/default/settings.php` es de solo lectura (modo 444) y contiene las credenciales de BD
  y `trusted_host_patterns` para `educacionbello.gov.co`.

## Arquitectura de los módulos custom

Ambos módulos comparten una decisión de fondo: **no usan entidades de contenido ni la Field API**.
Definen sus propias tablas con `hook_schema()` en el `.install` y las consultan con el query
builder de la API de base de datos.

### `audiencias` — asignación de vacantes administrativas

Documentación canónica (extensa, en español) en `web/modules/custom/audiencias/README.md`.
**Léela antes de modificar este módulo.**
Resumen de lo estructural:

- Cuatro tablas `audiencias_*` (institucion, cargo, funcionario, vacante) con integridad
  referencial aplicada en código, no por FKs de MySQL.
- **Capa de repositorios**: `RepositoryBase` (CRUD genérico + `loadAllPaginado()` con
  `PagerSelectExtender`) y un repositorio concreto por tabla, registrados como servicios en
  `audiencias.services.yml`. Controladores y formularios **nunca** ejecutan SQL.
- Flujo de asignación en **dos pasos**: la vacante se crea con `funcionario_id` NULL; asignar es
  una operación posterior (directa, por sorteo aleatorio, o reversible individual/masivamente).
- Un solo permiso para todo el módulo: `administrar vacantes` (`restrict access: true`).
- No hay scripts de precarga: los datos se registran desde la UI (`/admin/audiencias/...`).
  En la audiencia, los presentes se asignan directamente y a los que faltan se les adjudica
  vacante con el sorteo.

### `create_public_official` — registro de asistencia a eventos

- Tres tablas: `create_public_official_list` (padrón, poblado en `hook_install()` desde
  `data.php`), `_register_attendance` y `_attendance_data`.
- `ValidateUserForm` es un formulario multipaso (busca por cédula → confirma/completa datos →
  registra asistencia). Servicios: `create_public_official.users` (consultas) y
  `service_spreadsheet.export_data` (exportación a XLSX con PhpSpreadsheet, por chunks de 500).
- Rutas públicas `/funcionario/{validar,listar,descargar,ver-excel}`, cada una tras su permiso.
- **Gotcha:** los nombres máquina de los permisos son cadenas en español con mayúsculas y espacios
  (`'Validar Funcionario'`, `'Ver lista de asistentes'`, `'Descargar asistencia'`). Deben coincidir
  literalmente entre `.permissions.yml` y `.routing.yml`.

## Convenciones de código

`audiencias` es el módulo de referencia:

- `declare(strict_types=1)` en todas las clases.
- Inyección de dependencias por constructor vía `create(ContainerInterface)`. **Prohibido**
  `\Drupal::` estático dentro de las clases.
- Query builder con placeholders siempre; nunca concatenar SQL.
- Las validaciones de negocio van en `validateForm()` con mensajes claros, **antes** de que la BD
  lance una excepción.

`create_public_official` es más antiguo y usa `\Drupal::service()` estático en varios puntos
(`Form/DownloadForm.php`, `Service/SpreadsheetData.php`); no lo tomes como modelo para código nuevo.

Todo se escribe en español: comentarios, docblocks, mensajes de UI y mensajes de commit.
Indentación de 2 espacios, LF, UTF-8 (`.editorconfig`).

## Tema `bellodev`

Documentación completa en `web/themes/custom/bellodev/README.md`. Puntos que condicionan el trabajo:

- Vite + Tailwind CSS 4, **sin tema base** (`base theme: false`); `bellodev.libraries.yml` apunta a
  `assets/dist/`, así que ningún cambio en `assets/src/` se ve sin `npm run build`.
- Tailwind 4 descubre clases por las directivas `@source` de `assets/src/css/main.css`
  (`templates/**/*.twig`, `*.theme`, `js/**`). Un template creado fuera de esas rutas **no** genera
  sus utilidades.
- Paletas propias: `cobalt`, `matterhorn`, `havelock`. Swiper y Google Fonts se cargan por CDN como
  librerías externas.
- `bellodev.theme` calcula las variables de layout que usan los templates de página
  (`has_breadcrumb`, `has_messages`, `only_content`, `only_sidebar`, `content_lateral`,
  `no_content`) y construye el árbol completo del menú móvil.
- `libraries-override` desactiva varios CSS de `system/base`: si un componente de core se ve roto,
  revisa esa lista antes de parchear estilos.
