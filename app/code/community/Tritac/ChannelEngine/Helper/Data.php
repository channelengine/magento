<?php
class Tritac_ChannelEngine_Helper_Data extends Mage_Core_Helper_Abstract {

    const AUTOLOAD_FILENAME = 'autoload.php';
    const DEFAULT_PATH = '{{libdir}}/ChannelEngine/vendor';

    protected $_config = null;

    /**
     * Default expected shipment time (in weekdays)
     *
     * @var int
     */
    protected $_defaultTimeToShip = 5;

    /**
     * The location of the vendor directory on the machine the site is running on.
     * It always comes without a trailing slash.
     *
     * @return string
     */
    public function getVendorDirectoryPath()
    {
        $path = (string) Mage::getConfig()->getNode('global/composer_autoloader/path');
        if (!$path) {
            $path = self::DEFAULT_PATH;
        }
        $path = str_replace('/', DS, $path);
        $path = str_replace('{{basedir}}', Mage::getBaseDir(), $path);
        $path = str_replace('{{libdir}}', Mage::getBaseDir('lib'), $path);
        $path = rtrim($path, DS);
        return realpath($path);
    }

    /**
     * @param string|null $path Path to vendor directory. Pass null to use the configured value.
     * @param string|null $filename Filename of autoload file. Pass null to use the default (autoload.php).
     */
    public function registerAutoloader($path = null, $filename = null)
    {
        if ($path === null) {
            $path = $this->getVendorDirectoryPath();
        }
        if ($filename === null) {
            $filename = self::AUTOLOAD_FILENAME;
        }
        if (file_exists($path . DS . $filename)) {
            require_once($path . DS . $filename);
        }
    }

    /**
     * Get extension general config
     *
     * @param null $storeId
     * @return array
     */
    public function getConfig($storeId = null)
    {
        if(empty($this->_config)) {
            foreach(Mage::app()->getStores() as $_store) {
                $this->_config[$_store->getId()] = Mage::getStoreConfig('channelengine', $_store->getId());
            }
        }

        if($storeId) {
            if(!isset($this->_config[$storeId])) {
                return false;
            }else{
                return  $this->_config[$storeId];
            }
        }

        return $this->_config;
    }

    /**
     * Get extension general config
     *
     * @return array
     */
    public function getGeneralConfig()
    {
        $result = array();

        foreach($this->getConfig() as $storeId => $storeConfig)
        {
            if(isset($storeConfig['general']))
            {
                $result[$storeId] = $storeConfig['general'];
            }
        }
        return $result;
    }

    /**
     * Check required general config data
     *
     * @param null $storeId
     * @return bool
     */
    public function checkGeneralConfig($storeId = null)
    {
        $config = Mage::getStoreConfig('channelengine/general', $storeId);
        return (empty($config['api_key']) || empty($config['tenant'])) ? false : true;
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

        $weekdays = (int) $config['shipping']['expected_date'];
        if($weekdays <= 0)
            $weekdays = $this->_defaultTimeToShip;

        $expectedDate = date("Y-m-d", strtotime("{$weekdays} weekdays"));
        return new DateTime($expectedDate);
    }
}