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
     * @return array
     */
    public function getConfig()
    {
        if(empty($this->_config))
            $this->_config = Mage::getStoreConfig('channelengine');

        return $this->_config;
    }

    /**
     * Get extension general config
     *
     * @return array
     */
    public function getGeneralConfig()
    {
        return $this->_config['general'];
    }

    /**
     * Check required general config data
     *
     * @return bool
     */
    public function checkGeneralConfig()
    {
        $config = $this->getGeneralConfig();

        if(empty($config['api_key']) || empty($config['api_secret']) || empty($config['tenant'])) {
            Mage::log(
                "Couldn't connect to ChannelEngine.
                Please specify account keys
                (System/Configuration/Tritac ChannelEngine/Settings/General)"
            );
            return false;
        }

        return true;
    }

    public function getExpectedShipmentDate()
    {
        $config = $this->getConfig();
        $weekdays = (int) $config['shipping']['expected_date'];
        if($weekdays <= 0)
            $weekdays = $this->_defaultTimeToShip;

        $expectedDate = date("Y-m-d", strtotime("{$weekdays} weekdays"));
        return new DateTime($expectedDate);
    }
}