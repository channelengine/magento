<?php
/**
 * Observer model
 */
abstract  class Tritac_ChannelEngine_Model_aChannelEngine
{

    /**
     * @param $message
     * @param null $level
     */
    private function log($message, $level = null)
    {
        Mage::log($message . PHP_EOL . '--------------------', $level, $file = self::LOGFILE, true);
    }


    /**
     * @param $response
     * @param null $model
     */
    protected function logApiError($response, $model = null)
    {
        $this->log(
            'API Call failed ['.$response->getStatusCode().'] ' . $response->getMessage() . PHP_EOL . print_r($model, true),
            Zend_Log::ERR
        );
    }


    /**
     * @param $title
     * @param $message
     */
    protected function addAdminNotification($title, $message)
    {
        // Check if notification already exists
        $_resource  = Mage::getSingleton('core/resource');
        $_connectionRead = $_resource->getConnection('core_read');
        $select = $_connectionRead->select()
            ->from($_resource->getTableName('adminnotification/inbox'))
            ->where('title = ?', $title)
            ->where('is_remove != 1')
            ->limit(1);

        $data = $_connectionRead->fetchRow($select);

        if ($data) return;

        // Add new notification
        Mage::getModel('adminnotification/inbox')->addCritical($title, $message);
    }


    /**
     * @param $e
     * @param null $model
     */
    protected function logException($e, $model = null)
    {
        if($e instanceof \ChannelEngine\Merchant\ApiClient\ApiException)
        {
            $message = $e->getMessage() . PHP_EOL .
                print_r($e->getResponseBody(), true) .
                print_r($e->getResponseHeaders(), true) .
                print_r($model, true) .
                $e->getTraceAsString();
            $this->log($message, Zend_Log::ERR);
            return;
        }

        $this->log($e->__toString(), Zend_Log::ERR);
    }


}
