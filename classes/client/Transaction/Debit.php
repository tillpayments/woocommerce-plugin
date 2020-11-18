<?php

namespace TillPayments\Client\Transaction;

use TillPayments\Client\Transaction\Base\AbstractTransactionWithReference;
use TillPayments\Client\Transaction\Base\AddToCustomerProfileInterface;
use TillPayments\Client\Transaction\Base\AddToCustomerProfileTrait;
use TillPayments\Client\Transaction\Base\AmountableInterface;
use TillPayments\Client\Transaction\Base\AmountableTrait;
use TillPayments\Client\Transaction\Base\ItemsInterface;
use TillPayments\Client\Transaction\Base\ItemsTrait;
use TillPayments\Client\Transaction\Base\OffsiteInterface;
use TillPayments\Client\Transaction\Base\OffsiteTrait;
use TillPayments\Client\Transaction\Base\ScheduleInterface;
use TillPayments\Client\Transaction\Base\ScheduleTrait;

/**
 * Debit: Charge the customer for a certain amount of money. This could be once, but also recurring.
 *
 * @package TillPayments\Client\Transaction
 */
class Debit extends AbstractTransactionWithReference implements AmountableInterface, OffsiteInterface, ItemsInterface, ScheduleInterface, AddToCustomerProfileInterface {
    use OffsiteTrait;
    use AmountableTrait;
    use ItemsTrait;
    use ScheduleTrait;
    use AddToCustomerProfileTrait;

    const TRANSACTION_INDICATOR_SINGLE = 'SINGLE';
    const TRANSACTION_INDICATOR_INITIAL = 'INITIAL';
    const TRANSACTION_INDICATOR_RECURRING = 'RECURRING';
    const TRANSACTION_INDICATOR_CARDONFILE = 'CARDONFILE';
    const TRANSACTION_INDICATOR_CARDONFILE_MERCHANT = 'CARDONFILE_MERCHANT';

    /**
     * @var bool
     */
    protected $withRegister = false;

    /**
     * @var string
     */
    protected $transactionIndicator;

    /**
     * @return boolean
     */
    public function isWithRegister() {
        return $this->withRegister;
    }

    /**
     * set true if you want to register a user vault together with the debit
     *
     * @param boolean $withRegister
     *
     * @return $this
     */
    public function setWithRegister($withRegister) {
        $this->withRegister = $withRegister;
        return $this;
    }

    /**
     * @return string
     */
    public function getTransactionIndicator() {
        return $this->transactionIndicator;
    }

    /**
     * @param string $transactionIndicator
     */
    public function setTransactionIndicator($transactionIndicator) {
        $this->transactionIndicator = $transactionIndicator;
        return $this;
    }

}
