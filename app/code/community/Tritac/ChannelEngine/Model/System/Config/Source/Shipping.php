<?php

class Tritac_ChannelEngine_Model_System_Config_Source_Shipping
{

    protected $_options = null;

    public function toOptionArray()
    {

        if (is_null($this->_options)) {
            $_activeCarriers = Mage::getModel('shipping/config')->getActiveCarriers();

            foreach ($_activeCarriers as $carrierCode => $_carrier) {

                if ($_methods = $_carrier->getAllowedMethods()) {

                    if (!$title = Mage::getStoreConfig("carriers/{$_carrier->getId()}/title")) {
                        $title = $carrierCode;
                    }

                    $methods = array();

                    foreach ($_methods as $methodCode => $method) {

                        $methods[] = array(
                            'label' => $title . ' – ' . $method,
                            'value' => $carrierCode . '_' . $methodCode
                        );
                    }

                    $this->_options[] = array(
                        'label' => $title,
                        'value' => $methods
                    );
                }
            }
        }

        return $this->_options;

    }
}