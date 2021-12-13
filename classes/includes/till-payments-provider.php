<?php

final class WC_TillPayments_Provider
{
    public static function paymentMethods()
    {
        /**
         * Comment/disable adapters that are not applicable
         */
        return [
            'WC_TillPayments_CreditCard',
        ];
    }

    public static function autoloadClient()
    {
        require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/vendor/autoload.php';
    }
}
