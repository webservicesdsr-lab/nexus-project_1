(function ($) {
  'use strict';

  $(document).ready(function () {
    function initLogoUploader() {
      var frame;
      $('.knx-upload-logo').on('click', function (e) {
        e.preventDefault();

        if (frame) {
          frame.open();
          return;
        }

        frame = wp.media({
          title: 'Select or Upload Logo',
          library: { type: 'image' },
          button: { text: 'Use this logo' },
          multiple: false
        });

        frame.on('select', function () {
          var selection = frame.state().get('selection');
          if (!selection || !selection.first) return;
          var attachment = selection.first().toJSON();
          if (!attachment || !attachment.url) return;

          $('#knx_site_logo').val(attachment.url);

          // preview
          var prev = $('.knx-settings-logo-preview');
          if (prev.length) {
            prev.html('<img src="' + attachment.url + '" alt="Logo preview">');
          } else {
            $('<div class="knx-settings-logo-preview"><img src="' + attachment.url + '" alt="Logo preview"></div>').insertBefore('#knx_site_logo');
          }
        });

        frame.open();
      });
    }

    // Initialize
    if (typeof wp !== 'undefined' && wp.media) {
      initLogoUploader();
    } else {
      // If wp.media isn't available yet, try again shortly.
      setTimeout(function () {
        if (typeof wp !== 'undefined' && wp.media) initLogoUploader();
      }, 250);
    }
  });
})(jQuery);
