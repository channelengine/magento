<?php
class Tritac_ChannelEngine_Block_Head extends Mage_Core_Block_Template
{
    public function getAccountName() {

        $storeId = Mage::app()->getStore()->getId();
        $config = Mage::helper('channelengine')->getConfig($storeId);

        if(!isset($config['general'][$storeId]) || empty($config['general'][$storeId]['tenant'])) return false;

        return $config['general'][$storeId]['tenant'];
    }
}