<?php

class Tritac_ChannelEngine_Model_Resource_Shipment extends Mage_Core_Model_Resource_Db_Abstract
{

    protected function _construct()
    {
        $this->_init('channelengine/shipment', 'entity_id');
    }

    /**
     * Load channel shipment by magento shipment ID
     *
     * @param Tritac_ChannelEngine_Model_Shipment $shipment
     * @param int $shipmentId
     * @return Tritac_ChannelEngine_Model_Resource_Shipment
     */
    public function loadByShipmentId(Tritac_ChannelEngine_Model_Shipment $shipment, $shipmentId)
    {

        $adapter = $this->_getReadAdapter();
        $bind = array('shipment_id' => $shipmentId);
        $select = $adapter->select()
            ->from($this->getMainTable(), array($this->getIdFieldName()))
            ->where('shipment_id = :shipment_id');

        $entityId = $adapter->fetchOne($select, $bind);
        if ($entityId) {
            $this->load($shipment, $entityId);
        } else {
            $shipment->setData(array());
        }

        return $this;
    }
}