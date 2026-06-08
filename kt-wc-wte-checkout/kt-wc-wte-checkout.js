(function($){

  function status(msg) {
    $('#kt_passport_status').text(msg);
  }

  function setPlaceOrderDisabled(disabled){
    var $btn = $('#place_order');
    if ($btn.length) $btn.prop('disabled', !!disabled);
  }

  function cleanupPaymentChoice(){
    var $blocks = $('.kt-payment-choice');
    if (!$blocks.length) return;

    var $withDeposit = $blocks.filter(function(){
      return $(this).find('input[name="kt_payment_choice"][value="deposit"]').length > 0;
    });

    if ($withDeposit.length) {
      $blocks.not($withDeposit).remove();
      $blocks = $('.kt-payment-choice');
    }

    if ($blocks.length > 1) $blocks.not(':last').remove();
  }

  // Confirm script is running + AJAX is reachable
  $(function(){
    $.post(KTWTE.ajax_url, { action: 'kt_ping' })
      .done(function(resp){
        if (resp && resp.success) {
          // Optional: show something briefly
          // status('Ready for upload.');
          console.log('KTWTE ping OK:', resp);
        } else {
          console.log('KTWTE ping failed:', resp);
        }
      })
      .fail(function(xhr){
        console.log('KTWTE ping AJAX FAIL:', xhr);
      });

    cleanupPaymentChoice();
  });

  $(document.body).on('updated_checkout', function(){
    cleanupPaymentChoice();
  });

  // Deposit/full triggers totals refresh
  $(document.body).on('change', 'input[name="kt_payment_choice"]', function(){
    $(document.body).trigger('update_checkout');
  });

  // Upload on file select
  $(document.body).on('change', '#kt_passport_id', function(){
    var file = this.files && this.files[0] ? this.files[0] : null;
    if (!file) return;

    status('Uploading...');
    $('#kt_passport_attachment_id').val('');
    setPlaceOrderDisabled(true);

    var fd = new FormData();
    fd.append('action', 'kt_upload_passport');
    fd.append('nonce', (KTWTE && KTWTE.nonce) ? KTWTE.nonce : ($('#kt_passport_nonce_dom').val() || ''));
    fd.append('file', file);

    $.ajax({
      url: KTWTE.ajax_url,
      type: 'POST',
      data: fd,
      processData: false,
      contentType: false
    }).done(function(resp){
      console.log('KTWTE upload resp:', resp);

      if (resp && resp.success && resp.data && resp.data.attachment_id) {
        $('#kt_passport_attachment_id').val(resp.data.attachment_id);
        status(resp.data.message || 'Uploaded ✓');
        setPlaceOrderDisabled(false);
        $(document.body).trigger('update_checkout');
      } else {
        status((resp && resp.data && resp.data.message) ? resp.data.message : 'Upload failed.');
        setPlaceOrderDisabled(false);
      }
    }).fail(function(xhr){
      console.log('KTWTE upload AJAX FAIL:', xhr);
      status('Upload failed (AJAX). Check console.');
      setPlaceOrderDisabled(false);
    });
  });

  // Guard: don’t place order if missing attachment id
  $(document.body).on('click', '#place_order', function(e){
    if (!$('#kt_passport_attachment_id').val()) {
      e.preventDefault();
      e.stopPropagation();
      status('Please upload your Passport/ID document before placing the order.');
      return false;
    }
  });

})(jQuery);
