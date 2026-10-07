/**
 * @file
 * Swiper JS carousel initialization.
 */

(function (Drupal) {
  "use strict";

  Drupal.behaviors.swiperCarousel = {
    attach: function (context, settings) {
      const swiper = new Swiper(".swiper", {
        // Optional parameters
        direction: "horizontal",
        loop: true,
        slidesPerView: 1,
        spaceBetween: 0,

        // If we need pagination
        pagination: {
          el: ".swiper-pagination",
          type: "bullets",
          clickable: true,
        },

        // Navigation arrows
        navigation: {
          nextEl: ".swiper-button-next",
          prevEl: ".swiper-button-prev",
        },

        autoplay: {
          delay: 8000,
        },
      });

      const buttonPlayStop = document.querySelector("#swiper-stop-play");
      if (!buttonPlayStop) {
        return;
      }
      buttonPlayStop.addEventListener("click", () => {
        if (swiper.autoplay.running) {
          swiper.autoplay.stop();
          buttonPlayStop.textContent = "▶ Reproducir";
        } else {
          swiper.autoplay.start();
          buttonPlayStop.textContent = "|| Detener";
        }
      });
    },
  };
})(Drupal);
