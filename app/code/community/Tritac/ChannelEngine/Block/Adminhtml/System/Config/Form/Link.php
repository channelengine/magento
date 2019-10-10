<?php

/**
 * Adminhtml system href
 *
 * @category   Tritac
 * @package    Tritac_ChannelEngine
 */
class Tritac_ChannelEngine_Block_Adminhtml_System_Config_Form_Link extends Mage_Adminhtml_Block_System_Config_Form_Field
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
        $data = $element->getOriginalData();
        $action = $data['link_action'];
        $label = $data['link_label'];
        $url = $this->getUrl('adminhtml/ce/' . $action);
        return '<a type="text" href="' . $url . '" target="_blank">' . $label . '</a>';
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
