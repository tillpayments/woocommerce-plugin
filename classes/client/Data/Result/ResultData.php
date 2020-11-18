<?php


namespace TillPayments\Client\Data\Result;

/**
 * Class ResultData
 *
 * @package TillPayments\Client\Data\Result
 */
abstract class ResultData {

    /**
     * @return array
     */
    abstract public function toArray();

}
