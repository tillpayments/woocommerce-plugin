(function($) {
    'use strict';

    $(function() {
        $('#woocommerce-order-items').on('click','#tillpayments_capture_payment', function () {
            if (!confirm('Do you want to capture the authorized payment via TillPayments gateway?')) {
                return;
            }

            // get the order_id from the button tag
            var order_id = $(this).data('order-id');
            var payment_method = $(this).data('payment-method');

            // send the data via ajax to the sever
            $.ajax({
                type: 'POST',
                url: ajaxurl,
                dataType: 'json',
                data: {
                    action: 'tillpayments_capture_payment',
                    order_id: order_id,
                    payment_method: payment_method,
                    security: tp_capture.security,
                },
                success: function (data) {
                    if (data.error === 0) {
                        document.location.reload();
                    } else {
                        // show error message
                        alert(data.msg);
                    }
                },
                error: function (XMLHttpRequest, textStatus, errorThrown) {
                    alert(errorThrown);
                }
            });

        });
    });
})(jQuery);
