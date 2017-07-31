<?php
/**
 * Observer model
 */

use ChannelEngine\ApiClient\ApiClient;
use ChannelEngine\ApiClient\Configuration;
use ChannelEngine\ApiClient\ApiException;

use ChannelEngine\ApiClient\Api\OrderApi;
use ChannelEngine\ApiClient\Api\ShipmentApi;
use ChannelEngine\ApiClient\Api\CancellationApi;
use ChannelEngine\ApiClient\Api\ReturnApi;

use ChannelEngine\ApiClient\Model\MerchantOrderResponse;
use ChannelEngine\ApiClient\Model\OrderAcknowledgement;
use ChannelEngine\ApiClient\Model\MerchantShipmentRequest;
use ChannelEngine\ApiClient\Model\MerchantShipmentTrackingRequest;
use ChannelEngine\ApiClient\Model\MerchantShipmentLineRequest;

class Tritac_ChannelEngine_Model_Observer
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

                $client = new ApiClient($apiConfig);
                $this->_client[$storeId] = $client;
            }
        }
    }

    private function logApiError($response, $model = null)
    {
        $this->log(
            'API Call failed ['.$response->getStatusCode().'] ' . $response->getMessage() . PHP_EOL . print_r($model, true),
            Zend_Log::ERR
        );
    }

    private function log($message, $level = null)
    {
        Mage::log($message . PHP_EOL . '--------------------', $level, $file = self::LOGFILE, true);
    }

    private function logException($e, $model = null)
    {
        if($e instanceof ApiException)
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

    public function generateFeeds()
    {
        $this->_feedHelper->generateFeeds();
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
        if(is_null($this->_client)) return false;

        foreach($this->_client as $storeId => $client)
        {
            $orderApi = new OrderApi($client);
            $response = null;

            try
            {
                $response = $orderApi->orderGetNew();
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

            foreach($response->getContent() as $order)
            {
                $billingAddress = $order->getBillingAddress();
                $shippingAddress = $order->getShippingAddress();

                $lines = $order->getLines();

                if(count($lines) == 0 || empty($billingAddress)) continue;

                // Initialize new quote
                $quote = Mage::getModel('sales/quote')->setStoreId($storeId);
            
                foreach($lines as $item)
                {
                    $productNo = $item->getMerchantProductNo();
                    
                    $ids = explode('_', $productNo);
                    $productId = $ids[0];
                    $productOptions = array();
                    if(count($ids) == 3) {
                        $productOptions = array($ids[1] => intval($ids[2]));
                    }

                    // Load magento product
                    $_product = Mage::getModel('catalog/product')->setStoreId($storeId);
                    $_product->load($productId);

                    if(!$_product->getId())
                    {
                        // If the product can't be found by ID, fall back on the SKU.
                        $productId = $_product->getIdBySku($productNo);
                        $_product->load($productId);
                    }

                    // Prepare product parameters for quote
                    $params = new Varien_Object();
                    $params->setQty($item->getQuantity());
                    $params->setOptions($productOptions);

                    // Add product to quote
                    try
                    {
                        $_quoteItem = $quote->addProduct($_product, $params);
                        
                        if(is_string($_quoteItem))
                        {
                            // Magento sometimes returns a string when the method fails. -_-"
                            Mage::throwException('Failed to create quote item: ' . $_quoteItem);
                        }

                        $price = $item->getUnitPriceInclVat();
                        $_quoteItem->setOriginalCustomPrice($price);
                        $_quoteItem->setCustomPrice($price);
                        $_quoteItem->getProduct()->setIsSuperMode(true);
                        $_quoteItem->setChannelengineOrderLineId($item->getChannelProductNo());


                    }
                    catch (Exception $e)
                    {
                        Mage::getModel('adminnotification/inbox')->addCritical(
                            "An order ({$order->getChannelName()} #{$order->getChannelOrderNo()}) could not be imported",
                            "Failed add product to order: #{$productNo}. Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                        );
                        $this->logException($e);
                        continue 2;
                    }
                }

                $phone = $order->getPhone();
                if(empty($phone)) $phone = '-';

                // Prepare billing and shipping addresses
                $billingData = array(
                    'company'       => $billingAddress->getCompanyName(),
                    'firstname'     => $billingAddress->getFirstName(),
                    'lastname'      => $billingAddress->getLastName(),
                    'email'         => $order->getEmail(),
                    'telephone'     => $phone,
                    'country_id'    => $billingAddress->getCountryIso(),
                    'postcode'      => $billingAddress->getZipCode(),
                    'city'          => $billingAddress->getCity(),
                    'street'        =>
                        $billingAddress->getStreetName()."\n".
                        $billingAddress->getHouseNr().
                        $billingAddress->getHouseNrAddition()
                );

                $shippingData = array(
                    'company'       => $shippingAddress->getCompanyName(),
                    'firstname'     => $shippingAddress->getFirstName(),
                    'lastname'      => $shippingAddress->getLastName(),
                    'email'         => $order->getEmail(),
                    'telephone'     => $phone,
                    'country_id'    => $shippingAddress->getCountryIso(),
                    'postcode'      => $shippingAddress->getZipCode(),
                    'city'          => $shippingAddress->getCity(),
                    'street'        =>
                        $shippingAddress->getStreetName()."\n".
                        $shippingAddress->getHouseNr().
                        $shippingAddress->getHouseNrAddition()
                );

                // Register shipping cost. See Tritac_ChannelEngine_Model_Carrier_Channelengine::collectrates();
                Mage::register('channelengine_shipping_amount', floatval($order->getShippingCostsInclVat()));
                // Set this value to make sure ChannelEngine requested the rates and not the frontend
                // because the shipping method has a fallback on 0,- and this will make it show up on the frontend
                Mage::register('channelengine_shipping', true); 

                $quote->getBillingAddress()
                    ->addData($billingData);

                $quote->getShippingAddress()
                    ->addData($shippingData)
                    ->setSaveInAddressBook(0)
                    ->setCollectShippingRates(true)
                    ->setShippingMethod('channelengine_channelengine');

                $quote->collectTotals();

                // Set guest customer
                $quote->setCustomerId(null)
                    ->setCustomerEmail($quote->getBillingAddress()->getEmail())
                    ->setCustomerIsGuest(true)
                    ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);

                // Set custom payment method
                $quote->setIsSystem(true);
                $quote->getPayment()->importData(array('method' => 'channelengine'));

                // Save quote and convert it to new order
                try
                {
                    $quote->save();
                    $service = Mage::getModel('sales/service_quote', $quote);
                    $service->submitAll();
                }
                catch (Exception $e)
                {
                    Mage::getModel('adminnotification/inbox')->addCritical(
                        "An order ({$order->getChannelName()} #{$order->getChannelOrderNo()}) could not be imported",
                        "Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                    );
                    $this->logException($e);
                    continue;
                }

                $magentoOrder = $service->getOrder();

                if(!$magentoOrder->getIncrementId())
                {
                    $this->log("An order (#{$order->getId()}) could not be imported");
                    continue;
                }

                try
                {
                    // Initialize new invoice model
                    $invoice = Mage::getModel('sales/service_order', $magentoOrder)->prepareInvoice();
                    // Add comment to invoice
                    $invoice->addComment(
                        "Order paid on the marketplace.",
                        false,
                        true
                    );

                    // Register invoice. Register invoice items. Collect invoice totals.
                    $invoice->register();
                    $invoice->getOrder()->setIsInProcess(true);

                    $os = $order->getChannelOrderSupport();
                    $canShipPartiallyItem = ($os == MerchantOrderResponse::CHANNEL_ORDER_SUPPORT_SPLIT_ORDER_LINES);
                    $canShipPartially = ($canShipPartiallyItem || $os == MerchantOrderResponse::CHANNEL_ORDER_SUPPORT_SPLIT_ORDERS);

                    // Initialize new channel order
                    $_channelOrder = Mage::getModel('channelengine/order');
                    $_channelOrder->setOrderId($magentoOrder->getId())
                        ->setChannelOrderId($order->getChannelOrderNo())
                        ->setChannelName($order->getChannelName())
                        ->setCanShipPartial($canShipPartially);

                    $invoice->getOrder()
                        ->setCanShipPartiallyItem($canShipPartiallyItem)
                        ->setCanShipPartially($canShipPartially);

                    // Start new transaction
                    $transactionSave = Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder())
                        ->addObject($_channelOrder);
                    $transactionSave->save();
                }
                catch (Exception $e)
                {
                    Mage::getModel('adminnotification/inbox')->addCritical(
                        "An invoice could not be created (order #{$magentoOrder->getIncrementId()}, {$order->getChannelName()} #{$order->getChannelOrderNo()})",
                        "Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                    );

                    $this->logException($e);
                    continue;
                }


                try
                {
                    // Send order acknowledgement to CE.
                    $ack = new OrderAcknowledgement();
                    $ack->setMerchantOrderNo($magentoOrder->getId());
                    $ack->setOrderId($order->getId());
                    $response = $orderApi->orderAcknowledge($ack);

                    if(!$response->getSuccess())
                    {
                        $this->logApiError($response, $ack);
                        continue;
                    }
                }
                catch(Exception $e) 
                {
                    $this->logException($e);
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
        $errorMessage = "Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792";

        // Check if the API client was initialized for this order
        if(!isset($this->_client[$storeId])) return false;

        $shipmentApi = new ShipmentApi($this->_client[$storeId]);

        // Initialize new ChannelEngine shipment object
        $ceShipment = new MerchantShipmentRequest();
        $ceShipment->setMerchantOrderNo($_order->getId());
        $ceShipment->setMerchantShipmentNo($_shipment->getId());

        // Set tracking info if available
        $trackingCodes = $_shipment->getAllTracks();

        if(count($trackingCodes) > 0)
        {
            $trackingCode = $trackingCodes[0];
            $ceShipment->setTrackTraceNo($trackingCode->getNumber());
            $ceShipment->setMethod(($trackingCode->getCarrierCode() == 'custom') ? $trackingCode->getTitle() : $trackingCode->getCarrierCode());      
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
                    Mage::getModel('adminnotification/inbox')->addCritical($errorTitle, $errorMessage);
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
        $ceShipmentLines = [];
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
                Mage::getModel('adminnotification/inbox')->addCritical($errorTitle, $errorMessage);
                return false;
            }

            $_channelShipment = Mage::getModel('channelengine/shipment')->setShipmentId($_shipment->getId());
            $_channelShipment->save();
        }
        catch(Exception $e)
        {
            $this->logException($e);
             Mage::getModel('adminnotification/inbox')->addCritical($errorTitle, $errorMessage);
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

        foreach($this->_client as $storeId => $client)
        {
            $returnApi = new ReturnApi($client);
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

                // Check if notification is already exist
                $_resource  = Mage::getSingleton('core/resource');
                $_connectionRead = $_resource->getConnection('core_read');
                $select = $_connectionRead->select()
                    ->from($_resource->getTableName('adminnotification/inbox'))
                    ->where('title = ?', $title)
                    ->where('is_remove != 1')
                    ->limit(1);
                $data = $_connectionRead->fetchRow($select);

                if ($data) continue;

                // Add new notification
                Mage::getModel('adminnotification/inbox')->addCritical($title, $message);
            }
        }
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
            'channel_order_table.order_id=main_table.entity_id',
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
                'header'=> Mage::helper('sales')->__('ChannelEngine Order ID'),
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
