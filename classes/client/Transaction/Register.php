<?php

namespace TillPayments\Client\Transaction;

use TillPayments\Client\Transaction\Base\AbstractTransaction;
use TillPayments\Client\Transaction\Base\AddToCustomerProfileInterface;
use TillPayments\Client\Transaction\Base\AddToCustomerProfileTrait;
use TillPayments\Client\Transaction\Base\OffsiteInterface;
use TillPayments\Client\Transaction\Base\OffsiteTrait;
use TillPayments\Client\Transaction\Base\ScheduleInterface;
use TillPayments\Client\Transaction\Base\ScheduleTrait;

/**
 * Register: Register the customer's payment data for recurring charges.
 *
 * The registered customer payment data will be available for recurring transaction without user interaction.
 *
 * @package TillPayments\Client\Transaction
 */
class Register extends AbstractTransaction implements OffsiteInterface, ScheduleInterface, AddToCustomerProfileInterface {
    use OffsiteTrait;
    use ScheduleTrait;
    use AddToCustomerProfileTrait;
}
