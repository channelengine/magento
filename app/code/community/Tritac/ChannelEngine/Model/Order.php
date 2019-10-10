<?php

class Tritac_ChannelEngine_Model_Order extends Mage_Core_Model_Abstract
{

    protected function _construct()
    {
        $this->_init('channelengine/order');
    }

    /**
     * Load channel order by magento order ID
     *
     * @param $orderId
     * @return Tritac_ChannelEngine_Model_Order
     */
    public function loadByOrderId($orderId)
    {
        $this->_getResource()->loadByOrderId($this, $orderId);
        return $this;
    }

    /**
     * Load channel order by channel order ID
     *
     * @param $orderId
     * @return Tritac_ChannelEngine_Model_Order
     */
    public function loadByChannelOrderId($orderId)
    {
        $this->_getResource()->loadByChannelOrderId($this, $orderId);
        return $this;
    }
}