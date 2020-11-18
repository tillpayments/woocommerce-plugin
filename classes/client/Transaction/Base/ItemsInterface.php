<?php

namespace TillPayments\Client\Transaction\Base;
use TillPayments\Client\Data\Item;

/**
 * Interface ItemsInterface
 *
 * @package TillPayments\Client\Transaction\Base
 */
interface ItemsInterface {

    /**
     * @param Item[] $items
     * @return void
     */
    public function setItems($items);

    /**
     * @return Item[]
     */
    public function getItems();

    /**
     * @param Item $item
     * @return void
     */
    public function addItem($item);

}
