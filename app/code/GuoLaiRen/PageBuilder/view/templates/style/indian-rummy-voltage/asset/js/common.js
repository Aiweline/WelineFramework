(function ($) {
  'use strict';

  function safeGtag(eventName) {
    if (typeof window.gtag === 'function') {
      window.gtag('event', eventName);
    }
  }

  function resolveDownloadUrl() {
    var bodyUrl = document.body ? document.body.getAttribute('data-download-url') : '';
    if (bodyUrl) {
      return bodyUrl;
    }

    var firstDownload = document.querySelector('.download-btn[href]');
    if (firstDownload) {
      return firstDownload.getAttribute('href') || '#download';
    }

    return '#download';
  }

  function bindDownloadButtons() {
    var fallbackUrl = resolveDownloadUrl();
    var buttons = document.querySelectorAll('.download-btn');

    buttons.forEach(function (button) {
      button.addEventListener('click', function (event) {
        safeGtag('download_event');

        var href = button.getAttribute('href') || fallbackUrl;
        if (!href || href === '#') {
          return;
        }

        // Keep in-page anchors native; only force redirect for external/path links.
        if (href.charAt(0) === '#') {
          return;
        }

        event.preventDefault();
        window.setTimeout(function () {
          window.location.href = href;
        }, 120);
      });
    });
  }

  function ensureBackToTop() {
    if (!document.getElementById('backToTop')) {
      $('<a id="backToTop" class="back-top" aria-label="Back to top"></a>').appendTo('body');
    }

    $(document).on('click', '#backToTop', function (event) {
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  function updateStickyState() {
    var sticky = $('#containerSticky');
    if (!sticky.length) {
      return;
    }

    var stickyTop = sticky.height() + 10;
    if ($(window).scrollTop() > stickyTop) {
      sticky.addClass('css-sticky');
    } else {
      sticky.removeClass('css-sticky');
    }
  }

  function bindScrollUI() {
    $(window).on('scroll', function () {
      var scrollY = $(window).scrollTop();
      var screenW = $(window).width();
      var screenH = window.innerHeight * 0.65;

      if (scrollY > 600) {
        $('#backToTop').show();
      } else {
        $('#backToTop').hide();
      }

      if (scrollY > screenH && screenW < 900) {
        $('#levitateBtn').show();
      } else {
        $('#levitateBtn').hide();
      }

      updateStickyState();
    });

    updateStickyState();
  }

  function bindShareButtons() {
    var shareButtons = document.querySelectorAll('.share-button');

    shareButtons.forEach(function (button) {
      button.addEventListener('click', function (event) {
        event.preventDefault();

        var gtagData = button.getAttribute('data-gtag') || '';
        if (gtagData) {
          safeGtag(gtagData);
        }

        var href = button.getAttribute('href') || '';
        var target = button.getAttribute('target') || '_blank';
        if (href) {
          window.open(href, target);
        }
      });
    });
  }

  $(function () {
    bindDownloadButtons();
    ensureBackToTop();
    bindScrollUI();
    bindShareButtons();
  });
})(window.jQuery);
