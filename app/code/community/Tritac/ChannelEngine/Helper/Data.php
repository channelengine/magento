<?php
class Tritac_ChannelEngine_Helper_Data extends Mage_Core_Helper_Abstract
{
	private $config = array();

	public function __construct()
	{
		foreach(Mage::app()->getStores() as $store)
		{
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
		if(is_null($storeId)) return $this->config;
		if(!isset($this->config[$storeId])) return false;

		return  $this->config[$storeId];
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

		$weekdays = (int) $config['optional']['expected_date'];
		if($weekdays <= 0)
			$weekdays = $this->_defaultTimeToShip;

		$expectedDate = date("Y-m-d", strtotime("{$weekdays} weekdays"));
		return new DateTime($expectedDate);
	}

	public function getExtensionVersion()
	{
		return (string) Mage::getConfig()->getNode()->modules->Tritac_ChannelEngine->version;
	}
}