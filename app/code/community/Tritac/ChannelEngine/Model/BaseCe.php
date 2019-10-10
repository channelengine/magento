<?php

/**
 * Observer model
 */
class Tritac_ChannelEngine_Model_BaseCe
{
    /**
     * Join channelengine order fields to adminhtml order grid
     *
     * @param $observer
     */
    /*public function prepareOrderGridCollection($observer)
    {
        $collection = $observer->getOrderGridCollection();
        $joinTableName = Mage::getSingleton('core/resource')->getTableName('channelengine/order');
        $collection->getSelect()->joinLeft(
            array('channel_order_table' => $joinTableName),
            'channel_order_table.order_id = main_table.entity_id',
            array('channel_name', 'channel_order_id')
        );
    }*/

    /**
     * Add channelengine order fields to adminhtml order grid
     *
     * @param $observer
     * @return $this
     */
    /*public function appendCustomColumnToOrderGrid($observer)
    {
        $block = $observer->getBlock();
        if (!isset($block)) {
            return $this;
        }

        if ($block->getType() == 'adminhtml/sales_order_grid') {
            $block->addColumnAfter('channel_order_id', array(
                'header'=> Mage::helper('sales')->__('Channel Order ID'),
                'width' => '80px',
                'type'  => 'text',
                'index' => 'channel_order_id',
            ), 'real_order_id');

            $block->addColumnAfter('channel_name', array(
                'header'=> Mage::helper('sales')->__('Channel Name'),
                'width' => '80px',
                'type'  => 'text',
                'index' => 'channel_name',
            ), 'real_order_id');
        }
    }*/

    const LOGFILE = 'channelengine.log';

    /**
     * @param $message
     * @param null $level
     */
    protected function log($message, $level = null)
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
            'API Call failed [' . $response->getStatusCode() . '] ' . $response->getMessage() . PHP_EOL . print_r($model, true),
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
        $_resource = Mage::getSingleton('core/resource');
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
        if ($e instanceof \ChannelEngine\Merchant\ApiClient\ApiException) {
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
