<?php

/**
 * ChannelEngine Payment Method
 */
class Tritac_ChannelEngine_Model_Payment_Method_Channelengine extends Mage_Payment_Model_Method_Abstract
{

    /**
     * System payment method code
     *
     * @var string
     */
    protected $_code = 'channelengine';

    public function isAvailable($quote = null)
    {
        if (!is_null($quote) && $quote->getIsSystem() && $quote->getPayment()->getMethod() == $this->_code) {
            return true;
        }

        return false;
    }
}
