<?php
class Tritac_ChannelEngine_Block_Head extends Mage_Core_Block_Template
{
    public function getAccountName() {

        $storeId = Mage::app()->getStore()->getId();
        $config = Mage::helper('channelengine')->getGeneralConfig();

        if(!isset($config[$storeId]) || empty($config[$storeId]['tenant'])) return false;

        return $config[$storeId]['tenant'];
    }
}