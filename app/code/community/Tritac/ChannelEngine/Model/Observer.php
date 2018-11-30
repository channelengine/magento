<?php
/**
 * Observer model
 */

use ChannelEngine\Merchant\ApiClient\Configuration;
use ChannelEngine\Merchant\ApiClient\ApiException;
use ChannelEngine\Merchant\ApiClient\Api\OrderApi;
use ChannelEngine\Merchant\ApiClient\Api\ShipmentApi;
use ChannelEngine\Merchant\ApiClient\Api\ReturnApi;
use ChannelEngine\Merchant\ApiClient\Model\MerchantOrderResponse;
use ChannelEngine\Merchant\ApiClient\Model\OrderAcknowledgement;
use ChannelEngine\Merchant\ApiClient\Model\MerchantShipmentRequest;
use ChannelEngine\Merchant\ApiClient\Model\MerchantShipmentTrackingRequest;
use ChannelEngine\Merchant\ApiClient\Model\MerchantShipmentLineRequest;

class Tritac_ChannelEngine_Model_Observer extends  Tritac_ChannelEngine_Model_BaseCe
{
    /**
     * The CE logfile path
     *
     * @var string
     */
    const LOGFILE = 'channelengine.log';

    /**
     * API client
     *
     * @var ChannelEngine\ApiClient\ApiClient
     */
    protected $_client = null;

    /**
     * API config. API key, API secret, API tenant
     *
     * @var array
     */
    protected $_config = null;

    /**
     * ChannelEngine helper
     *
     * @var Tritac_ChannelEngine_Helper_Data
     */
    protected $_helper = null;

    /**
     * ChannelEngine helper
     *
     * @var Tritac_ChannelEngine_Helper_Feed
     */
    protected $_feedHelper = null;

    /**
     * Whether this merchant uses the postNL extension
     *
     * @var bool
     */
    private $_hasPostNL = false;

    /**
     * Retrieve and validate API config
     * Initialize API client
     */
    public function __construct()
    {
        $this->_helper = Mage::helper('channelengine');
        $this->_feedHelper = Mage::helper('channelengine/feed');
        $this->_hasPostNL = Mage::helper('core')->isModuleEnabled('TIG_PostNL');
        $this->_config = $this->_helper->getConfig();

        /**
         * Check required config parameters. Initialize API client.
         */
        foreach($this->_config as $storeId => $storeConfig) {
            if($this->_helper->isConnected($storeId)) {
                $apiConfig = new Configuration();
                $apiConfig->setApiKey('apikey', $storeConfig['general']['api_key']);
                $apiConfig->setHost('https://'.$storeConfig['general']['tenant'].'.channelengine.net/api');
                $this->_client['orders'][$storeId] = new OrderApi(null,$apiConfig);
                $this->_client['returns'][$storeId] = new ReturnApi(null,$apiConfig);
                $this->_client['shipment'][$storeId] = new ShipmentApi(null,$apiConfig);

            }
        }
    }


    public function generateFeeds()
    {
        $this->_feedHelper->generateFeeds();
    }

    public function fetchFulfilmentOrders()
    {

        if(is_null($this->_client)) return false;
        $customer = new Tritac_ChannelEngine_Model_Customer();
        $product = new Tritac_ChannelEngine_Model_Product();
        $from_date = date('Y-m-d',strtotime('-5 days')) .' 00:00:00';
        $to_date = date('Y-m-d').' 23:59:59';
        foreach($this->_client['orders'] as $storeId => $client) {
            if(!$this->importFulfilmentOrders($storeId)) {
                continue;
            }
            $response = null;
            try {

                $response = $client->orderGetByFilter('SHIPPED', null, $from_date, $to_date, null, 'ONLY_CHANNEL');
                if(!$response->getSuccess())
                {
                    $this->logApiError($response);
                    continue;
                }
            }
            catch (Exception $e) {
                $this->logException($e);
                continue;
            }

            if($response->getCount() == 0) continue;
            foreach($response->getContent() as $order)
            {
                $lines = $order->getLines();
                $billingAddress = $order->getBillingAddress();
                $shippingAddress = $order->getShippingAddress();
                if(count($lines) == 0 || empty($billingAddress)) continue;
                $hasChannelOrder = Mage::getModel('channelengine/order') ->getCollection()->addFilter('channel_order_id',$order->getChannelOrderNo())->count();
                if($hasChannelOrder > 0) {
                    continue;
                }

                // Initialize new quote
                $quote = Mage::getModel('sales/quote')->setStoreId($storeId);
                $quote->setInventoryProcessed(true);
                foreach($lines as $item) {
                    $product_details = $product->generateProductId($item->getMerchantProductNo());
                    $productId = $product_details['id'];
                    $productOptions = array();
                    $ids = $product_details['ids'];
                    if (count($ids) == 3) {
                        $productOptions = array($ids[1] => intval($ids[2]));
                    }
                    $productNo = $product_details['productNo'];
                    // Load magento product
                    $_product = Mage::getModel('catalog/product')->setStoreId($storeId);
                    $_product->load($productId);
                    if (!$_product->getId()) {
                        // If the product can't be found by ID, fall back on the SKU.
                        $productId = $_product->getIdBySku($productNo);
                        $_product->load($productId);

                    }
                    // Prepare product parameters for quote
                    $params = new Varien_Object();
                    $params->setQty($item->getQuantity());
                    $params->setOptions($productOptions);
                    $add_product_to_quote = $product->addProductToQuote($_product, $productId, $quote, $params, $item, $order, $productNo);
                    if (!$add_product_to_quote) {
                        continue 2;
                    }

                }

                $customer->setBillingData($billingAddress,$order);
                $customer->setShippingData($shippingAddress,$order);
                // Register shipping cost. See Tritac_ChannelEngine_Model_Carrier_Channelengine::collectrates();
                Mage::register('channelengine_shipping_amount', floatval($order->getShippingCostsInclVat()));
                // Set this value to make sure ChannelEngine requested the rates and not the frontend
                // because the shipping method has a fallback on 0,- and this will make it show up on the frontend
                Mage::register('channelengine_shipping', true);

                $product_data = $product->processCustomerData($quote,$customer,$order);
                if(!$product_data['status']) {
                    continue;
                }
                $service = $product_data['service'];
                $magentoOrder = $service->getOrder();
                $product->processOrder($magentoOrder,$order);
            }

        }

        return true;
    }



    /**
     * Fetch new orders from ChannelEngine.
     * Ran by cron. The cronjob is set in extension config file.
     *
     * @return bool
     */
    public function fetchNewOrders()
    {
        /**
         * Check if client is initialized
         */
        $product = new Tritac_ChannelEngine_Model_Product();
        $customer = new Tritac_ChannelEngine_Model_Customer();

        if(is_null($this->_client['orders'])) return false;


        foreach($this->_client['orders'] as $storeId => $client) {
            if(!$this->enableOrderImport($storeId)) {
                continue;
            }
            $orderApi = $this->initOrderApi($client);
            $response =& $orderApi;
            if(!$orderApi) { continue;}
            if($response->getCount() == 0) continue;


            foreach($response->getContent() as $order) {
                $billingAddress = $order->getBillingAddress();
                $shippingAddress = $order->getShippingAddress();
                $lines = $order->getLines();

                if(count($lines) == 0 || empty($billingAddress)) continue;
                // Initialize new quote
                
                $quote = Mage::getModel('sales/quote')->setStoreId($storeId);
                $quote->setTotalsCollectedFlag(true);

                foreach($lines as $item) {
                    $product_details = $product->generateProductId($item->getMerchantProductNo());
                    // get order id
                    $productId = $product_details['id'];
                    $productOptions = array();
                    $ids = $product_details['ids'];
                    if (count($ids) == 3) {
                        $productOptions = array($ids[1] => intval($ids[2]));
                    }
                    $productNo = $product_details['productNo'];

                    // Load magento product
                    $_product = Mage::getModel('catalog/product')->setStoreId($storeId);
                    $_product->load($productId);
                    // If the product can't be found by ID, fall back on the SKU.
                    if(!$_product->getId()){
                        $productId = $_product->getIdBySku($productNo);
                        $_product->load($productId);
                    }
                    // visable vat
                    if($this->disableMagentoVatCalculation($storeId)) {
                        $_product->setTaxClassId(0);
                    }
                    // Prepare product parameters for quote
                    $params = new Varien_Object();
                    $params->setQty($item->getQuantity());
                    $params->setOptions($productOptions);
                    $add_product_to_quote = $product->addProductToQuote($_product, $productId, $quote, $params, $item, $order, $productNo);
                    if (!$add_product_to_quote) {
                        continue 2;
                    }
                }

                $phone = $customer->formatPhone($order);
                $customer->setBillingData($billingAddress,$order);
                $customer->setShippingData($shippingAddress,$order);
                // Register shipping cost. See Tritac_ChannelEngine_Model_Carrier_Channelengine::collectrates();
                Mage::register('channelengine_shipping_amount', floatval($order->getShippingCostsInclVat()));
                // Set this value to make sure ChannelEngine requested the rates and not the frontend
                // because the shipping method has a fallback on 0,- and this will make it show up on the frontend
                Mage::register('channelengine_shipping', true);
                $product_data = $product->processCustomerData($quote,$customer,$order);
                if(!$product_data['status']) {
                    continue;
                }
                $service = $product_data['service'];
                $magentoOrder = $service->getOrder();
                if(!$magentoOrder->getIncrementId()) {
                    $this->log("An order (#{$order->getId()}) could not be imported");
                    continue;
                }
                $product->processOrder($magentoOrder,$order);
                $send_to_ce = $this->ackChannelEngine($magentoOrder,$order,$client);
                if(!$send_to_ce) {
                    continue;
                }

            }
        }

        return true;
    }
    /**
     * Post new shipment to ChannelEngine. This function is set in extension config file.
     *
     * @param Varien_Event_Observer $observer
     * @return bool
     * @throws Exception
     */
    public function saveShipment(Varien_Event_Observer $observer)
    {
        $event = $observer->getEvent();
        /** @var $_shipment Mage_Sales_Model_Order_Shipment */
        $_shipment = $event->getShipment();
        /** @var $_order Mage_Sales_Model_Order */
        $_order = $_shipment->getOrder();

        $storeId = $_order->getStoreId();

        $ceOrder = Mage::getModel('channelengine/order')->loadByOrderId($_order->getId());
        if($ceOrder->getId() == null) return true;

        $errorTitle = "A shipment (#{$_shipment->getId()}) could not be updated";
        $errorMessage = "Please contact ChannelEngine support at support@channelengine.com";

        // Check if the API client was initialized for this order
        if(!isset($this->_client[$storeId])) return false;

        $shipmentApi = $this->_client['shipment'][$storeId];

        // Initialize new ChannelEngine shipment object
        $ceShipment = new MerchantShipmentRequest();
        $ceShipment->setMerchantOrderNo($_order->getId());
        $ceShipment->setMerchantShipmentNo($_shipment->getId());

        // Set tracking info if available
        $trackingCodes = $_shipment->getAllTracks();

        if(count($trackingCodes) > 0)
        {
            // CE supports one tracking code per shipment. When a shipment has multiple codes, take the first one.
            $trackingCode = $trackingCodes[0];
            $carrierCode = $trackingCode->getCarrierCode();
            $title = $trackingCode->getTitle();

            $ceShipment->setTrackTraceNo($trackingCode->getNumber());
            $ceShipment->setMethod(($carrierCode == 'custom' || $carrierCode == 'paazl') ? $title : $carrierCode);
        }

        // Post NL support, in case of a leter box parcel, we can safely omit the tracking code.
        if($this->_hasPostNL)
        {
            $postnlShipment = Mage::getModel('postnl_core/shipment')->load($_shipment->getId(), 'shipment_id');
            if($postnlShipment->getId() != null && $postnlShipment->getIsBuspakje())
            {
                $ceShipment->setMethod('Briefpost');
            }
        }

        // If the shipment is already known to ChannelEngine we will just update it
        $_channelShipment = Mage::getModel('channelengine/shipment')->loadByShipmentId($_shipment->getId());

        if($_channelShipment->getId() != null)
        {
            $ceShipmentUpdate = new MerchantShipmentTrackingRequest();
            $ceShipmentUpdate->setTrackTraceNo($ceShipment->getTrackTraceNo());
            $ceShipmentUpdate->setMethod($ceShipment->getMethod());

            try
            {
                $response = $shipmentApi->shipmentUpdate($_shipment->getId(), $ceShipmentUpdate);
                if(!$response->getSuccess())
                {
                    $this->logApiError($response, $ceShipmentUpdate);
                    $this->addAdminNotification($errorTitle, $errorMessage);
                    return false;
                }
            }
            catch(Exception $e)
            {
                $this->logException($e);
                return false;
            }

            return true;
        }

        // Add the shipment lines
        $ceShipmentLines = array();
        foreach($_shipment->getAllItems() as $_shipmentItem)
        {
            // Get the quantity for this shipment
            $shippedQty = (int)$_shipmentItem->getQty();
            if($shippedQty == 0) continue;

            // Get the original order item
            $_orderItem = Mage::getModel('sales/order_item')->load($_shipmentItem->getOrderItemId());
            if($_orderItem == null) continue;

            $ceShipmentLine = new MerchantShipmentLineRequest();
            $ceShipmentLine->setMerchantProductNo($_shipmentItem->getProductId());
            $ceShipmentLine->setQuantity($shippedQty);
            $ceShipmentLines[] = $ceShipmentLine;
        }

        // Check if there are any shipment lines
        if(count($ceShipmentLines) == 0) return false;

        $ceShipment->setLines($ceShipmentLines);

        // Post shipment to ChannelEngine
        try
        {
            $response = $shipmentApi->shipmentCreate($ceShipment);
            if(!$response->getSuccess())
            {
                $this->logApiError($response, $ceShipment);
                $this->addAdminNotification($errorTitle, $errorMessage);
                return false;
            }

            $_channelShipment = Mage::getModel('channelengine/shipment')->setShipmentId($_shipment->getId());
            $_channelShipment->save();
        }
        catch(Exception $e)
        {
            $this->logException($e);
            $this->addAdminNotification($errorTitle, $errorMessage);
            return false;
        }

        return true;
    }

    /**
     * Fetch new returns from channelengine
     *
     * @return bool
     */
    public function fetchReturns()
    {
        if(is_null($this->_client)) return false;

        foreach($this->_client['returns'] as $storeId => $client)
        {
            $returnApi =& $client;
            $lastUpdatedAt = new DateTime('-1 day');

            $response = null;

            try
            {
                $response = $returnApi->returnGetDeclaredByChannel($lastUpdatedAt);
                if(!$response->getSuccess())
                {
                    $this->logApiError($response);
                    continue;
                }
            }
            catch (Exception $e)
            {
                $this->logException($e);
                continue;
            }


            if($response->getCount() == 0) continue;

            foreach($response->getContent() as $return)
            {
                //$_channelOrder = Mage::getModel('channelengine/order')->loadByChannelOrderId($return->getOrderId());
                $_order = Mage::getModel('sales/order')->load($return->getMerchantOrderNo());

                if(!$_order->getIncrementId()) continue;

                $link       = "https://". $this->_config[$storeId]['general']['tenant'] .".channelengine.net/returns";
                $title      = "A new return was declared in ChannelEngine for order #" . $_order->getIncrementId();
                $message    = "Magento Order #<a href='".
                    Mage::helper('adminhtml')->getUrl('adminhtml/sales_order/view', array('order_id' => $_order->getId())).
                    "'>".
                    $_order->getIncrementId().
                    "</a><br />";
                $message   .= "Comment: {$return->getCustomerComment()}<br />";
                $message   .= "Reason: {$return->getReason()}<br />";
                $message   .= "For more details visit ChannelEngine your <a href='".$link."' target='_blank'>account</a>";

                $this->addAdminNotification($title, $message);
            }
        }

        return true;
    }


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
}