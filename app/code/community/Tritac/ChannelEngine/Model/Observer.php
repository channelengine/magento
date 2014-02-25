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
        if($this->_helper->checkConfig()) {
            $this->_client = new Tritac_ChannelEngineApiClient_Client(
                $this->_config['api_key'],
                $this->_config['api_secret'],
                $this->_config['tenant']
            );
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

        /**
         * Retrieve new orders
         */
        $orders = $this->_client->getOrders(array(
            Tritac_ChannelEngineApiClient_Enums_OrderStatus::NEW_ORDER
        ));

        /**
         * Check new orders existing
         */
        if(is_null($orders) || $orders->count() == 0)
            return false;

        foreach($orders as $order) {

            $billingAddress = $order->getBillingAddress();
            $shippingAddress = $order->getShippingAddress();
            if(empty($billingAddress)) continue;

            // Initialize new quote
            $quote = Mage::getModel('sales/quote')->setStoreId(Mage::app()->getDefaultStoreView()->getStoreId());
            $lines = $order->getLines();

            if(!empty($lines)) {

                foreach($lines as $item) {

                    // Load magento product
                    $_product = Mage::getModel('catalog/product')
                        ->setStoreId(Mage::app()->getStore()->getId());
                    $productId = $_product->getIdBySku($item->getMerchantProductNo());
                    $_product->load($productId);

                    // Prepare product parameters for quote
                    $params = new Varien_Object();
                    $params->setQty($item->getQuantity());

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

        return true;
    }

    /**
     * Post new shipment to ChannelEngine. This function is set in extension config file.
     *
     * @param Varien_Event_Observer $observer
     * @return bool
     */
    public function saveShipment(Varien_Event_Observer $observer)
    {
        $event = $observer->getEvent();
        /** @var $_shipment Mage_Sales_Model_Order_Shipment */
        $_shipment = $event->getShipment();
        /** @var $_order Mage_Sales_Model_Order */
        $_order = $_shipment->getOrder();
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
        if(is_null($this->_client))
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
                    $expectedDate = $this->_helper->getExpectedShipmentDate();
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
            $this->_client->postShipment($shipment);

            Mage::log("Shippment #{$_shipment->getId()} was placed successfully.");

            return true;
        }
    }

    public function fetchReturns()
    {
        /**
         * Check if client is initialized
         */
        if(is_null($this->_client))
            return false;

        /**
         * Retrieve returns
         */
        $returns = $this->_client->getReturns(array(
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

            $status     = $return->getStatus(); // Get return status
            $reason     = $return->getReason(); // Get return reason
            $message    = "Magento Order #: <a href='".
                Mage::helper('adminhtml')->getUrl('adminhtml/sales_order/view', array('order_id'=>$_order->getOrderId())).
                "'>".
                $_order->getIncrementId().
                "</a><br />";
            $message   .= "Status: {$status}<br />";
            $message   .= "Reason: {$reason}<br />";
            $message   .= "For more details visit your ChannelEngine <a href='http://www.channelengine.com' target='_blank'>account</a>";

            if(!empty($message)) {
                Mage::getModel('adminnotification/inbox')->addCritical(
                    "You have new return from ChannelEngine (ChannelEngine Order #{$return->getOrderId()})",
                    $message,
                    'http://www.channelengine.com',
                    false
                );
            }
        }
    }

    /**
     * Generate products feed for ChannelEngine
     */
    public function generateFeed()
    {
        $path = Mage::getBaseDir('media') . DS . 'channelengine' . DS;
        $name = 'channelengine_products.xml';
        $file = $path . DS . $name;

        $io = new Varien_Io_File();
        $io->setAllowCreateFolders(true);
        $io->open(array('path' => $path));
        $io->streamOpen($file, 'w+');
        $io->streamLock(true);
        $io->streamWrite('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $io->streamWrite('<ArrayOfProduct xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n");
        $start_memory = memory_get_usage();

        /**
         * Prepare categories array
         */
        $categoryArray = array();
        $parent     = Mage::app()->getStore()->getRootCategoryId();
        $category = Mage::getModel('catalog/category');
        if ($category->checkId($parent)) {
            $storeCategories = $category->getCategories($parent, 0, true, true, true);
            foreach($storeCategories as $_category) {
                $categoryArray[$_category->getId()] = $_category->getData();
            }
        }

        /**
         * Prepare custom options array
         */
        $optionsArray = array();
        $_options = Mage::getModel('catalog/product_option')
            ->getCollection()
            ->addTitleToResult()
            ->addPriceToResult()
            ->addValuesToResult()
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
         * Retrieve product collection
         */
        $collection = Mage::getModel('catalog/product')->getCollection();
        $collection->addAttributeToSelect(array('name', 'description', 'image', 'url_key', 'price', 'visibility'), 'left');
        $collection->addFieldToFilter('type_id', 'simple');
        // Add qty and category fields
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
                'options'       => $optionsArray,
                'startMemory'   => $start_memory
            )
        );

        $io->streamWrite('</ArrayOfProduct>');
        $io->streamUnlock();
        $io->streamClose();

        Mage::log("Products feed is generated successfully");
    }

    public function callbackGenerateFeed($args)
    {
        $io         = $args['io'];
        $product   = $args['row'];
        $categories = $args['categories'];
        $options    = $args['options'];

        $xml = '';

        if(isset($options[$product['entity_id']])) {
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
            $xml .= $this->_getProductXml($product, $categories);
        }

        $io->streamWrite($xml);
    }

    protected function _getProductXml($product, $categories, $additional = null)
    {
        $xml = "<Product>";
        $xml .= "<Id>".$product['id']."</Id>";
        $xml .= "<Name>".$product['name']."</Name>";
        $xml .= "<Description>".$product['description']."</Description>";
        $xml .= "<Price>".$product['price']."</Price>";
        $xml .= "<ListPrice>".$product['msrp']."</ListPrice>";
        $xml .= "<PurchasePrice>".$product['base_price']."</PurchasePrice>";

        //Retrieve product stock qty
        $xml .= "<Stock>".$product['qty']."</Stock>";
        $xml .= "<SKU>".$product['sku']."</SKU>";
        $xml .= "<Url>".$product['url_key']."</Url>";

        if($product['image'] != 'no_selection') {
            $imgUrl = Mage::getSingleton('catalog/product_media_config')->getMediaUrl($product['image']);
            $xml .= "<ImageUrl>".$imgUrl."</ImageUrl>";
        }

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
                $xml .= "<Category>".$categoryPath."</Category>";
            }
        }

        if(isset($additional['title']) && isset($additional['value'])) {
            $xml .= sprintf("<%1\$s>%2\$s<%1\$s>",
                $additional['title'],
                $additional['value']
            );
        }

        $xml .= "</Product>\n";

        return $xml;
    }
}
