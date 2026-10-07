// Importación de estilos CSS
import "../css/main.css";

// Importación del módulo de detección de resolución
//import { watchBreakpoints } from './modules/detect-resolution/detect-resolution.js';

/*
// Definir acciones personalizadas según punto de ruptura
const actions = createBreakpointActions({
    mobile: () => {
        console.log('📱 Modo móvil');
        document.body.classList.add('mobile-layout');
    },
    lg: () => {
        console.log('🖥️ Modo desktop');
        document.body.classList.remove('mobile-layout');
    }
});

// Usar con watchBreakpoints
const unwatch = watchBreakpoints(actions);
 */

(function (Drupal) {
  "use strict";

  Drupal.behaviors.drupaldev = {
    attach(context, settings) {
      const menuMobile = document.getElementById("menu-mobile");
      if (menuMobile) {
        const menuMobileItemsWithChildren = menuMobile.querySelectorAll(
          ".menu-mobile__item--has-children > .menu-mobile__link",
        );
        menuMobileItemsWithChildren.forEach(function (menuItem) {
          menuItem.addEventListener("click", function (event) {
            event.preventDefault();
            const parentItem = event.target.closest(
              ".menu-mobile__item--has-children",
            );
            const submenu = parentItem.querySelector(".menu-mobile__submenu");
            const link = parentItem.querySelector(".menu-mobile__link");
            if (submenu) {
              const isExpanded = submenu.classList.contains(
                "menu-mobile__submenu--open",
              );

              // Cerrar todos los submenús abiertos y remover clase de links
              const allSubmenus = menuMobile.querySelectorAll(
                ".menu-mobile__submenu--open",
              );
              allSubmenus.forEach(function (openSubmenu) {
                openSubmenu.classList.remove("menu-mobile__submenu--open");
              });
              const allLinks = menuMobile.querySelectorAll(
                ".menu-mobile__link--expanded",
              );
              allLinks.forEach(function (expandedLink) {
                expandedLink.classList.remove("menu-mobile__link--expanded");
              });

              // Si el submenú no estaba abierto, abrirlo
              if (!isExpanded) {
                submenu.classList.add("menu-mobile__submenu--open");
                link.classList.add("menu-mobile__link--expanded");
              }
            }
          });
        });
        // Botón para abrir el menú
        const menuMobileOpenButton =
          document.getElementById("menu-mobile-open");
        if (menuMobileOpenButton) {
          menuMobileOpenButton.addEventListener("click", function () {
            menuMobile.classList.add("menu-mobile--open");
          });
        }

        // Botón para cerrar el menú
        const menuMobileCloseButton =
          document.getElementById("menu-mobile-close");
        if (menuMobileCloseButton) {
          menuMobileCloseButton.addEventListener("click", function () {
            menuMobile.classList.remove("menu-mobile--open");
          });
        }
      }

      const desktopMenuItemsWithChildren =
        document.querySelectorAll(".has-children");
      desktopMenuItemsWithChildren.forEach(function (menuItem) {
        menuItem.addEventListener("click", function (e) {
          e.preventDefault();

          const clickedLink = e.currentTarget;
          const clickedSubmenu = clickedLink.nextElementSibling;
          const isCurrentlyOpen = !clickedSubmenu.classList.contains("hidden");

          // Cerrar todos los submenús y remover clase de enlaces expandidos
          desktopMenuItemsWithChildren.forEach(function (item) {
            const submenu = item.nextElementSibling;
            if (submenu) {
              submenu.classList.add("hidden");
            }
            item.classList.remove("menu-expanded");
          });

          // Si el submenú clickeado no estaba abierto, abrirlo
          if (!isCurrentlyOpen) {
            clickedSubmenu.classList.remove("hidden");
            clickedLink.classList.add("menu-expanded");
          }
        });
      });

      // Funcionalidad para cerrar alertas de mensajes
      const alertCloseButtons = document.querySelectorAll(
        '[data-drupal-messages] button[aria-label="Cerrar mensaje"]',
      );
      alertCloseButtons.forEach(function (button) {
        button.addEventListener("click", function () {
          const alert = button.closest('[role="contentinfo"]');
          if (alert) {
            alert.style.transition = "opacity 0.3s ease";
            alert.style.opacity = "0";
            setTimeout(function () {
              alert.remove();
            }, 300);
          }
        });
      });

    },
  };
})(Drupal);
