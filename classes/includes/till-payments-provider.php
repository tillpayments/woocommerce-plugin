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
            'WC_TillPayments_CreditCard_Amex',
            'WC_TillPayments_CreditCard_Diners',
            'WC_TillPayments_CreditCard_Discover',
            'WC_TillPayments_CreditCard_Jcb',
            'WC_TillPayments_CreditCard_Maestro',
            'WC_TillPayments_CreditCard_Mastercard',
            'WC_TillPayments_CreditCard_UnionPay',
            'WC_TillPayments_CreditCard_Visa',
        ];
    }

    public static function autoloadClient()
    {
        require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/vendor/autoload.php';
    }
}
