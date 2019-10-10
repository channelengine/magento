<?php

/**
 * Adminhtml system "generate feed" button
 *
 * @category   Tritac
 * @package    Tritac_ChannelEngine
 */
class Tritac_ChannelEngine_Block_Adminhtml_System_Config_Form_Feed extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /*
     * Set template
     */
    private $helper;

    protected function _construct()
    {
        parent::_construct();
        $this->helper = Mage::helper('channelengine');
    }

    /**
     * Return element html
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $value = "Please set your account name";
        $disabled = "disabled";
        $storeId = Mage::getSingleton('adminhtml/config_data')->getScopeId();
        $config = $this->helper->getConfig($storeId);
        $hasValue = $config && !empty($config['general']['tenant']);
        if ($hasValue) {
            $baseUrl = Mage::app()->getStore($storeId)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA);
            $value = $baseUrl . "channelengine/" . $config['general']['tenant'] . "_products.xml";
            $disabled = "";
        }
        return '<input type="text" class="input-text" value="' . $value . '" ' . $disabled . '/>';
    }
}
