(function ($) {
	$(document).ready(function(){
		var $paymentForm = $('#till_payments_seamless').closest('form');
	    var $paymentFormSubmitButton = $("#place_order");
	    var $paymentFormTokenInput = $('#till_payments_token');
	    var $tillPaymentsErrors = $('#till_payments_errors');
	    var integrationKey = window.integrationKey;
	    var initialized = false;
	    var init = function () {
	        if (integrationKey && !initialized) {
	            $paymentFormSubmitButton.prop("disabled", false);
	            tillPaymentsSeamless.init(
	                integrationKey,
	                function () {
	                    $paymentFormSubmitButton.prop("disabled", true);
	                },
	                function () {
	                    $paymentFormSubmitButton.prop("disabled", false);
	                });
	        }
	    };
	    $paymentFormSubmitButton.on('click', function (e) {
	        tillPaymentsSeamless.submit(
	            function (token) {
	                $paymentFormTokenInput.val(token);
	                $paymentForm.submit();
	            },
	            function (errors) {
	                errors.forEach(function (error) {
	                    $tillPaymentsErrors.html(error.message);
	                    console.error(error);
	                });
	            });
	        return false;
	    });
	    var tillPaymentsSeamless = function () {
	        var payment;
	        var validDetails;
	        var validNumber;
	        var validCvv;
	        var _invalidCallback;
	        var _validCallback;
	        var $seamlessForm = $('#till_payments_seamless');
	        var $seamlessCardHolderInput = $('#till_payments_seamless_card_holder', $seamlessForm);
	        var $seamlessEmailInput = $('#till_payments_seamless_email', $seamlessForm);
	        var $seamlessExpiryInput = $('#till_payments_seamless_expiry', $seamlessForm);
	        var $seamlessCardNumberInput = $('#till_payments_seamless_card_number', $seamlessForm);
	        var $seamlessCvvInput = $('#till_payments_seamless_cvv', $seamlessForm);
	        var init = async function (integrationKey, invalidCallback, validCallback) {
	            _invalidCallback = invalidCallback;
	            _validCallback = validCallback;
	            if ($seamlessForm.length > 0) {
	                initialized = true;
	            } else {
	                return;
	            }
	            $seamlessCardNumberInput.height($seamlessCardHolderInput.css('height'));
	            $seamlessCvvInput.height($seamlessCardHolderInput.css('height'));
	            $seamlessForm.show();
	            var style = {
	                'border': $seamlessCardHolderInput.css('border'),
	                'border-radius': $seamlessCardHolderInput.css('border-radius'),
	                'height': $seamlessCardHolderInput.css('height'),
	                'padding': $seamlessCardHolderInput.css('padding'),
	                'font-size': $seamlessCardHolderInput.css('font-size'),
	                'font-weight': $seamlessCardHolderInput.css('font-weight'),
	                'font-family': $seamlessCardHolderInput.css('font-family'),
	                'letter-spacing': '0.1px',
	                'word-spacing': '1.7px',
	                'color': $seamlessCardHolderInput.css('color'),
	                'background': $seamlessCardHolderInput.css('background'),
	            };
	            const waitForScript = el => {
	                return new Promise((res, rej) => {
	                    let retryCounter = 0;
	                    const findScriptElement = el => {
	                        if (document.querySelector(el) !== null) 
	                            res(new PaymentJs('1.3'));
	                        else if (retryCounter == 50) 
	                            rej("Payment Js script failed to load"); 
	                        else {
	                            retryCounter += 1;
	                            setTimeout(() => findScriptElement(el), 100);
	                        }
	                    }
	                    findScriptElement(el);
	                });
	            }
	            await waitForScript(`[data-main="payment-js"]`).then(p => {
	                payment = p;
	                payment.init(integrationKey, $seamlessCardNumberInput.prop('id'), $seamlessCvvInput.prop('id'), function (payment) {
	                x = document.getElementsByClassName('payment_method_till_payments_creditcard');
	                x[1].style.background = 'transparent';
					
					// remove WP injected br tags 
					const paymentBoxes = document.querySelectorAll('#payment > ul > li > div > div.payment_box');
					paymentBoxes.forEach(box => {
						const brTags = box.querySelectorAll('br');
						brTags.forEach(br => {
							br.remove();
						});
					});

	                payment.enableAutofill();
	                payment.onAutofill(function(data) {
	                  $('#till_payments_seamless_card_holder').val(data.card_holder);
	                  $('#till_payments_seamless_expiry').val(data.month+"/"+data.year);
	                }
	                );
	                    payment.setNumberStyle(style);
	                    payment.setCvvStyle(style);
	                    payment.numberOn('input', function (data) {
	                        validNumber = data.validNumber;
	                        validate();
	                    });
	                    payment.cvvOn('input', function (data) {
	                        validCvv = data.validCvv;
	                        validate();
	                    });
	            });
	            })
	            .catch(e => {console.log(e)})
	            $('input, select', $seamlessForm).on('input', validate);
	        };
	        var validate = function () {
	            $tillPaymentsErrors.html('');
	            //$('.form-row', $seamlessForm).removeClass('woocommerce-invalid');
	            //$seamlessCardNumberInput.closest('.form-row').toggleClass('woocommerce-invalid', !validNumber);
	            //$seamlessCvvInput.closest('.form-row').toggleClass('woocommerce-invalid', !validCvv);
	            validDetails = true;
	            if (!$seamlessCardHolderInput.val().length) {
	                //$seamlessCardHolderInput.closest('.form-row').addClass('woocommerce-invalid');
	                validDetails = false;
	            }
	            if (!$seamlessExpiryInput.val().length) {
	                //$seamlessExpiryInput.closest('.form-row').addClass('woocommerce-invalid');
	                validDetails = false;
	            }
	            if (validNumber && validCvv && validDetails) {
	                _validCallback.call();
	                return;
	            }
	            // _invalidCallback.call();
	        };
	        var reset = function () {
	            $seamlessForm.hide();
	        };
	        // add in forward slash to mm/yy
	        function onExpiryInputChange(e) {
	            if (e.target.value.length > 2 && !e.target.value.includes("/")) {
	                document.getElementById("till_payments_seamless_expiry").value = e.target.value.slice(0, 2) + "/" + e.target.value.slice(2)
	            }
	        }
	        document.getElementById("till_payments_seamless_expiry").addEventListener("input", onExpiryInputChange);
	        // hide loader
	        function removeLoader() {
	            document.getElementById("loader").style.display = "none";
	        };
	        window.onload = function () {
	            document.querySelector("iframe").addEventListener("load", removeLoader());
	        }
	        var submit = function (success, error) {
	            var expiryData = $seamlessExpiryInput.val().split('/');
	            payment.tokenize({
	                    card_holder: $seamlessCardHolderInput.val(),
	                    month: expiryData[0],
	                    year: expiryData[1],
	                    email: $seamlessEmailInput.val()
	                },
	                function (token, cardData) {
	                    success.call(this, token);
	                },
	                function (errors) {
	                    error.call(this, errors);
	                }
	            );
	        };
	        return {
	            init: init,
	            reset: reset,
	            submit: submit,
	        };
	    }();
	    init();
	});
})(jQuery);