# Bellodev - Tema de Drupal

Un tema moderno para Drupal 10/11 construido con Vite y Tailwind CSS 4.x, optimizado para desarrollo rápido y rendimiento.

## 📋 Descripción

Bellodev es un tema base personalizado para Drupal que integra las últimas tecnologías de frontend, proporcionando un flujo de trabajo de desarrollo moderno con recarga en caliente (HMR), compilación optimizada y un sistema de diseño basado en utilidades con Tailwind CSS 4.

## ✨ Características

- 🚀 **Vite**: Build tool moderno con HMR para desarrollo rápido
- 🎨 **Tailwind CSS 4.x**: Framework CSS basado en utilidades
- 📱 **Diseño Responsivo**: Sistema completo de breakpoints
- 🔧 **Sin tema base**: Tema independiente para máximo control
- 🎯 **Optimizado**: Compilación y minificación automática de assets
- 🌐 **Google Fonts**: Integración de Nunito Sans con preconnect optimizado
- 📦 **Swiper JS**: Carrusel/slider integrado y listo para usar
- 🗂️ **Estructura modular**: Organización clara de templates y assets

## 📦 Requisitos

- Drupal 10.x o 11.x
- Node.js 18+ y npm
- Composer (para dependencias de Drupal)

## 🚀 Instalación

1. **Clonar o copiar el tema** en el directorio de temas de Drupal:
   ```bash
   cd /path/to/drupal/web/themes/
   ```

2. **Instalar dependencias de Node.js**:
   ```bash
   cd bellodev
   npm install
   ```

3. **Habilitar el tema en Drupal**:
   ```bash
   drush theme:enable bellodev
   drush config-set system.theme default bellodev -y
   ```
   
   O desde la interfaz de administración: `/admin/appearance`

4. **Limpiar caché**:
   ```bash
   drush cr
   ```

## 🛠️ Desarrollo

### Comandos disponibles

#### Modo desarrollo (watch)
Inicia Vite en modo watch para desarrollo continuo con recarga automática:
```bash
npm run dev
```

#### Build de producción
Compila y optimiza todos los assets para producción:
```bash
npm run build
```

### Estructura de Assets

```
assets/
├── src/                    # Archivos fuente
│   ├── css/
│   │   └── main.css       # CSS principal con Tailwind
│   ├── js/
│   │   ├── main.js        # JavaScript principal
│   │   └── modules/       # Módulos JS reutilizables
│   ├── fonts/             # Fuentes personalizadas
│   └── images/            # Imágenes
├── dist/                   # Archivos compilados (generados automáticamente)
│   ├── css/
│   ├── js/
│   ├── fonts/
│   └── images/
└── libraries/              # Librerías externas (Swiper, etc.)
    ├── css/
    └── js/
```

### Workflow de desarrollo

1. **Inicia el modo watch**:
   ```bash
   npm run dev
   ```

2. **Edita archivos en `assets/src/`**:
   - CSS: `assets/src/css/main.css`
   - JS: `assets/src/js/main.js`
   - Tailwind se aplicará automáticamente en templates `.twig`

3. **Los cambios se compilarán automáticamente** en `assets/dist/`

4. **Limpia la caché de Drupal** cuando sea necesario:
   ```bash
   drush cr
   ```

## 🎨 Tailwind CSS

### Configuración

El tema utiliza Tailwind CSS 4.x con la nueva sintaxis `@source` para escanear archivos:

```css
@source "../../../templates/**/*.twig";
@source "../../../*.theme";
@source "../js/**/*.js";
```

### Colores personalizados

El tema incluye tres paletas de colores personalizadas:

- **Cobalt**: Azul corporativo (`cobalt-50` a `cobalt-950`)
- **Matterhorn**: Escala de grises (`matterhorn-50` a `matterhorn-950`)
- **Havelock**: Azul claro/medio (`havelock-50` a `havelock-950`)

Ejemplo de uso:
```html
<div class="bg-cobalt-500 text-white">
  <h1 class="text-havelock-300">Título</h1>
</div>
```

## 📁 Estructura del Tema

### Archivos de configuración

- **bellodev.info.yml**: Configuración principal del tema
- **bellodev.libraries.yml**: Definición de librerías CSS/JS
- **bellodev.theme**: Hooks y preprocesamiento PHP
- **vite.config.js**: Configuración de Vite
- **package.json**: Dependencias y scripts npm

### Regiones disponibles

- `header`: Cabecera
- `primary_menu`: Menú principal
- `secondary_menu`: Menú secundario
- `page_top`: Parte superior de página
- `page_bottom`: Parte inferior de página
- `featured`: Destacados
- `breadcrumb`: Migas de pan
- `content`: Contenido principal
- `sidebar_first`: Barra lateral izquierda
- `sidebar_second`: Barra lateral derecha
- `footer`: Pie de página
- `messages`: Mensajes del sistema

### Templates

El tema incluye templates Twig personalizados para:

- **Bloques**: Sistema de bloques, menús, búsqueda, branding
- **Formularios**: Inputs, selects, textareas, checkboxes, etc.
- **Páginas**: Páginas estándar, front page, login, mantenimiento
- **Nodos**: Templates de nodos
- **Campos**: Renderizado de campos
- **Navegación**: Menús, breadcrumbs, tareas locales
- **Usuarios**: Perfiles e imágenes de usuario
- **Mensajes**: Status messages personalizados

### Includes

Templates reutilizables en `templates/includes/`:

- `bar-top.html.twig`: Barra superior
- `footer.html.twig`: Pie de página
- `menu_mobile.html.twig`: Menú móvil
- `swiper.html.twig`: Plantilla de carrusel Swiper

## 📚 Librerías incluidas

### Global
Cargada automáticamente en todas las páginas:
- CSS compilado por Vite
- JavaScript principal
- Google Fonts (Nunito Sans)
- Dependencias de Drupal core (drupal, once)

### Google Fonts
```yaml
google-fonts:
  - Nunito Sans (variable weights 200-1000)
```

### Swiper JS
Carrusel/slider para contenido dinámico:
```yaml
swiperjs:
  - Swiper Bundle (CDN)
  - Estilos y scripts personalizados
```

Para usar Swiper en un template:
```twig
{% include '@bellodev/includes/swiper.html.twig' %}
```

O adjuntar la librería:
```php
$variables['#attached']['library'][] = 'bellodev/swiperjs';
```

## 🔧 Funcionalidades del tema (bellodev.theme)

### Preprocess Hooks

#### `bellodev_preprocess_html()`
- Añade preconnect para Google Fonts (optimización de rendimiento)

#### `bellodev_preprocess_page()`
- Detecta bloques activos en regiones
- Proporciona variables condicionales:
  - `has_breadcrumb`: Si existe breadcrumb
  - `has_messages`: Si hay mensajes del sistema
  - `only_content`: Solo región de contenido activa
  - `only_sidebar`: Solo sidebar activa
  - `content_lateral`: Contenido + sidebar activos
  - `no_content`: Sin contenido ni sidebar
- Genera menú móvil con árbol completo del menú principal

### Menú Móvil

El tema incluye un sistema completo de menú móvil con:
- Soporte multinivel
- Marcado de elementos activos
- Detección automática de hijos
- Integración con el menú principal de Drupal

## 🎯 Módulos JavaScript

### Main.js

Funcionalidades incluidas:
- **Menú móvil interactivo**: Acordeón para submenús
- **Toggle de búsqueda**: Apertura/cierre del formulario de búsqueda
- **Menú hamburguesa**: Control del menú móvil

## 🌐 Vite Configuration

### Características de la configuración

- **Root**: `assets/src`
- **Output**: `assets/dist`
- **Entry point**: `assets/src/js/main.js`
- **Plugins**:
  - Tailwind CSS con `@tailwindcss/vite`
  - Copy static assets (fuentes e imágenes)

### Organización de salida

```javascript
entryFileNames: 'js/[name].js'
chunkFileNames: 'js/[name].js'
assetFileNames: 
  - CSS → 'css/[name].css'
  - Imágenes → 'images/[name][ext]'
  - Fuentes → 'fonts/[name][ext]'
```

## 📝 Personalización

### Añadir nuevos colores en Tailwind

Edita `assets/src/css/main.css`:
```css
@theme {
  --color-mi-color-500: #hexcode;
  --color-mi-color-600: #hexcode;
  /* ... */
}
```

### Añadir nuevas librerías

Edita `bellodev.libraries.yml`:
```yaml
mi-libreria:
  css:
    theme:
      assets/libraries/css/mi-libreria.css: {}
  js:
    assets/libraries/js/mi-libreria.js: {}
```

### Crear nuevos templates

1. Crea el archivo en `templates/` siguiendo la convención de Drupal
2. Limpia la caché: `drush cr`
3. Usa clases de Tailwind directamente en el template

### Añadir hooks personalizados

Edita `bellodev.theme` y añade funciones con el prefijo `bellodev_`:
```php
function bellodev_preprocess_node(&$variables) {
  // Tu código aquí
}
```

## 🐛 Debugging

### Los estilos no se aplican
```bash
# Recompilar assets
npm run build

# Limpiar caché de Drupal
drush cr
```

### Vite no detecta cambios
```bash
# Detener npm run dev y reiniciar
Ctrl+C
npm run dev
```

### Errores de permisos en assets/dist
```bash
chmod -R 755 assets/dist
chown -R www-data:www-data assets/dist  # Ajustar según tu servidor
```

## 📊 Rendimiento

### Optimizaciones incluidas

- ✅ Preconnect para Google Fonts
- ✅ Minificación automática en build
- ✅ Tree-shaking de Tailwind CSS
- ✅ Rollup optimization con Vite
- ✅ CSS y JS separados por tipo
- ✅ Carga de librerías externas vía CDN (opcional)

### Recomendaciones

1. **Producción**: Siempre ejecuta `npm run build` antes de desplegar
2. **Caché de Drupal**: Habilita agregación de CSS/JS en `/admin/config/development/performance`
3. **Imágenes**: Optimiza imágenes antes de añadirlas a `assets/src/images/`
4. **CDN**: Considera usar CDN para assets estáticos

## 🤝 Contribuir

Para contribuir al tema:

1. Fork el proyecto
2. Crea una rama para tu feature (`git checkout -b feature/nueva-funcionalidad`)
3. Commit tus cambios (`git commit -m 'Añade nueva funcionalidad'`)
4. Push a la rama (`git push origin feature/nueva-funcionalidad`)
5. Abre un Pull Request

## 📄 Licencia

Este proyecto es de código abierto y está disponible bajo tu licencia preferida.

## 🔗 Enlaces útiles

- [Documentación de Drupal Theming](https://www.drupal.org/docs/theming-drupal)
- [Documentación de Vite](https://vitejs.dev/)
- [Documentación de Tailwind CSS 4](https://tailwindcss.com/docs)
- [Swiper JS](https://swiperjs.com/)
- [Twig Template Engine](https://twig.symfony.com/)

## ✍️ Autor

Desarrollado para proyectos Drupal modernos.

## 📞 Soporte

Para problemas, sugerencias o preguntas, abre un issue en el repositorio del proyecto.

---

**Versión**: 1.0.0  
**Última actualización**: Febrero 2026  
**Compatible con**: Drupal 10.x, 11.x
