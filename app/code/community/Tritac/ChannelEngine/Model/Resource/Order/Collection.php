<?php

class Tritac_ChannelEngine_Model_Resource_Order_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{

    protected function _construct()
    {
        $this->_init('channelengine/order');
    }
}