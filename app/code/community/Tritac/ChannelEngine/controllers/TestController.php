<?php
/**
 * Test Controller
 */
class Tritac_ChannelEngine_TestController extends Mage_Core_Controller_Front_Action {

    /**
     * Index action
     */
    public function indexAction(){

        $apiKey = Mage::getStoreConfig('channelengine/general/api_key');
        $apiSecret = Mage::getStoreConfig('channelengine/general/api_secret');

        $this->client = new Tritac_ChannelEngineApiClient_Client($apiKey, $apiSecret, 'plugindev');

        $orders = $this->client->getOrders(array(Tritac_ChannelEngineApiClient_Enums_OrderStatus::IN_PROGRESS));

        if(!is_null($orders))
        {
            foreach($orders as $order)
            {
                $billingAddress = $order->getBillingAddress();
                if(empty($billingAddress)) continue;

                $quote = Mage::getModel('sales/quote')->setStoreId(Mage::app()->getStore()->getId());

                $lines = $order->getLines();
                if(!empty($lines)){
                    foreach($lines as $item){
                        $_product = Mage::getModel('catalog/product')->setStoreId(Mage::app()->getStore()->getId());
                        $productId = $_product->getIdBySku($item->getProductEan());
                        $_product->load($productId);
                        $params = new Varien_Object();
                        $params->setQty($item->getQuantity());
                        try {
                            $quote->addProduct($_product, $params);
                        } catch (Mage_Core_Exception $e) {
                            echo $e->getMessage();
                        } catch (Exception $e) {
                            echo $e->getMessage();
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

                $quote->getPayment()->importData(array('method' => 'checkmo'));
                $quote->getShippingAddress()
                    ->setShippingMethod('freeshipping_freeshipping')
                    ->setCollectShippingRates(true)
                    ->collectTotals();


                try {

                    $quote->save();

                    $service = Mage::getModel('sales/service_quote', $quote);
                    $service->submitAll();

                } catch (Mage_Core_Exception $e) {
                    echo $e->getMessage();
                } catch (Exception $e) {
                    echo $e->getMessage();
                    Mage::logException($e);
                }

                $_order = $service->getOrder();
                var_export($_order->getIncrementId());
            }
        }
    }
}