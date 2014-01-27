<?php
/**
 * Observer model
 */
class Tritac_ChannelEngine_Model_Observer
{
    /**
     * Fetch new orders from ChannelEngine.
     * Uses for cronjob. Cronjob is set in extension config file.
     *
     * @return bool
     */
    public function fetchNewOrders()
    {
        $helper = Mage::helper('channelengine');

        /**
         * Check required config parameters
         */
        $config = $helper->getConfig();
        if(!$helper->checkConfig()) {
            return false;
        }

        /**
         * Initialize new client
         */
        $client = new Tritac_ChannelEngineApiClient_Client(
            $config['api_key'],
            $config['api_secret'],
            $config['tenant']
        );

        /**
         * Retrieve new orders
         */
        $orders = $client->getOrders(array(
            Tritac_ChannelEngineApiClient_Enums_OrderStatus::NEW_ORDER
        ));

        /**
         * Check new orders existing
         */
        if(is_null($orders))
            return false;

        foreach($orders as $order) {

            $billingAddress = $order->getBillingAddress();
            $shippingAddress = $order->getShippingAddress();
            if(empty($billingAddress)) continue;

            // Initialize new quote
            $quote = Mage::getModel('sales/quote')->setStoreId(Mage::app()->getStore()->getId());
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

                    } catch (Mage_Core_Exception $e) {
                        Mage::logException($e);

                    } catch (Exception $e) {
                        Mage::logException($e);
                    }
                }
            }

            // Prepare billing and shipping addresses
            $billingData = array(
                'firstname'     => $billingAddress->getFirstName(),
                'lastname'      => $billingAddress->getLastName(),
                'email'         => $order->getEmail(),
                'telephone'     => '-',
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
                'telephone'     => '-',
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
                $service->setOrderData(array(
                    'channelengine_order_id' => $order->getId()
                ));

                $service->submitAll();

            } catch (Mage_Core_Exception $e) {
                Mage::logException($e);
                continue;
            } catch (Exception $e) {
                Mage::logException($e);
                continue;
            }

            $_order = $service->getOrder();

            if($_order->getIncrementId()) {

                /**
                 * Create new invoice
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

                    // Start new transaction
                    $transactionSave = Mage::getModel('core/resource_transaction')
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());
                    $transactionSave->save();

                } catch (Mage_Core_Exception $e) {
                    Mage::logException($e);
                } catch (Exception $e) {
                    Mage::logException($e);
                }
                Mage::log("Order #{$_order->getIncrementId()} was imported successfully.");
            } else {
                Mage::log("Can't import order. ChannelEngine Order Id: {$order->getId()}");
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
        $channelOrderId = $_order->getChannelengineOrderId();
        $helper = Mage::helper('channelengine');

        /**
         * Check required config parameters
         */
        $config = $helper->getConfig();
        if(!$helper->checkConfig()) {
            return false;
        }

        /**
         * Initialize new client
         */
        $client = new Tritac_ChannelEngineApiClient_Client(
            $config['api_key'],
            $config['api_secret'],
            $config['tenant']
        );

        /**
         * Check ChannelEngine order
         */
        if(!$channelOrderId)
            return false;

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
                foreach ($_shipment->getItemsCollection() as $item) {
                    if ($item->getOrderItemId()==$_orderItem->getId()) {
                        $_shipmentItem = $item;
                        break;
                    }
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
                    $expectedDate = new DateTime('+2 weeks');
                    $shipmentLine->setExpectedDate($expectedDate->format('Y-m-d')); // TODO Add multipurpose solution
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
            $client->postShipment($shipment);

            Mage::log("Shippment #{$_shipment->getId()} was placed successfully.");
        }
    }
}
