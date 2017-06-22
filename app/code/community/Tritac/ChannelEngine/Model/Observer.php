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
     * To prevent exceeding the maximum number of allowed mySQL joins 
     * when not using the flat catalog. 
     *
     * @var int
     */
    const ATTRIBUTES_LIMIT = 30;

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
        $this->_hasPostNL = Mage::helper('core')->isModuleEnabled('TIG_PostNL');

        $this->_config = $this->_helper->getConfig();
        /**
         * Check required config parameters. Initialize API client.
         */
        foreach($this->_config as $storeId => $storeConfig) {
            if($this->_helper->checkGeneralConfig($storeId)) {
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
     * Generate products feed for ChannelEngine
     */
    public function generateFeed()
    {
        @set_time_limit(15 * 60);
        $start_memory = memory_get_usage();
        
        /**
         * Prepare categories array
         */
        $categoryArray = array();
        $parent = Mage::app()->getWebsite(true)->getDefaultStore()->getRootCategoryId();
        $category = Mage::getModel('catalog/category');
        if ($category->checkId($parent)) {
            $storeCategories = $category->getCategories($parent, 0, true, true, true);
            foreach($storeCategories as $_category) {
                $categoryArray[$_category->getId()] = $_category->getData();
            }
        }

        /**
         * Prepare products relation
         */
//        $productsRelation = array();
//        $_resource = Mage::getSingleton('core/resource');
//        $_connection = $_resource->getConnection('core_read');
//        $relations = $_connection->fetchAll("SELECT * FROM " . $_resource->getTableName('catalog/product_relation'));
//        foreach($relations as $relation) {
//            $productsRelation[$relation['child_id']] = $relation['parent_id'];
//        }

        /**
         * Export products from each store.
         * Note: products with undefined website id will not be export.
         */
        foreach(Mage::app()->getStores() as $_store)
        {
            Mage::app()->setCurrentStore($_store);           

            $path = Mage::getBaseDir('media') . DS . 'channelengine' . DS;
            $storeConfig = $this->_helper->getConfig($_store->getId());

            if(!$this->_helper->checkGeneralConfig($_store->getId())) continue;

            $name = $storeConfig['general']['tenant'].'_products.xml';
            $file = $path . DS . $name;
            $date = date('c');

            $io = new Varien_Io_File();
            $io->setAllowCreateFolders(true);
            $io->open(array('path' => $path));
            $io->streamOpen($file, 'w+');
            $io->streamLock(true);
            $io->streamWrite('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
            $io->streamWrite('<Products xmlns:xsd="http://www.w3.org/2001/XMLSchema"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" GeneratedAt="'.$date.'">' . "\n");

            /**
             * Prepare custom options array
             */
            $storeId = $_store->getId();
            $optionsArray = array();
            $_options = Mage::getModel('catalog/product_option')
                ->getCollection()
                ->addTitleToResult($storeId)
                ->addPriceToResult($storeId)
                ->addValuesToResult($storeId)
                ->setOrder('sort_order', 'asc');
            foreach($_options as $_option) {
                $productId = $_option->getProductId();
                $optionId = $_option->getOptionId();
                $optionsArray[$productId][$optionId] = $_option->getData();
                if($_option->getType() == Mage_Catalog_Model_Product_Option::OPTION_TYPE_DROP_DOWN) {
                    $optionsArray[$productId][$optionId]['values'] = $_option->getValues();
                }
            }

            /**
             * Retrieve product collection with all visible attributes
             */
            $collection = Mage::getResourceModel('catalog/product_collection');
            $flatCatalogEnabled = $collection->isEnabledFlat();

            // Make sure to create a new instance of our collection after setting the store ID
            // when using the flat catalog. Otherwise store ID will be ignored. This is a bug in magento.
            // https://magento.stackexchange.com/a/25908
            if($flatCatalogEnabled)
            {
                // The flat product entity has a setStoreId method, the regular entity does not have one
                $collection->getEntity()->setStoreId($storeId);
                $collection = Mage::getResourceModel('catalog/product_collection');  
            } 

            $visibleAttributes = array();
            $systemAttributes = array();
            $attributesToSelect = array(
                'sku',
                'name',
                'manufacturer',
                'description',
                'image',
                'url_key',
                'price',
                'cost',
                'special_price',
                'special_from_date',
                'special_to_date',
                'visibility',
                'msrp'
            );

            if(!empty($this->_config[$storeId]['general']['gtin'])) $attributesToSelect[] = $this->_config[$storeId]['general']['gtin'];
            $attributes = Mage::getResourceModel('catalog/product_attribute_collection');

            $totalAttributes = count($attributesToSelect);

            foreach($attributes as $attribute)
            {
                $code = $attribute->getAttributeCode();
                $isFlat = $flatCatalogEnabled && $attribute->getUsedInProductListing();
                $isRegular = !$flatCatalogEnabled && $attribute->getIsVisible() && $attribute->getIsVisibleOnFront();

                // Only allow a subset of system attributes
                $isSystem = !$attribute->getIsUserDefined();

                if(!$isFlat && !$isRegular || ($isRegular && $totalAttributes >= self::ATTRIBUTES_LIMIT)) continue;

                $visibleAttributes[$code]['label'] = $attribute->getFrontendLabel();  
                foreach($attribute->getSource()->getAllOptions(false) as $option)
                {
                    $visibleAttributes[$code]['values'][$option['value']] = $option['label'];
                }

                if($isSystem)
                {
                    $systemAttributes[] = $code;
                    continue;
                }

                if(in_array($code, $attributesToSelect)) continue;

                $attributesToSelect[] = $code;
                $totalAttributes++;
            }

            $collection->addAttributeToSelect($attributesToSelect, 'left')
                ->addFieldToFilter('type_id', array('in' => array('simple')))
                ->addStoreFilter($_store)
                ->addAttributeToFilter('status', 1)
                ->addAttributeToFilter('visibility', array('in' => array(
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_CATALOG,
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_IN_SEARCH,
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)))
                ->addAttributeToSort('entity_id', 'DESC');

            // Add qty and category fields to select
            $collection->getSelect()
                ->joinLeft(
                    array('csi' => Mage::getSingleton('core/resource')->getTableName('cataloginventory/stock_item')),
                    '`e`.`entity_id` = `csi`.`product_id`',
                    array('qty' => 'COALESCE(`qty`, 0)')
                )
                ->joinLeft(
                    array('ccp' => Mage::getSingleton('core/resource')->getTableName('catalog/category_product')),
                    '`e`.`entity_id` = `ccp`.`product_id`',
                    array('category_id' => 'MAX(`ccp`.`category_id`)')
                )
                ->group('e.entity_id');

            Mage::getSingleton('core/resource_iterator')->walk(
                $collection->getSelect(),
                array(array($this, 'callbackGenerateFeed')),
                array(
                    'io'            => $io,
                    'categories'    => $categoryArray,
                    'attributes'    => $visibleAttributes,
                    'systemAttributes' => $systemAttributes,
                    'options'       => $optionsArray,
                    'store'         => $_store,
                    'startMemory'   => $start_memory,
                )
            );

            $collection->clear()->getSelect()->reset('where');
            $collection->addFieldToFilter('type_id', array('in' => array('configurable')))
                ->addStoreFilter($_store)
                ->addAttributeToFilter('status', 1)
                ->addAttributeToSort('entity_id', 'DESC');

            foreach($collection as $_product) {
                $productAttributeOptions = $_product->getTypeInstance(true)->getConfigurableAttributesAsArray($_product);
                $superAttributes = array();

                foreach($productAttributeOptions as $superAttribute) {
                    foreach($superAttribute['values'] as $value) {
                        $superAttributes[$superAttribute['attribute_code']][$value['value_index']] = $value;
                    }
                }

                $parentData = $_product->getData();
                $parentData['id'] = $parentData['entity_id'];

                $productModel = Mage::getModel('catalog/product');
                $productModel->setData('entity_id', $parentData['entity_id']);
                $productModel->setData('url_key', $parentData['url_key']);
                $productModel->setData('store_id', $parentData['store_id']);

                $parentData['url'] = $productModel->getProductUrl();

                $specialPrice = $parentData['special_price'];
                $specialFrom = $parentData['special_from_date'];
                $specialTo = $parentData['special_to_date'];
                $parentData['price'] = Mage::getModel('catalog/product_type_price')->calculateSpecialPrice($parentData['price'], $specialPrice, $specialFrom, $specialTo, $storeId);

                $xml = $this->_getProductXml($parentData, $categoryArray, array('systemAttributes' => $systemAttributes, 'attributes' => $visibleAttributes));

                $childProductCollection = Mage::getModel('catalog/product_type_configurable')
                    ->getUsedProductCollection($_product)
                    ->addAttributeToSelect($attributesToSelect);

                $_childProducts = $childProductCollection->getItems();


                foreach($_childProducts as $_child) {
                    $childData = $_child->getData();
                    
                    $childData['id'] = $childData['entity_id'];
                    $childData['parent_id'] = $parentData['id'];
                    $childData['price'] = $parentData['price'];
                    $childData['url'] = $parentData['url'];
                    $childData['description'] = $parentData['description'];
                    
                    if(isset($childData['stock_item']) && $childData['stock_item'] !== null) {
                        $stock = $childData['stock_item']->getData();
                        $childData['qty'] = $stock['qty'];
                    }

                    if(!isset($childData['image']) || $childData['image'] == 'no_slection') {
                        $childData['image'] = $parentData['image'];
                    }

                    foreach($superAttributes as $code => $superAttribute) {
                        if(isset($childData[$code])) {
                            $priceValue = $superAttribute[$childData[$code]]['pricing_value'];
                            if($superAttribute[$childData[$code]]['is_percent']) {
                                $newPrice = $childData['price'] + $childData['price'] * $priceValue / 100;
                            } else {
                                $newPrice = $childData['price'] + $priceValue;
                            }
                            $childData['price'] = $newPrice;
                        }
                    }

                    $xml .= $this->_getProductXml($childData, $categoryArray, array('systemAttributes' => $systemAttributes, 'attributes' => $visibleAttributes));
                }
                $io->streamWrite($xml);
            }



            $io->streamWrite('</Products>');
            $io->streamUnlock();
            $io->streamClose();
        }

        return true;
    }

    public function callbackGenerateFeed($args)
    {
        $io         = $args['io'];
        $product    = $args['row'];
        $attributes = $args['attributes'];
        $systemAttributes = $args['systemAttributes'];
        $categories = $args['categories'];
        $options    = $args['options'];
        $_store     = $args['store'];
        $storeId    = $_store->getId();

        $xml = '';

        $product['store_id'] = $storeId;
        if(!empty($this->_config[$storeId]['general']['gtin'])) {
            $product['gtin'] = $product[$this->_config[$storeId]['general']['gtin']];
        }

        $specialPrice = $product['special_price'];
        $specialFrom = $product['special_from_date'];
        $specialTo = $product['special_to_date'];
        $product['price'] = Mage::getModel('catalog/product_type_price')
            ->calculateSpecialPrice($product['price'], $specialPrice, $specialFrom, $specialTo, $storeId);

        $productModel = Mage::getModel('catalog/product');
        $productModel->setData('entity_id', $product['entity_id']);
        $productModel->setData('url_key', $product['url_key']);
        $productModel->setData('store_id', $product['store_id']);
        $product['url'] = $productModel->getProductUrl();

        /**
         * Add product custom options to feed.
         * Each option value will generate new product row
         */
        $additional['systemAttributes'] = $systemAttributes;
        $additional['attributes'] = $attributes;
        if(isset($options[$product['entity_id']])) {
            $product['group_code'] = $product['entity_id'];
            foreach($options[$product['entity_id']] as $option) {
                if(isset($option['values'])) {
                    foreach($option['values'] as $_value) {
                        $product['id'] = $product['entity_id'].'_'.$option['option_id'].'_'.$_value->getId();
                        $additional['title'] = str_replace(' ', '_', $option['default_title']);
                        $additional['value'] = $_value->getDefaultTitle();
                        $xml .= $this->_getProductXml($product, $categories, $additional);
                    }
                } else {
                    $product['id'] = $product['entity_id'].'_'.$option['option_id'];
                    $additional['title'] = str_replace(' ', '_', $option['default_title']);
                    $additional['value'] = '';
                    $xml .= $this->_getProductXml($product, $categories, $additional);
                }
            }
        }else {
            $product['id'] = $product['entity_id'];
            $xml .= $this->_getProductXml($product, $categories, $additional);
        }

        $io->streamWrite($xml);
    }

    protected function _getProductXml($product, $categories, $additional = null)
    {
        $xml = "<Product>";
        $xml .= "<Id>".$product['id']."</Id>";

        // Add group code with product id if product have custom options
        if(isset($product['group_code'])) {
            $xml .= "<GroupCode><![CDATA[".$product['group_code']."]]></GroupCode>";
        }

        if(isset($product['parent_id'])) {
            $xml .= "<ParentId><![CDATA[".$product['parent_id']."]]></ParentId>";
        }

        $xml .= "<Type><![CDATA[".$product['type_id']."]]></Type>";
        $xml .= "<Name><![CDATA[".$product['name']."]]></Name>";
        $xml .= "<Description><![CDATA[".strip_tags($product['description'])."]]></Description>";
        $xml .= "<Price><![CDATA[".$product['price']."]]></Price>";
        $xml .= "<ListPrice><![CDATA[".$product['msrp']."]]></ListPrice>";
        $xml .= "<PurchasePrice><![CDATA[".$product['cost']."]]></PurchasePrice>";

        // Add product stock qty
        $xml .= "<Stock><![CDATA[".$product['qty']."]]></Stock>";

        // Add product SKU and GTIN
        $xml .= "<SKU><![CDATA[".$product['sku']."]]></SKU>";
        if(!empty($product['gtin'])) {
            $xml .= "<GTIN><![CDATA[".$product['gtin']."]]></GTIN>";
        }

        // VAT and Shipping Time are pre configured in extension settings
        if(!empty($this->_config[$product['store_id']]['optional']['vat_rate'])) {
            $vat = $this->_config[$product['store_id']]['optional']['vat_rate'];
            $xml .= "<VAT><![CDATA[".$vat."]]></VAT>";
        }

        $shippingTime = ($product['qty'] > 0) ? $this->_config[$product['store_id']]['optional']['shipping_time'] : $this->_config[$product['store_id']]['optional']['shipping_time_oos'];

        if($shippingTime) {
            $xml .= "<ShippingTime><![CDATA[".$shippingTime."]]></ShippingTime>";
        }

        $xml .= "<Url><![CDATA[".$product['url']."]]></Url>";

        if(isset($product['image']) && $product['image'] != 'no_selection') {
            $imgUrl = Mage::getSingleton('catalog/product_media_config')->getMediaUrl($product['image']);
            $xml .= "<ImageUrl><![CDATA[".$imgUrl."]]></ImageUrl>";
        }

        // Prepare category path
        if(!empty($product['category_id']) && !empty($categories)) {
            $categoryId = $product['category_id'];
            $categoryPathIds = explode('/', $categories[$categoryId]['path']);
            $categoryPath = null;
            foreach($categoryPathIds as $id) {
                if($id > 2) {
                    $categoryPath .= ($categoryPath) ? ' > ':'';
                    $categoryPath .= $categories[$id]['name'];
                }
            }
            if($categoryPath) {
                $xml .= "<Category><![CDATA[".$categoryPath."]]></Category>";
            }
        }

        if(isset($additional['title']) && isset($additional['value'])) {
            $title = preg_replace("/[^a-zA-Z0-9]/", "", $additional['title']);
            $xml .= sprintf("<%1\$s><![CDATA[%2\$s]]></%1\$s>",
                $title,
                $additional['value']
            );
        }

        /*
         * Prepare product visible attributes
         */
        if(isset($additional['attributes'])) {
            $xml .= '<Attributes>';
            foreach($additional['attributes'] as $code => $attribute) {

                if(isset($product[$code]) && !in_array($code, $additional['systemAttributes'])) {
                    $xml .= "<".$code.">";
                    /*$xml .= "<label><![CDATA[".$attribute['label']."]]></label>";
                    if(!empty($attribute['values'])) {
                        $xml .= "<value><![CDATA[".$attribute['values'][$product[$code]]."]]></value>";
                    } else {
                        $xml .= "<value><![CDATA[".$product[$code]."]]></value>";
                    }*/
                    if(!empty($attribute['values'])) {
                        $xml .= "<![CDATA[".$attribute['values'][$product[$code]]."]]>";
                    } else {
                        $xml .= "<![CDATA[".$product[$code]."]]>";
                    }
                    $xml .= "</".$code.">";
                }
            }
            $xml .= '</Attributes>';
        }

        $xml .= "</Product>\n";

        return $xml;
    }

    public function addConfigurableProducts($collection)
    {

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
