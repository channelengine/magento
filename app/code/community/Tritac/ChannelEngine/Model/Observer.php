<?php
/**
 * Observer model
 */
class Tritac_ChannelEngine_Model_Observer
{

    /**
     * API client
     *
     * @var Tritac_ChannelEngineApiClient_Client
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

    const ATTRIBUTES_LIMIT = 30;

    /**
     * Retrieve and validate API config
     * Initialize API client
     */
    public function __construct()
    {
        $this->_helper = Mage::helper('channelengine');
        $this->_config = $this->_helper->getConfig();
        /**
         * Check required config parameters. Initialize API client.
         */
        foreach($this->_config as $storeId => $storeConfig) {
            if($this->_helper->checkGeneralConfig($storeId)) {
                $this->_client[$storeId] = new Tritac_ChannelEngineApiClient_Client(
                    $storeConfig['general']['api_key'],
                    $storeConfig['general']['api_secret'],
                    $storeConfig['general']['tenant']
                );
            }
        }
    }

    /**
     * Fetch new orders from ChannelEngine.
     * Uses for cronjob. Cronjob is set in extension config file.
     *
     * @return bool
     */
    public function fetchNewOrders()
    {
        /**
         * Check if client is initialized
         */
        if(is_null($this->_client))
            return false;

        foreach($this->_client as $storeId => $_client) {
            /**
             * Retrieve new orders
             */
            $orders = $_client->getOrders(array(
                Tritac_ChannelEngineApiClient_Enums_OrderStatus::NEW_ORDER
            ));

            /**
             * Check new orders existing
             */
            if(is_null($orders) || $orders->count() == 0)
                continue;

            foreach($orders as $order) {

                $billingAddress = $order->getBillingAddress();
                $shippingAddress = $order->getShippingAddress();
                if(empty($billingAddress)) continue;

                $lines = $order->getLines();

                if(!empty($lines)) {

                    // Initialize new quote
                    $quote = Mage::getModel('sales/quote')->setStoreId($storeId);

                    foreach($lines as $item) {

                        $productNo = $item->getMerchantProductNo();
                        $ids = explode('_', $productNo);
                        $productId = $ids[0];
                        // Load magento product
                        $_product = Mage::getModel('catalog/product')
                            ->setStoreId($storeId);
                        $productOptions = array();
                        $_product->load($productId);
                        if(count($ids) == 3) {
                            $productOptions = array($ids[1] => intval($ids[2]));
                        }

                        // Prepare product parameters for quote
                        $params = new Varien_Object();
                        $params->setQty($item->getQuantity());
                        $params->setOptions($productOptions);

                        // Add product to quote
                        try {
                            $_quoteItem = $quote->addProduct($_product, $params);
                            $_quoteItem->setChannelengineOrderLineId($item->getId());

                        } catch (Exception $e) {

                            Mage::getModel('adminnotification/inbox')->addCritical(
                                "An order (#{$order->getId()}) could not be imported",
                                "Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                            );
                            Mage::logException($e);
                            continue 2;
                        }
                    }
                }

                $phone = $order->getPhone();
                if(empty($phone))
                    $phone = '-';
                // Prepare billing and shipping addresses
                $billingData = array(
                    'firstname'     => $billingAddress->getFirstName(),
                    'lastname'      => $billingAddress->getLastName(),
                    'email'         => $order->getEmail(),
                    'telephone'     => $phone,
                    'country_id'    => $billingAddress->getCountryIso(),
                    'postcode'      => $billingAddress->getZipCode(),
                    'city'          => $billingAddress->getCity(),
                    'street'        =>
                        $billingAddress->getStreetName().' '.
                        $billingAddress->getHouseNr().
                        $billingAddress->getHouseNrAddition()
                );
                $shippingData = array(
                    'firstname'     => $shippingAddress->getFirstName(),
                    'lastname'      => $shippingAddress->getLastName(),
                    'email'         => $order->getEmail(),
                    'telephone'     => $phone,
                    'country_id'    => $shippingAddress->getCountryIso(),
                    'postcode'      => $shippingAddress->getZipCode(),
                    'city'          => $shippingAddress->getCity(),
                    'street'        =>
                        $shippingAddress->getStreetName().' '.
                        $shippingAddress->getHouseNr().
                        $shippingAddress->getHouseNrAddition()
                );

                // Register shipping cost. See Tritac_ChannelEngine_Model_Carrier_Channelengine::collectrates();
                if($order->getShippingCostsInclVat() && floatval($order->getShippingCostsInclVat()) > 0) {
                    Mage::register('channelengine_shipping_amount', floatval($order->getShippingCostsInclVat()));
                }

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
                try {

                    $quote->save();

                    $service = Mage::getModel('sales/service_quote', $quote);

                    $service->submitAll();

                } catch (Exception $e) {
                    Mage::getModel('adminnotification/inbox')->addCritical(
                        "An order (#{$order->getId()}) could not be imported",
                        "Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                    );
                    Mage::logException($e);
                    continue;
                }

                $_order = $service->getOrder();


                if($_order->getIncrementId()) {

                    /**
                     * Create new invoice and save channel order
                     */
                    try {
                        // Initialize new invoice model
                        $invoice = Mage::getModel('sales/service_order', $_order)->prepareInvoice();
                        // Add comment to invoice
                        $invoice->addComment(
                            "Order paid on the marketplace.",
                            false,
                            true
                        );

                        // Register invoice. Register invoice items. Collect invoice totals.
                        $invoice->register();
                        $invoice->getOrder()->setIsInProcess(true);

                        // Initialize new channel order
                        $_channelOrder = Mage::getModel('channelengine/order');
                        $_channelOrder->setOrderId($_order->getId())
                            ->setChannelOrderId($order->getId())
                            ->setChannelName($order->getChannelName())
                            ->setDoSendMails($order->getDoSendMails())
                            ->setCanShipPartial($order->getCanShipPartialOrderLines());

                        $invoice->getOrder()
                            ->setCanShipPartiallyItem($order->getCanShipPartialOrderLines())
                            ->setCanShipPartially($order->getCanShipPartialOrderLines());

                        // Start new transaction
                        $transactionSave = Mage::getModel('core/resource_transaction')
                            ->addObject($invoice)
                            ->addObject($invoice->getOrder())
                            ->addObject($_channelOrder);
                        $transactionSave->save();

                    } catch (Exception $e) {
                        Mage::getModel('adminnotification/inbox')->addCritical(
                            "An invoice could not be created (order #{$_order->getIncrementId()}, channel order #{$order->getId()})",
                            "Reason: {$e->getMessage()} Please contact ChannelEngine support at <a href='mailto:support@channelengine.com'>support@channelengine.com</a> or +31(0)71-5288792"
                        );
                        Mage::logException($e);
                        continue;
                    }
                    Mage::log("Order #{$_order->getIncrementId()} was imported successfully.");
                } else {
                    Mage::log("An order (#{$order->getId()}) could not be imported");
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
        $channelOrder = Mage::getModel('channelengine/order')->loadByOrderId($_order->getId());
        $channelOrderId = $channelOrder->getChannelOrderId();

        /**
         * Check ChannelEngine order
         */
        if(!$channelOrderId)
            return false;

        /**
         * Check if client is initialized
         */
        if(!isset($this->_client[$storeId]))
            return false;

        /**
         * Throw new exception if user not added tracking information
         */
        if(!$_shipment->getAllTracks()) {
            Mage::getSingleton('adminhtml/session')->addError(
                $this->_helper->__("Tracking information can not be empty")
            );
            throw new Exception(
                $this->_helper->__("Cannot save shipment without tracking information.")
            );
        }

        foreach($_shipment->getAllTracks() as $_track) {
            // Initialize new ChannelEngine shipment object
            $shipment = new Tritac_ChannelEngineApiClient_Models_Shipment();
            $shipment->setOrderId($channelOrderId);
            $shipment->setMerchantShipmentNo($_shipment->getId());
            $shipment->setTrackTraceNo($_track->getNumber());
            $shipment->setMethod($_track->getTitle());

            // Initialize new ChannelEngine collection of shipments
            $linesCollection = new Tritac_ChannelEngineApiClient_Helpers_Collection('Tritac_ChannelEngineApiClient_Models_ShipmentLine');

            foreach($_order->getAllItems() as $_orderItem) {

                // Load saved order item from db, because current items changed but still not saved
                $_orderItemOrigin = Mage::getModel('sales/order_item')->load($_orderItem->getId());

                // Get shipment item that contains required qty to ship.
                $_shipmentItem = null;
                foreach ($_shipment->getItemsCollection() as $item) {
                    if ($item->getOrderItemId()==$_orderItem->getId()) {
                        $_shipmentItem = $item;
                        break;
                    }
                }

                if(is_null($_shipmentItem)) {
                    continue;
                }

                $qtyToShip = (int) $_shipmentItem->getQty();
                $orderedQty = (int) $_orderItem->getQtyOrdered();
                $shippedQty = (int) $_orderItemOrigin->getQtyShipped();

                // Skip item if all qty already shipped
                if($orderedQty == $shippedQty)
                    continue;

                // If we send a part of an order, post with status IN_BACKORDER
                if($qtyToShip < $orderedQty - $shippedQty) {
                    $shipmentLine = new Tritac_ChannelEngineApiClient_Models_ShipmentLine();
                    // Fill required data
                    $shipmentLine->setShipmentId($_shipment->getId());
                    $shipmentLine->setOrderLineId($_orderItem->getChannelengineOrderLineId());
                    $shipmentLine->setQuantity($orderedQty - $qtyToShip - $shippedQty);
                    $shipmentLine->setStatus(Tritac_ChannelEngineApiClient_Enums_ShipmentLineStatus::IN_BACKORDER);
                    $expectedDate = $this->_helper->getExpectedShipmentDate($storeId);
                    $shipmentLine->setExpectedDate($expectedDate->format('Y-m-d'));
                    $shipmentLines[] = $shipmentLine;
                    // Put shipment line to shipments collection
                    $linesCollection->append($shipmentLine);
                }
                // Initialize new ChannelEngine Shipment Line
                if($qtyToShip > 0) {
                    $shipmentLine = new Tritac_ChannelEngineApiClient_Models_ShipmentLine();
                    // Fill required data
                    $shipmentLine->setShipmentId($_shipment->getId());
                    $shipmentLine->setOrderLineId($_orderItem->getChannelengineOrderLineId());
                    $shipmentLine->setQuantity($qtyToShip);
                    $shipmentLine->setStatus(Tritac_ChannelEngineApiClient_Enums_ShipmentLineStatus::SHIPPED);
                    $shipmentLines[] = $shipmentLine;
                    // Put shipment line to shipments collection
                    $linesCollection->append($shipmentLine);
                }
            }

            $shipment->setLines($linesCollection);
            // Post shipment to ChannelEngine
            $this->_client[$storeId]->postShipment($shipment);

            Mage::log("Shippment #{$_shipment->getId()} was placed successfully.");

            return true;
        }
    }

    /**
     * Fetch new returns from channelengine
     *
     * @return bool
     */
    public function fetchReturns()
    {
        /**
         * Check if client is initialized
         */
        if(is_null($this->_client))
            return false;

        foreach($this->_client as $storeId => $_client) {
            /**
             * Retrieve returns
             */
            $returns = $_client->getReturns(array(
                Tritac_ChannelEngineApiClient_Enums_ReturnStatus::DECLARED
            ));

            /**
             * Check declared returns
             */
            if(is_null($returns) || $returns->count() == 0)
                return false;

            foreach($returns as $return) {
                $_channelOrder = Mage::getModel('channelengine/order')->loadByChannelOrderId($return->getOrderId());
                $_order = Mage::getModel('sales/order')->load($_channelOrder->getOrderId());

                if(!$_order->getIncrementId()) {
                    continue;
                }


                $link       = "https://". $this->_config[$storeId]['general']['tenant'] .".channelengine.net/orders/view/". $return->getOrderId();
                $status     = $return->getStatus(); // Get return status
                $reason     = $return->getReason(); // Get return reason
                $title      = "A new return was declared in ChannelEngine (ChannelEngine Order #{$return->getOrderId()})";
                $message    = "Magento Order #: <a href='".
                    Mage::helper('adminhtml')->getUrl('adminhtml/sales_order/view', array('order_id'=>$_order->getOrderId())).
                    "'>".
                    $_order->getIncrementId().
                    "</a><br />";
                $message   .= "Status: {$status}<br />";
                $message   .= "Reason: {$reason}<br />";
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

                if ($data) {
                    continue;
                }

                // Add new notification
                Mage::getModel('adminnotification/inbox')->addCritical(
                    $title,
                    $message,
                    $link
                );
            }
        }
    }

    /**
     * Generate products feed for ChannelEngine
     */
    public function generateFeed()
    {
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
         * Export products from each store.
         * Note: products with undefined website id will not be export.
         */
        foreach(Mage::app()->getStores() as $_store) {
            Mage::app()->setCurrentStore($_store);
            $path = Mage::getBaseDir('media') . DS . 'channelengine' . DS;
            $storeConfig = $this->_helper->getConfig($_store->getId());
            $name = $storeConfig['general']['tenant'].'_products.xml';
            $file = $path . DS . $name;

            $io = new Varien_Io_File();
            $io->setAllowCreateFolders(true);
            $io->open(array('path' => $path));
            $io->streamOpen($file, 'w+');
            $io->streamLock(true);
            $io->streamWrite('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
            $io->streamWrite('<Products xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n");

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
            if(Mage::helper('catalog/product_flat')->isEnabled($storeId)) {
                Mage::getResourceSingleton('catalog/product_flat')->setStoreId($storeId);
            }
            $collection = Mage::getModel('catalog/product')->getCollection();

            if(Mage::helper('catalog/product_flat')->isEnabled($storeId)) {
                $collection->getEntity()->setStoreId($storeId);
            }

            $systemAttributes = $attributesToSelect =  array('name', 'description', 'image', 'url_key', 'price', 'cost', 'visibility', 'msrp');
            $visibleAttributes = array();
            $attributes = Mage::getSingleton('eav/config')
                ->getEntityType(Mage_Catalog_Model_Product::ENTITY)->getAttributeCollection();

            foreach($attributes as $attribute) {
                if( ($attribute->getIsVisible() && $attribute->getIsVisibleOnFront())
                    || in_array($attribute->getAttributeCode(), $systemAttributes))
                {
                    $code = $attribute->getAttributeCode();
                    $visibleAttributes[$code]['label'] = $attribute->getFrontendLabel();

                    foreach( $attribute->getSource()->getAllOptions(false) as $option ) {
                        $visibleAttributes[$code]['values'][$option['value']] = $option['label'];
                    }
                    if(!in_array($code, $attributesToSelect)) {
                        $attributesToSelect[] = $code;
                    }
                }
            }

            if(!empty($this->_config[$storeId]['feed']['gtin'])) {
                $attributesToSelect[] = $this->_config[$storeId]['feed']['gtin'];
            }

            if( (count($attributesToSelect) > self::ATTRIBUTES_LIMIT) && !$collection->isEnabledFlat()) {
                $error = $this->_helper->__('Too many visible attributes. Please enable catalog product flat mode.');
                Mage::getSingleton('adminhtml/session')->addError($error);
                echo 'redirect';
                return false;
            }

            $collection->addAttributeToSelect($attributesToSelect, 'left')
                ->addFieldToFilter('type_id', 'simple')
                ->addStoreFilter($_store)
                ->addAttributeToFilter('status', 1)
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

            //die();
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

            $io->streamWrite('</Products>');
            $io->streamUnlock();
            $io->streamClose();

            Mage::log("Product feed {$name} was generated successfully");
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
        if(!empty($this->_config[$storeId]['feed']['gtin'])) {
            $product['gtin'] = $product[$this->_config[$storeId]['feed']['gtin']];
        }

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
        $xml .= "<Name><![CDATA[".$product['name']."]]></Name>";
        $xml .= "<Description><![CDATA[".$product['description']."]]></Description>";
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
        if(!empty($this->_config[$product['store_id']]['feed']['vat_rate'])) {
            $vat = $this->_config[$product['store_id']]['feed']['vat_rate'];
            $xml .= "<VAT><![CDATA[".$vat."]]></VAT>";
        }

        $shippingTime = ($product['qty'] > 0) ? $this->_config[$product['store_id']]['feed']['shipping_time'] : $this->_config[$product['store_id']]['feed']['shipping_time_oos'];

        if($shippingTime) {
            $xml .= "<ShippingTime><![CDATA[".$shippingTime."]]></ShippingTime>";
        }

        // Retrieve product url
        $productModel = Mage::getModel('catalog/product');
        $productModel->setData('entity_id', $product['entity_id']);
        $productModel->setData('url_key', $product['url_key']);
        $productModel->setData('store_id', $product['store_id']);
        $url = $productModel->getProductUrl();
        $xml .= "<Url><![CDATA[".$url."]]></Url>";

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

    /**
     * Join channelengine order fields to adminhtml order grid
     *
     * @param $observer
     */
    public function prepareOrderGridCollection($observer)
    {
        $collection = $observer->getOrderGridCollection();
        $joinTableName = Mage::getSingleton('core/resource')->getTableName('channelengine/order');
        $collection->getSelect()->joinLeft(
            array('channel_order_table' => $joinTableName),
            'channel_order_table.order_id=main_table.entity_id',
            array('channel_name', 'channel_order_id')
        );
    }

    /**
     * Add channelengine order fields to adminhtml order grid
     *
     * @param $observer
     * @return $this
     */
    public function appendCustomColumnToOrderGrid($observer)
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
    }
}
