<?php

use ChannelEngine\Merchant\ApiClient\Model\MerchantOrderResponse;
class Tritac_ChannelEngine_Model_Product  extends  Tritac_ChannelEngine_Model_BaseCe
{

    /**
     * @param $product_number
     * @return array
     */
    public function generateProductId($product_number)
    {
        $ids = explode('_', $product_number);
        $productId = $ids[0];
        return [
            'product_id'=>$productId,
            'productNo'=>$product_number,
            'ids'=>$ids
        ];
    }

    /**
     * @param $_product
     * @param $productId
     * @param $quote
     * @param $params
     * @param $item
     * @param $order
     * @param $productNo
     * @return bool
     */
    public function addProductToQuote($_product,$productId,$quote,$params,$item,$order,$productNo)
    {
        // Add product to quote
        try
        {
            if(!$_product->getId())
            {
                Mage::throwException('Cannot find product: ' . $productId);

            }

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
            return true;

        }
        catch (Exception $e)
        {



				$this->logException($e);
            $this->addAdminNotification( "An order ({$order->getChannelName()} #{$order->getChannelOrderNo()}) could not be imported",
                "Failed add product to order: #{$productNo}. Reason: {$e->getMessage()} Please contact ChannelEngine support at support@channelengine.com");
            return false;
        }
    }

    /**
     * @param $quote
     * @param $customer
     * @param $order
     * @return array
     */
    public function processCustomerData($quote,$customer,$order)
    {
        $quote->getBillingAddress()
            ->addData($customer->getBillingData());

        $quote->getShippingAddress()
            ->addData($customer->getShippingData())
            ->setSaveInAddressBook(0)
            ->setCollectShippingRates(true)
            ->setShippingMethod('channelengine_channelengine');

        // Set guest customer
        $quote->setCustomerId(null)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);

        // Set custom payment method
        $quote->setIsSystem(true);
        $quote->getPayment()->importData(array('method' => 'channelengine'));
		$quote->setTotalsCollectedFlag(false);
		$quote->collectTotals();

        // Save quote and convert it to new order
        try
        {
            $quote->save();
            $service = Mage::getModel('sales/service_quote', $quote);
            $service->submitAll();
            return [
                'status'=>true,
                'service'=>$service
            ];

        }
        catch (Exception $e)
        {
            $this->addAdminNotification(
                "An order ({$order->getChannelName()} #{$order->getChannelOrderNo()}) could not be imported",
                "Reason: {$e->getMessage()} Please contact ChannelEngine support at support@channelengine.com"
            );
            $this->logException($e);
            return [
                'status'=>false
            ];
        }
    }


    /**
     * Set the order to shopped
     * @param $order
     * @return bool
     */
    protected function setOrderToShipped($order)
    {
        try {
            //START Handle Shipment
            $shipment = $order->prepareShipment();
            $shipment->register();
            $order->setIsInProcess(true);
            Mage::getModel('core/resource_transaction')
                ->addObject($shipment)
                ->addObject($shipment->getOrder())
                ->save();
            return true;
        } catch (\Exception $e) {

            $this->addAdminNotification(
                "An error occured while setting shipment method",
                "Reason: {$e->getMessage()} Please contact ChannelEngine support at support@channelengine.com"
            );
            $this->logException($e);

            return false;
        }



    }

    /**
     * @param $magentoOrder
     * @param $order
     * @return bool]
     */
    public function processOrder($magentoOrder,$order)
    {
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
            $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_OFFLINE);
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
            $this->setOrderToShipped($magentoOrder);


            return true;
        }
        catch (Exception $e) {
            $this->addAdminNotification(
                "An invoice could not be created (order #{$magentoOrder->getIncrementId()}, {$order->getChannelName()} #{$order->getChannelOrderNo()})",
                "Reason: {$e->getMessage()} Please contact ChannelEngine support at support@channelengine.com"
            );

            $this->logException($e);
            return false;
        }
    }







}
