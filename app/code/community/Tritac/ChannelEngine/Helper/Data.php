<?php
class Tritac_ChannelEngine_Helper_Data extends Mage_Core_Helper_Abstract {

    protected $_config;

    /**
     * Get extension config
     *
     * @return array
     */
    public function getConfig()
    {
        if(empty($this->_config))
            $this->_config = Mage::getStoreConfig('channelengine/general');

        return $this->_config;
    }

    /**
     * Check required config data
     *
     * @return bool
     */
    public function checkConfig()
    {
        $config = $this->_config;

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


}