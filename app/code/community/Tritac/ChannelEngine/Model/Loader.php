<?php

/**
 * Observer model
 */
class Tritac_ChannelEngine_Model_Loader
{
    const AUTOLOAD_FILENAME = 'autoload.php';
    const DEFAULT_PATH = '{{libdir}}/ChannelEngine/vendor';

    /**
     * @var bool
     */
    protected static $added = false;

    /**
     * Register the Composer autoloader
     * @param Varien_Event_Observer $observer
     */
    public function addComposerAutoloader(Varien_Event_Observer $observer)
    {
        if (self::$added === false) {
            $this->registerAutoloader();
            self::$added = true;
        }
    }

    /**
     * The location of the vendor directory on the machine the site is running on.
     * It always comes without a trailing slash.
     *
     * @return string
     */
    public function getVendorDirectoryPath()
    {
        $path = (string)Mage::getConfig()->getNode('global/composer_autoloader/path');
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
}
