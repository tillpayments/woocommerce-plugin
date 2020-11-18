<?php

namespace TillPayments\Client\Transaction;

use TillPayments\Client\Transaction\Base\AbstractTransactionWithReference;
use TillPayments\Client\Transaction\Base\AmountableInterface;
use TillPayments\Client\Transaction\Base\AmountableTrait;
use TillPayments\Client\Transaction\Base\ItemsInterface;
use TillPayments\Client\Transaction\Base\ItemsTrait;

/**
 * Capture: Charge a previously preauthorized transaction.
 *
 * @package TillPayments\Client\Transaction
 */
class Capture extends AbstractTransactionWithReference implements AmountableInterface, ItemsInterface {
    use AmountableTrait;
    use ItemsTrait;
}
