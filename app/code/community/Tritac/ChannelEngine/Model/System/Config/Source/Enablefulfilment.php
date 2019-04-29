<?php
/**
 * @category    Tritac
 * @package     Tritac_ChannelEngine
 * @copyright   Copyright (c) 2014 ChannelEngine. (http://www.channelengine.com)
 */

class Tritac_ChannelEngine_Model_System_Config_Source_Enablefulfilment
{
    protected $_options;
    public function toOptionArray()
    {
        return array(
            array(
                'value' => 0,
                'label' => 'No',
            ),
            array(
                'value' => 1,
                'label' => 'Yes',
            )
        );
    }
}
