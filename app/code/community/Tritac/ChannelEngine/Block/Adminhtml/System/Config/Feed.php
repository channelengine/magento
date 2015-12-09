<?php
/**
 * Adminhtml system "generate feed" button
 *
 * @category   Tritac
 * @package    Tritac_ChannelEngine
 */
class Tritac_ChannelEngine_Block_Adminhtml_System_Config_Feed extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    /**
     * Set template block template
     *
     * @return Tritac_ChannelEngine_Block_Adminhtml_System_Config_Feed
     */
    protected function _prepareLayout()
    {
        parent::_prepareLayout();
        if (!$this->getTemplate()) {
            $this->setTemplate('channelengine/system/config/feed/generate_button.phtml');
        }
        return $this;
    }

    /**
     * Disable website scope
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    public function render(Varien_Data_Form_Element_Abstract $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * Get the button and scripts contents
     *
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        $originalData = $element->getOriginalData();
        $this->addData(array(
            'button_label' => Mage::helper('channelengine')->__($originalData['button_label']),
            'html_id' => $element->getHtmlId(),
            'ajax_url' => Mage::getSingleton('adminhtml/url')->getUrl('channelengine/adminhtml_generate/ajax')
        ));

        return $this->_toHtml();
    }
}
