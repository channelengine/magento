<?php

/**
 * Adminhtml system "generate feed" button
 *
 * @category   Tritac
 * @package    Tritac_ChannelEngine
 */
class Tritac_ChannelEngine_Block_Adminhtml_System_Config_Form_Button extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /*
     * Set template
     */
    private $data;
    private $id;

    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('channelengine/system/config/form/ajax_button.phtml');
    }

    /**
     * Return element html
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $this->data = $element->getOriginalData();
        $this->id = '_id_' . rand();
        return $this->_toHtml();
    }

    /**
     * Return ajax url for button
     *
     * @return string
     */
    public function getAjaxUrl()
    {
        $action = $this->data['ajax_action'];
        return Mage::helper('adminhtml')->getUrl('adminhtml/ce/' . $action);
    }

    public function getId()
    {
        return $this->id;
    }

    /**
     * Generate button html
     *
     * @return string
     */
    public function getButtonHtml()
    {
        $label = $this->data['button_label'];

        $button = $this->getLayout()->createBlock('adminhtml/widget_button')->setData(array(
            'id' => $this->id,
            'label' => $label, //$this->helper('channelengine')->__('Generate Feed'),
            'onclick' => 'javascript:makeRequest' . $this->id . '(); return false;'
        ));

        return $button->toHtml();
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
