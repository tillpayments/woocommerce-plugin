(function ($) {
  var googlePayFormId = "till_payments_googlepay";
  var $googlePayForm = $("#" + googlePayFormId);
  var $paymentForm = $googlePayForm.closest("form");
  var $tillPaymentsErrors = $("#till_payments_googlepay_errors");
  var paymentFormSubmitButtonId = "place_order";
  var googlePayButtonId = "googlepay-button";
  var injected = false;
  // init GooglePay on document load
  $(document.body).on("updated_checkout", function () {
    $googlePayForm = $("#" + googlePayFormId);
    $paymentForm = $googlePayForm.closest("form");
    $tillPaymentsErrors = $("#till_payments_googlepay_errors");
    injectGooglePay();
    $(document).on(
      "change",
      "input[type=radio][name=payment_method]",
      function () {
        toggleGooglePayButton(this.value);
      }
    );
  });
  var injectGooglePay = function () {
    if (!injected) {
      $googlePayForm.show();
      $.getScript("https://pay.google.com/gp/p/js/pay.js")
        .done(function () {
          injected = true;
          toggleGooglePayButton(
            $("input[type=radio][name=payment_method]:checked").val()
          );
        })
        .fail(function () {
          console.error("GOOGLE PAY error loading pay.js!");
        });
    } else {
      toggleGooglePayButton(
        $("input[type=radio][name=payment_method]:checked").val()
      );
    }
  };
  var initGooglePay = function () {
    if ($("#" + googlePayButtonId).length === 0) {
      var buttonContainer = $('<div id="' + googlePayButtonId + '" />');
      buttonContainer.appendTo(".form-row.place-order");
    }
    window.tillGooglePayLoader.setConfiguration({
      environment: window.googlePay.environment,
      googlePayButtonContainerId: googlePayButtonId,
      buttonType: window.googlePay.button_type,
      buttonColor: window.googlePay.button_color,
      allowedCardNetworks: window.googlePay.allowed_card_networks,
      allowedCardAuthMethods: window.googlePay.allowed_card_auth_methods,
      tokenizationSpecification: {
        type: "PAYMENT_GATEWAY",
        parameters: {
          gateway: "ixopay",
          gatewayMerchantId: window.googlePay.gateway_merchant_id,
        },
      },
      merchantId: window.googlePay.merchant_id,
      merchantName: window.googlePay.merchant_name,
      cartInfo: {
        currency: window.googlePay.currency_code,
        country: window.googlePay.country,
        grandTotal: window.googlePay.grand_total,
      },
      processPaymentCallback: function (paymentData, paymentToken) {
        console.debug("GOOGLE PAY processPaymentCallback()");
        console.debug(paymentToken);
        var $paymentFormTokenInput = $("#till_payments_googlepay_token");
        $paymentFormTokenInput.val("googlepay:" + paymentToken);
        $paymentForm.submit();
      },
      errorCallback: function (err) {
        if (err.hasOwnProperty("statusCode") && err.statusCode === "CANCELED") {
          $tillPaymentsErrors.html("Payment was cancelled by the user.");
          console.error("Payment was cancelled by the user.");
        } else {
          $tillPaymentsErrors.html(
            "An error occurred while processing the payment. Please check whether your entered data is valid."
          );
          console.error(
            "An error occurred while processing the payment. Please check whether your entered data is valid."
          );
        }
      },
    });
    window.tillGooglePayLoader.onGooglePayLoaded().then(function () {
      $("#" + googlePayButtonId).show();
    });
  };
  var toggleGooglePayButton = function (currentPaymentMethodName) {
    if (
      currentPaymentMethodName.startsWith("till_payments_") &&
      currentPaymentMethodName !== "till_payments_creditcard"
    ) {
      $("#" + paymentFormSubmitButtonId).hide();
    } else {
      $("#" + paymentFormSubmitButtonId).show();
    }
    if (currentPaymentMethodName === "till_payments_googlepay") {
      initGooglePay();
    } else {
      $("#" + googlePayButtonId).hide();
    }
  };
})(jQuery);
