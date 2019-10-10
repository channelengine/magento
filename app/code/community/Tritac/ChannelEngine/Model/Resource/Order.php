<?php

class Tritac_ChannelEngine_Model_Resource_Order extends Mage_Core_Model_Resource_Db_Abstract
{

    protected function _construct()
    {
        $this->_init('channelengine/order', 'entity_id');
    }

    /**
     * Load channel order by magento order ID
     *
     * @param Tritac_ChannelEngine_Model_Order $order
     * @param int $orderId
     * @return Tritac_ChannelEngine_Model_Resource_Order
     */
    public function loadByOrderId(Tritac_ChannelEngine_Model_Order $order, $orderId)
    {

        $adapter = $this->_getReadAdapter();
        $bind = array('order_id' => $orderId);
        $select = $adapter->select()
            ->from($this->getMainTable(), array($this->getIdFieldName()))
            ->where('order_id = :order_id');

        $entityId = $adapter->fetchOne($select, $bind);
        if ($entityId) {
            $this->load($order, $entityId);
        } else {
            $order->setData(array());
        }

        return $this;
    }

    /**
     * Load channel order by channel order ID
     *
     * @param Tritac_ChannelEngine_Model_Order $order
     * @param int $orderId
     * @return Tritac_ChannelEngine_Model_Resource_Order
     */
    public function loadByChannelOrderId(Tritac_ChannelEngine_Model_Order $order, $orderId)
    {

        $adapter = $this->_getReadAdapter();
        $bind = array('channel_order_id' => $orderId);
        $select = $adapter->select()
            ->from($this->getMainTable(), array($this->getIdFieldName()))
            ->where('channel_order_id = :channel_order_id');

        $entityId = $adapter->fetchOne($select, $bind);
        if ($entityId) {
            $this->load($order, $entityId);
        } else {
            $order->setData(array());
        }

        return $this;
    }
}