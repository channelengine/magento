<?php
/**
 * Observer model
 */
class Tritac_ChannelEngine_Model_Loader
{
    /**
     * @var bool
     */
    protected static $added = false;
    /**
     * Register the Composer autoloader
     * @param Varien_Event_Observer $observer
     */
    public function addComposerAutoloader(Varien_Event_Observer $observer)
    {
        if (self::$added === false) {
            /** @var $helper Tritac_ChannelEngine_Helper_Data */
            $helper = Mage::helper('channelengine');
            $helper->registerAutoloader();
            self::$added = true;
        }
    }
}
