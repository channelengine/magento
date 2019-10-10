<?php

class Tritac_ChannelEngine_Model_Shipment extends Mage_Core_Model_Abstract
{

    protected function _construct()
    {
        $this->_init('channelengine/shipment');
    }

    /**
     * Load channel shipment by magento shipment ID
     *
     * @param $shipmentId
     * @return Tritac_ChannelEngine_Model_Shipment
     */
    public function loadByShipmentId($shipmentId)
    {
        $this->_getResource()->loadByShipmentId($this, $shipmentId);
        return $this;
    }
}