<?php

class Tritac_ChannelEngine_Model_Carrier_Channelengine
    extends Mage_Shipping_Model_Carrier_Abstract
    implements Mage_Shipping_Model_Carrier_Interface
{

    /** @var string Shipping method system code */
    protected $_code = 'channelengine';

    protected $_isFixed = true;

    /**
     * Collect and get shipping rates
     *
     * @param Mage_Shipping_Model_Rate_Request $request
     * @return bool|false|Mage_Core_Model_Abstract|Mage_Shipping_Model_Rate_Result|null
     */
    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        // Check if the rates were requested by ChannelEngine and not by the frontend
        if (!Mage::registry('channelengine_shipping')) {
            return false;
        }
        Mage::unregister('channelengine_shipping');

        $result = Mage::getModel('shipping/rate_result');

        $shippingPrice = 0;

        if (Mage::registry('channelengine_shipping_amount')) {
            $shippingPrice = Mage::registry('channelengine_shipping_amount');
        }
        Mage::unregister('channelengine_shipping_amount');


        $method = Mage::getModel('shipping/rate_result_method');

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));

        $method->setMethod($this->_code);
        $method->setMethodTitle($this->getConfigData('name'));

        $method->setPrice($shippingPrice);
        $method->setCost($shippingPrice);

        $result->append($method);


        return $result;
    }

    public function isActive()
    {

    }

    public function getAllowedMethods()
    {
        return array('channelengine' => 'ChannelEngine');
    }

}
