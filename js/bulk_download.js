(function ($, Drupal) {

  Drupal.behaviors.customBulkOperationDownload = {
    attach: function (context, settings) {
      const downloadLink = $('a.download-link');
      if (downloadLink.length) {
        $(document).ready(function () {
          document.querySelector('a.download-link').click();
        });
      }
    }
  };

})(jQuery, Drupal);
