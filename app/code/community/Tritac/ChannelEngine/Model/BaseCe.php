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
     * @param $storeId
     * @return bool
     */
    protected function disableMagentoVatCalculation($storeId)
    {
        $store = Mage::getModel('core/store')->load($storeId);
        return Mage::getStoreConfig('channelengine/optional/disable_magento_vat_calculation', $store) == 1;
    }

    /**
     * @param $storeId
     * @return bool
     */
    protected function importFulfilmentOrders($storeId)
    {
        $store = Mage::getModel('core/store')->load($storeId);
        return Mage::getStoreConfig('channelengine/optional/enable_fulfilment_import', $store) == 1;
    }

    /**
     * Enable the order import
     * @param $storeId
     * @return bool
     */
    protected function enableOrderImport($storeId)
    {

        $store = Mage::getModel('core/store')->load($storeId);
        return Mage::getStoreConfig('channelengine/general/enable_order_import', $store) == 1;
    }



    /**
     * @param $magentoOrder
     * @param $order
     * @param $client
     * @return bool
     */
    protected function ackChannelEngine($magentoOrder,$order,$client)
    {
        try
        {
            // Send order acknowledgement to CE.
            $ack = new \ChannelEngine\Merchant\ApiClient\Model\OrderAcknowledgement();
            $ack->setMerchantOrderNo($magentoOrder->getId());
            $ack->setOrderId($order->getId());
            $response = $client->orderAcknowledge($ack);
            if(!$response->getSuccess()) {
                $this->logApiError($response, $ack);
                return false;
            } else {
                return true;
            }
        }
        catch(Exception $e)
        {
            $this->logException($e);
            return false;
        }
    }

    /**
     * @param $orderApi
     * @return bool
     */
    protected function initOrderApi($orderApi)
    {
        try {
            $response = $orderApi->orderGetNew();
            if(!$response->getSuccess()) {
                $this->logApiError($response);
                return false;
            }
            return $response;
        } catch (Exception $e) {
            $this->logException($e);
            return false;
        }
    }
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
