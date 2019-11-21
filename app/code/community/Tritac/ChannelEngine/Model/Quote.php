<?php

use ChannelEngine\Merchant\ApiClient\Model\MerchantOrderResponse;

class Tritac_ChannelEngine_Model_Quote extends Tritac_ChannelEngine_Model_BaseCe
{
    private $_helper;

    public function __construct()
    {
        $this->_helper = Mage::helper('channelengine');
    }

    public function prepareQuoteOrder($lines, $product, $storeId, $order, $quote, $isFulfillmentByMarketplace)
    {
        // Prevent marketplace fulfilled orders from affecting stock 
        if ($isFulfillmentByMarketplace) $quote->setInventoryProcessed(true);

        $quote->setIsSuperMode(true);

        foreach ($lines as $item) {

            $_product = Mage::getModel('catalog/product')->setStoreId($storeId);
            $productOptions = array();
            $productId = null;
            $mpn = $item->getMerchantProductNo();

            if($this->_helper->useSkuInsteadOfId($storeId))
            {
                $productId = $_product->getIdBySku($mpn);
            }
            else
            {
                $parsedMpn = $product->parseMerchantProductNo($mpn);
                $productId = $parsedMpn['id'];
                if (isset($parsedMpn['option_id'])) $productOptions = array($parsedMpn['option_id'] => intval($parsedMpn['option_value_id']));
            }

            // Load magento product
            $_product->load($productId);

            // Disable vat
            if ($this->_helper->disableMagentoVatCalculation($storeId)) $_product->setTaxClassId(0);

            // Prepare product parameters for quote
            $params = new Varien_Object();
            $params->setQty($item->getQuantity());
            $params->setOptions($productOptions);

            $result = $this->addProductToQuote($_product, $quote, $params, $item, $order, $mpn);

            if(!$result) return false;
        }
        return true;

    }


    /**
     * @param $quote
     * @param $customer
     * @param $order
     * @return array
     */
    public function processCustomerData($quote, $customer, $order)
    {
        $quote->setTotalsCollectedFlag(true);

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
        try {
            $quote->save();
            $service = Mage::getModel('sales/service_quote', $quote);
            $service->submitAll();
            return [
                'status' => true,
                'service' => $service
            ];

        } catch (Exception $e) {
            $this->addAdminNotification(
                "An order ({$order->getChannelName()} #{$order->getChannelOrderNo()}) could not be imported",
                "Reason: {$e->getMessage()} Please contact ChannelEngine support at support@channelengine.com"
            );
            $this->logException($e);
            return [
                'status' => false
            ];
        }
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
    public function addProductToQuote($_product, $quote, $params, $item, $order, $productNo)
    {
        try {
            if (!$_product->getId()) {
                Mage::throwException('Cannot find product: ' . $productNo);
            }

            $_quoteItem = $quote->addProduct($_product, $params);
            if (is_string($_quoteItem)) {
                // Magento sometimes returns a string when the method fails. -_-"
                Mage::throwException('Failed to create quote item: ' . $_quoteItem);
            }
            $price = $item->getUnitPriceInclVat();
            $_quoteItem->setOriginalCustomPrice($price);
            $_quoteItem->setCustomPrice($price);
            $_quoteItem->getProduct()->setIsSuperMode(true);
            return true;

        } catch (Exception $e) {
            $this->logException($e);
            $this->addAdminNotification("An order ({$order->getChannelName()} #{$order->getChannelOrderNo()}) could not be imported",
                "Failed add product to order: '{$productNo}'. Reason: {$e->getMessage()} Please contact ChannelEngine support at support@channelengine.com");
            return false;
        }
    }


}
