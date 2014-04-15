<?php
class Tritac_ChannelEngine_Block_Head extends Mage_Core_Block_Template
{
    public function getAccountName() {
        $config = Mage::helper('channelengine')->getGeneralConfig();
        return $config['api_key'];
    }
}