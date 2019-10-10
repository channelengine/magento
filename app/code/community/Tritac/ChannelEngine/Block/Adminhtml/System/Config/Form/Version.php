<?php

/**
 * Adminhtml system href
 *
 * @category   Tritac
 * @package    Tritac_ChannelEngine
 */
class Tritac_ChannelEngine_Block_Adminhtml_System_Config_Form_Version extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /*
     * Set template
     */
    private $helper;
    private $data;

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
        // Hide checkbox
        $element->unsCanUseWebsiteValue();
        return $this->helper->getExtensionVersion();
    }

    /**
     * Render element
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        // Hide checkbox
        $element->unsCanUseWebsiteValue()->unsCanUseDefaultValue()->unsScope();
        return parent::render($element);
    }
}
