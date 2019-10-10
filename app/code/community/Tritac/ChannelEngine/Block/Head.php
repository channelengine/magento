<?php

class Tritac_ChannelEngine_Block_Head extends Mage_Core_Block_Template
{
    public function getAccountName()
    {
        $storeId = Mage::app()->getStore()->getId();
        $helper = Mage::helper('channelengine');

        if (!$helper->isConnected($storeId)) return false;

        $config = $helper->getConfig($storeId);

        return $config['general']['tenant'];
    }
}