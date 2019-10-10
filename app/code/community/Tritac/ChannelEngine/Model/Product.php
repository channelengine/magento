<?php

use ChannelEngine\Merchant\ApiClient\Model\MerchantOrderResponse;

class Tritac_ChannelEngine_Model_Product extends Tritac_ChannelEngine_Model_BaseCe
{


    /**
     * @param $mpn
     * @return array
     */
    public function parseMerchantProductNo($mpn)
    {
        $ids = explode('_', $mpn);
        $result = [
            'product_id' => $ids[0]
        ];

        if(count($ids) == 3)
        {
            $result['option_id'] = $ids[1];
            $result['option_value_id'] = $ids[2];
        }

        return $result;
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
     * @param $setShipped
     * @return bool
     */
    public function processOrder($magentoOrder, $order, $setShipped)
    {

        try {
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
            $order_id = $magentoOrder->getId();

            $_channelOrder->setOrderId($order_id)
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

            if ($setShipped) {
                $this->setOrderToShipped($magentoOrder);
            }
            return true;
        } catch (Exception $e) {
            $this->addAdminNotification(
                "An invoice could not be created (order #{$magentoOrder->getIncrementId()}, {$order->getChannelName()} #{$order->getChannelOrderNo()})",
                "Reason: {$e->getMessage()} Please contact ChannelEngine support at support@channelengine.com"
            );

            $this->logException($e);
            return false;
        }
    }


}
