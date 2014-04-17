<?php
class Tritac_ChannelEngine_Helper_Data extends Mage_Core_Helper_Abstract {

    protected $_config = null;

    /**
     * Default expected shipment time (in weekdays)
     *
     * @var int
     */
    protected $_defaultTimeToShip = 5;

    /**
     * Get extension general config
     *
     * @param null $storeId
     * @return array
     */
    public function getConfig($storeId = null)
    {
        if(empty($this->_config)) {
            foreach(Mage::app()->getStores() as $_store) {
                $this->_config[$_store->getId()] = Mage::getStoreConfig('channelengine', $_store->getId());
            }
        }

        if($storeId) {
            if(!isset($this->_config[$storeId])) {
                return false;
            }else{
                return  $this->_config[$storeId];
            }
        }

        return $this->_config;
    }

    /**
     * Get extension general config
     *
     * @return array
     */
    public function getGeneralConfig()
    {
        $result = array();

        foreach($this->getConfig() as $storeId => $storeConfig) {
            $result[$storeId] = $storeConfig['general'];
        }

        return $result;
    }

    /**
     * Check required general config data
     *
     * @param null $storeId
     * @return bool
     */
    public function checkGeneralConfig($storeId = null)
    {
        $config = Mage::getStoreConfig('channelengine/general', $storeId);

        if(empty($config['api_key']) || empty($config['api_secret']) || empty($config['tenant'])) {
            $storeMsg = ($storeId) ? 'for store '.$storeId : '';
            Mage::log(
                "Couldn't connect to ChannelEngine.
                Please specify account keys {$storeMsg}
                (System/Configuration/Tritac ChannelEngine/Settings/General)"
            );
            return false;
        }

        return true;
    }

    /**
     * Get store expected shipment text
     *
     * @param $store_id
     * @return DateTime
     */
    public function getExpectedShipmentDate($store_id)
    {
        $config = $this->getConfig($store_id);

        $weekdays = (int) $config['shipping']['expected_date'];
        if($weekdays <= 0)
            $weekdays = $this->_defaultTimeToShip;

        $expectedDate = date("Y-m-d", strtotime("{$weekdays} weekdays"));
        return new DateTime($expectedDate);
    }
}