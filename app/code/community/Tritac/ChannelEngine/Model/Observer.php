<?php
/**
 * Observer model
 */
class Tritac_ChannelEngine_Model_Observer
{
    /**
     * Fetch new orders from ChannelEngine
     */
    public function fetchNewOrders(){

        $helper = Mage::helper('channelengine');

        // Check required config parameters
        $config = $helper->getConfig();
        if(!$helper->checkConfig()) {
            return false;
        }

        // Initialize new client
        $client = new Tritac_ChannelEngineApiClient_Client(
            $config['api_key'],
            $config['api_secret'],
            $config['tenant']
        );

        // Retrieve new orders
        $orders = $client->getOrders(array(
            Tritac_ChannelEngineApiClient_Enums_OrderStatus::NEW_ORDER
        ));

        if(is_null($orders))
            return false;

        foreach($orders as $order){
            $billingAddress = $order->getBillingAddress();
            if(empty($billingAddress)) continue;

            $quote = Mage::getModel('sales/quote')->setStoreId(Mage::app()->getStore()->getId());

            $lines = $order->getLines();
            if(!empty($lines)){
                foreach($lines as $item){
                    $_product = Mage::getModel('catalog/product')
                        ->setStoreId(Mage::app()->getStore()->getId());
                    $productId = $_product->getIdBySku($item->getProductEan());
                    $_product->load($productId);
                    $params = new Varien_Object();
                    $params->setQty($item->getQuantity());

                    try {
                        $quote->addProduct($_product, $params);

                    } catch (Mage_Core_Exception $e) {
                        Mage::logException($e);

                    } catch (Exception $e) {
                        Mage::logException($e);
                    }
                }
            }

            $billingData = array(
                'firstname'     => $billingAddress->getFirstName(),
                'lastname'      => $billingAddress->getLastName(),
                'email'         => $order->getEmail(),
                'telephone'     => '1234567890',
                'country_id'    => $billingAddress->getCountryIso(),
                'postcode'      => $billingAddress->getZipCode(),
                'city'          => $billingAddress->getCity(),
                'street'        => array(
                    $billingAddress->getStreetName().' '.
                        $billingAddress->getHouseNr().
                        $billingAddress->getHouseNrAddition()
                ),
                'save_in_address_book'  => 0,
                'use_for_shipping'      => 1
            );

            $quote->getBillingAddress()
                ->addData($billingData);
            $quote->getShippingAddress()
                ->addData($billingData);

            $quote->setCustomerId(null)
                ->setCustomerEmail($quote->getBillingAddress()->getEmail())
                ->setCustomerIsGuest(true)
                ->setCustomerGroupId(Mage_Customer_Model_Group::NOT_LOGGED_IN_ID);

            $quote->getPayment()->importData(array('method' => 'channelengine'));
            $quote->getShippingAddress()
                ->setShippingMethod('freeshipping_freeshipping')
                ->setCollectShippingRates(true)
                ->collectTotals();

            try {

                $quote->save();
                $service = Mage::getModel('sales/service_quote', $quote);
                $service->setOrderData(array(
                    'channelengine_order_id' => $order->getChannelOrderNo()
                ));
                $service->submitAll();

            } catch (Mage_Core_Exception $e) {
                Mage::logException($e);
            } catch (Exception $e) {
                Mage::logException($e);
            }

            $_order = $service->getOrder();
            Mage::log("Order #".$_order->getIncrementId().' was imported successfully.');
        }

        return true;
    }
}
