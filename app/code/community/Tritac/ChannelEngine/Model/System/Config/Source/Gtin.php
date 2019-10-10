<?php
/**
 * @category    Tritac
 * @package     Tritac_ChannelEngine
 * @copyright   Copyright (c) 2014 ChannelEngine. (http://www.channelengine.com)
 */

class Tritac_ChannelEngine_Model_System_Config_Source_Gtin
{
    protected $_options;

    public function toOptionArray()
    {
        if (!$this->_options) {
            $this->_options[] = array('value' => '', 'label' => Mage::helper('adminhtml')->__('-- Please Select --'));
            $attributes = Mage::getSingleton('eav/config')
                ->getEntityType(Mage_Catalog_Model_Product::ENTITY)
                ->getAttributeCollection()
                ->addFieldToFilter('backend_type', array('in' => array('static', 'varchar', 'text')))
                ->addFieldToFilter('frontend_input', array('in' => array('text', 'textarea')))
                ->setOrder('frontend_label', 'ASC');

            foreach ($attributes as $attribute) {
                $value = $attribute->getAttributeCode();
                $label = ($attribute->getFrontendLabel()) ? $attribute->getFrontendLabel() : $value;
                $this->_options[] = array('value' => $value, 'label' => $label);
            }
        }
        return $this->_options;
    }
}
