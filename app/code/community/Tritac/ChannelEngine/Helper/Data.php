<?php

class Tritac_ChannelEngine_Helper_Data extends Mage_Core_Helper_Abstract
{
    private $config = array();

    public function __construct()
    {
        foreach (Mage::app()->getStores() as $store) {
            $this->config[$store->getId()] = Mage::getStoreConfig('channelengine', $store->getId());
        }
    }

    /**
     * Default expected shipment time (in weekdays)
     *
     * @var int
     */
    protected $_defaultTimeToShip = 5;


    /**
     * Get extension general config
     *
     * @param null $storeId
     * @return array
     */
    public function getConfig($storeId = null)
    {
        if (is_null($storeId)) return $this->config;
        if (!isset($this->config[$storeId])) return false;

        return $this->config[$storeId];
    }

    /**
     * Check required general config data
     *
     * @param null $storeId
     * @return bool
     */
    public function isConnected($storeId)
    {
        $config = $this->getConfig($storeId);
        return (empty($config['general']['api_key']) || empty($config['general']['tenant'])) ? false : true;
    }

    /**
     * Get store expected shipment text
     *
     * @param $storeId
     * @return DateTime
     */
    public function getExpectedShipmentDate($storeId)
    {
        $config = $this->getConfig($storeId);

        $weekdays = (int)$config['optional']['expected_date'];
        if ($weekdays <= 0)
            $weekdays = $this->_defaultTimeToShip;

        $expectedDate = date("Y-m-d", strtotime("{$weekdays} weekdays"));
        return new DateTime($expectedDate);
    }

    /**
     * @param $storeId
     * @return bool
     */
    public function disableMagentoVatCalculation($storeId)
    {
        $config = $this->getConfig($storeId);
        $value = $config['optional']['disable_magento_vat_calculation'];
        return $value == 1;
    }

    /**
     * @param $storeId
     * @return bool
     */
    public function isMarketplaceFulfilledOrderImportEnabled($storeId)
    {
        $config = $this->getConfig($storeId);
        $value = $config['general']['enable_fulfilment_import'];
        return $value == 1;
    }

    public function isFeedGenerationEnabled($storeId)
    {
        $config = $this->getConfig($storeId);
        $value = $config['general']['enable_feed_generation'];
        return $value == 1;
    }

    /**
     * Enable the order import
     * @param $storeId
     * @return bool
     */
    public function isOrderImportEnabled($storeId)
    {
        $config = $this->getConfig($storeId);
        $value = $config['general']['enable_order_import'];
        return $value == 1;
    }

    public function useSkuInsteadOfId($storeId)
    {
        $config = $this->getConfig($storeId);
        $value = $config['optional']['use_sku_instead_of_id'];
        return $value == 1;
    }

    public function getExtensionVersion()
    {
        return (string)Mage::getConfig()->getNode()->modules->Tritac_ChannelEngine->version;
    }
}