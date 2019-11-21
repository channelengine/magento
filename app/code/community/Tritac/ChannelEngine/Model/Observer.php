<?php
/**
 * Observer model
 */

use ChannelEngine\Merchant\ApiClient\Configuration;
use ChannelEngine\Merchant\ApiClient\ApiException;
use ChannelEngine\Merchant\ApiClient\Api\OrderApi;
use ChannelEngine\Merchant\ApiClient\Api\ShipmentApi;
use ChannelEngine\Merchant\ApiClient\Api\ReturnApi;
use ChannelEngine\Merchant\ApiClient\Model\MerchantOrderResponse;
use ChannelEngine\Merchant\ApiClient\Model\MerchantOrderAcknowledgementRequest;
use ChannelEngine\Merchant\ApiClient\Model\MerchantShipmentRequest;
use ChannelEngine\Merchant\ApiClient\Model\MerchantShipmentTrackingRequest;
use ChannelEngine\Merchant\ApiClient\Model\MerchantShipmentLineRequest;
use ChannelEngine\Merchant\ApiClient\Model\MerchantCancellationRequest;
use \ChannelEngine\Merchant\ApiClient\Model\MerchantCancellationLineRequest;
use ChannelEngine\Merchant\ApiClient\Api\CancellationApi;

class Tritac_ChannelEngine_Model_Observer extends Tritac_ChannelEngine_Model_BaseCe
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
        foreach ($this->_config as $storeId => $storeConfig) {
            if ($this->_helper->isConnected($storeId)) {
                $apiConfig = new Configuration();
                $apiConfig->setApiKey('apikey', $storeConfig['general']['api_key']);
                $apiConfig->setHost('http://' . $storeConfig['general']['tenant'] . '.channelengine.local/api');
                //$apiConfig->setHost('https://' . $storeConfig['general']['tenant'] . '.channelengine.net/api');
                $this->_client['orders'][$storeId] = new OrderApi(null, $apiConfig);
                $this->_client['returns'][$storeId] = new ReturnApi(null, $apiConfig);
                $this->_client['shipment'][$storeId] = new ShipmentApi(null, $apiConfig);
                $this->_client['cancellation'][$storeId] = new CancellationApi(null, $apiConfig);

            }
        }
    }

    /**
     * Generates all product feeds
     * Ran by cron. The cronjob is set in extension config file.
     */
    public function generateFeeds()
    {
        $this->_feedHelper->generateFeeds();
    }

    /**
     * Fetches new merchant fulfilled orders from ChannelEngine.
     * Ran by cron. The cronjob is set in extension config file.
     * @return bool
     */
    public function fetchNewOrders()
    {
        if (is_null($this->_client)) return false;

        foreach ($this->_client['orders'] as $storeId => $client) {

            if (!$this->_helper->isOrderImportEnabled($storeId)) continue;

            $this->fetchNewOrdersForStore($storeId, $client);
        }

        return true;
    }

    private function fetchNewOrdersForStore($storeId, $client)
    {
        $orders = [];

        try
        {
            $response = $client->orderGetNew();
            $orders = $response->getContent();
        }
        catch(Exception $e)
        {
            $this->logException($e);
            return;
        }

        foreach ($orders as $order)
        {
            try
            {
                $magentoOrder = $this->createMagentoOrderForStore($storeId, $order, false);
                if(is_null($magentoOrder)) continue;

                $acknowledgement = new MerchantOrderAcknowledgementRequest();
                $acknowledgement->setMerchantOrderNo($magentoOrder->getId());
                $acknowledgement->setOrderId($order->getId());
                $response = $client->orderAcknowledge($acknowledgement);
            }
            catch(Exception $e)
            {
                $this->logException($e);
            }
        }
    }

    /**
     * Fetches the marketplace fulfilled orders (LVB, FBA, FBC, etc.)
     * Ran by cron. The cronjob is set in extension config file.
     * @return bool
     */
    public function fetchMarketplaceFulfilledOrders()
    {
        if (is_null($this->_client)) return false;

        foreach ($this->_client['orders'] as $storeId => $client) {

            if (!$this->_helper->isMarketplaceFulfilledOrderImportEnabled($storeId)) continue;

            $this->fetchMarketplaceFulfilledOrdersForStore($storeId, $client);
        }

        return true;
    }

    private function fetchMarketplaceFulfilledOrdersForStore($storeId, $client)
    {
        $fromDate = date('Y-m-d', strtotime('-100 days')) . ' 00:00:00';
        $toDate = date('Y-m-d') . ' 23:59:59';
        $page = 1;

        $orders = [];

        try
        {
            $response = $client->orderGetByFilter('SHIPPED', null, $fromDate, $toDate, null, 'ONLY_CHANNEL', $page);
            array_merge($orders, $response->getContent());
            $totalPages = ($response->getTotalCount() + $response->getItemsPerPage() - 1) / $response->getItemsPerPage();

            for($page = 2; $page <= $totalPages; $page++)
            {
                $response = $client->orderGetByFilter('SHIPPED', null, $fromDate, $toDate, null, 'ONLY_CHANNEL', $page);
                array_merge($orders, $response->getContent());
            }
        }
        catch(Exception $e)
        {
            $this->logException($e);
            return;
        }

        foreach ($orders as $order)
        {
            try
            {
                $magentoOrder = $this->createMagentoOrderForStore($storeId, $order, true);
            }
            catch(Exception $e)
            {
                $this->logException($e);
            }
        }

    }

    private function createMagentoOrderForStore($storeId, $order, $isFulfillmentByMarketplace)
    {
        $product = new Tritac_ChannelEngine_Model_Product();
        $productQuote = new Tritac_ChannelEngine_Model_Quote();
        $customer = new Tritac_ChannelEngine_Model_Customer();

        $lines = $order->getLines();
        $billingAddress = $order->getBillingAddress();
        $shippingAddress = $order->getShippingAddress();

        if (count($lines) == 0 || empty($billingAddress)) return;

        // Check if the order has already been imported
        $existingOrder = Mage::getModel('channelengine/order')->loadByChannelOrderId($order->getChannelOrderNo());
        if ($existingOrder->getId()) return null;

        // Initialize new quote
        $quote = Mage::getModel('sales/quote')->setStoreId($storeId);
        $prepare_quote = $productQuote->prepareQuoteOrder($lines, $product, $storeId, $order, $quote, $isFulfillmentByMarketplace);
        if (!$prepare_quote) return null;

        $customer->setBillingData($billingAddress, $order);
        $customer->setShippingData($shippingAddress, $order);

        // Register shipping cost. See Tritac_ChannelEngine_Model_Carrier_Channelengine::collectrates();
        Mage::register('channelengine_shipping_amount', floatval($order->getShippingCostsInclVat()));
        // Set this value to make sure ChannelEngine requested the rates and not the frontend
        // because the shipping method has a fallback on 0,- and this will make it show up on the frontend
        Mage::register('channelengine_shipping', true);

        $product_data = $productQuote->processCustomerData($quote, $customer, $order);
        if (!$product_data['status']) return null;

        $service = $product_data['service'];
        $magentoOrder = $service->getOrder();
        $product->processOrder($magentoOrder, $order, $isFulfillmentByMarketplace);

        return $magentoOrder;
    }

    /**
     * Post new shipment to ChannelEngine. This function is set in extension config file.
     * Triggered by events. The event is set in extension config file.
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

        if (!$ceOrder || $ceOrder->getId() == null) return true;

        $errorTitle = "A shipment (#{$_shipment->getId()}) could not be updated";
        $errorMessage = "Please contact ChannelEngine support at support@channelengine.com";
        // Check if the API client was initialized for this order
        if (!isset($this->_client['shipment'][$storeId])) return false;

        $shipmentApi = $this->_client['shipment'][$storeId];

        // Initialize new ChannelEngine shipment object
        $ceShipment = new MerchantShipmentRequest();
        $ceShipment->setMerchantOrderNo($_order->getId());
        $ceShipment->setMerchantShipmentNo($_shipment->getId());

        // Set tracking info if available
        $trackingCodes = $_shipment->getAllTracks();

        if (count($trackingCodes) > 0) {
            // CE supports one tracking code per shipment. When a shipment has multiple codes, take the first one.
            $trackingCode = $trackingCodes[0];
            $carrierCode = $trackingCode->getCarrierCode();
            $title = $trackingCode->getTitle();

            $ceShipment->setTrackTraceNo($trackingCode->getNumber());
            $ceShipment->setMethod(($carrierCode == 'custom' || $carrierCode == 'paazl') ? $title : $carrierCode);
        }

        // Post NL support, in case of a leter box parcel, we can safely omit the tracking code.
        if ($this->_hasPostNL) {
            $postnlShipment = Mage::getModel('postnl_core/shipment')->load($_shipment->getId(), 'shipment_id');
            if ($postnlShipment->getId() != null && $postnlShipment->getIsBuspakje()) {
                $ceShipment->setMethod('Briefpost');
            }
        }

        // If the shipment is already known to ChannelEngine we will just update it
        $_channelShipment = Mage::getModel('channelengine/shipment')->loadByShipmentId($_shipment->getId());

        if ($_channelShipment->getId() != null) {
            $ceShipmentUpdate = new MerchantShipmentTrackingRequest();
            $ceShipmentUpdate->setTrackTraceNo($ceShipment->getTrackTraceNo());
            $ceShipmentUpdate->setMethod($ceShipment->getMethod());

            try {
                $response = $shipmentApi->shipmentUpdate($_shipment->getId(), $ceShipmentUpdate);
                if (!$response->getSuccess()) {
                    $this->logApiError($response, $ceShipmentUpdate);
                    $this->addAdminNotification($errorTitle, $errorMessage);
                    return false;
                }
            } catch (Exception $e) {
                $this->logException($e);
                return false;
            }

            return true;
        }

        // Add the shipment lines
        $ceShipmentLines = array();
        foreach ($_shipment->getAllItems() as $_shipmentItem) {

            // Get the quantity for this shipment
            $shippedQty = (int)$_shipmentItem->getQty();
            if ($shippedQty == 0) continue;

            // Get the original order item
            $_orderItem = Mage::getModel('sales/order_item')->load($_shipmentItem->getOrderItemId());
            if ($_orderItem == null) continue;

            // Only one option per product is supported by CE
            $productOption = null;
            $_productOptions = $_orderItem->getProductOptions();
            if(isset($_productOptions['options']) && count($_productOptions['options']) > 0) $productOption = $_productOptions['options'][0];

            $ceShipmentLine = new MerchantShipmentLineRequest();

            if($this->_helper->useSkuInsteadOfId($storeId))
            {
                $ceShipmentLine->setMerchantProductNo($_shipmentItem->getSku());
            }
            else
            {
                $id = $_shipmentItem->getProductId();
                if(!is_null($productOption)) $id .= '_' . $productOption['option_id'] . '_' . $productOption['option_value'];
                $ceShipmentLine->setMerchantProductNo($id);
            }

            $ceShipmentLine->setQuantity($shippedQty);
            $ceShipmentLines[] = $ceShipmentLine;
        }

        // Check if there are any shipment lines
        if (count($ceShipmentLines) == 0) return false;

        $ceShipment->setLines($ceShipmentLines);

        // Post shipment to ChannelEngine
        try {
            $response = $shipmentApi->shipmentCreate($ceShipment);
            if (!$response->getSuccess()) {
                $this->logApiError($response, $ceShipment);
                $this->addAdminNotification($errorTitle, $errorMessage);
                return false;
            }

            $_channelShipment = Mage::getModel('channelengine/shipment')->setShipmentId($_shipment->getId());
            $_channelShipment->save();
        } catch (Exception $e) {
            $this->logException($e);
            $this->addAdminNotification($errorTitle, $errorMessage);
            return false;
        }

        return true;
    }

    /**
     * Creates a ChannelEngine cancellation for a credited order
     * Triggered by events. The event is set in extension config file.
     * @param Varien_Event_Observer $observer
     */
    public function creditCancellation(Varien_Event_Observer $observer)
    {
        $creditMemo = $observer->getEvent()->getCreditmemo();
        $storeId = $creditMemo->getStoreId();
        $order = $creditMemo->getOrder();
        $orderId = $order->getId();

        // Check if the API is connected for this store
        $clients = $this->_client['cancellation'];
        if(!isset($clients[$storeId])) return;

        $client = $clients[$storeId];

        $ceOrder = Mage::getModel('channelengine/order')->loadByOrderId($orderId);
        if (!$ceOrder || $ceOrder->getId() == null) return true;

        $_creditItems = $creditMemo->getAllItems();


        $ceCancellationLines = [];
        foreach ($_creditItems as $_creditItem)
        {
            // Get the original order item
            $_orderItem = Mage::getModel('sales/order_item')->load($_creditItem->getOrderItemId());
            if ($_orderItem == null) continue;

            // Only one option per product is supported by CE
            $productOption = null;
            $_productOptions = $_orderItem->getProductOptions();
            if(isset($_productOptions['options']) && count($_productOptions['options']) > 0) $productOption = $_productOptions['options'][0];

            $ceCancellationLine = new MerchantCancellationLineRequest();

            if($this->_helper->useSkuInsteadOfId($storeId))
            {
                $ceCancellationLine->setMerchantProductNo($_creditItem->getSku());
            }
            else
            {
                $id = $_creditItem->getProductId();
                if(!is_null($productOption)) $id .= '_' . $productOption['option_id'] . '_' . $productOption['option_value'];
                $ceCancellationLine->setMerchantProductNo($id);
            }

            $ceCancellationLine->setQuantity($_creditItem->getQty());
            $ceCancellationLines[] = $ceCancellationLine;
        }

        $cancellation = new MerchantCancellationRequest();
        $cancellation->setMerchantCancellationNo($creditMemo->getId());
        $cancellation->setMerchantOrderNo($orderId);
        $cancellation->setLines($ceCancellationLines);

        try
        {
            $client->cancellationCreate($cancellation);
        }
        catch (\Exception $e)
        {
            $this->logException($e);

        }

    }

    /**
     * Fetch new returns from channelengine
     * Ran by cron. The cronjob is set in extension config file.
     * @return bool
     */
    public function fetchReturns()
    {
        if (is_null($this->_client)) return false;

        foreach ($this->_client['returns'] as $storeId => $client) {
            $returnApi =& $client;
            $lastUpdatedAt = new DateTime('-1 day');

            $response = null;

            try {
                $response = $returnApi->returnGetDeclaredByChannel($lastUpdatedAt);
                if (!$response->getSuccess()) {
                    $this->logApiError($response);
                    continue;
                }
            } catch (Exception $e) {
                $this->logException($e);
                continue;
            }


            if ($response->getCount() == 0) continue;

            foreach ($response->getContent() as $return) {
                //$_channelOrder = Mage::getModel('channelengine/order')->loadByChannelOrderId($return->getOrderId());
                $_order = Mage::getModel('sales/order')->load($return->getMerchantOrderNo());

                if (!$_order->getIncrementId()) continue;

                $link = "https://" . $this->_config[$storeId]['general']['tenant'] . ".channelengine.net/returns";
                $title = "A new return was declared in ChannelEngine for order #" . $_order->getIncrementId();
                $message = "Magento Order #<a href='" .
                    Mage::helper('adminhtml')->getUrl('adminhtml/sales_order/view', array('order_id' => $_order->getId())) .
                    "'>" .
                    $_order->getIncrementId() .
                    "</a><br />";
                $message .= "Comment: {$return->getCustomerComment()}<br />";
                $message .= "Reason: {$return->getReason()}<br />";
                $message .= "For more details visit ChannelEngine your <a href='" . $link . "' target='_blank'>account</a>";

                $this->addAdminNotification($title, $message);
            }
        }

        return true;
    }



    /**
     * Add channelengine order fields to adminhtml order grid
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
                'header'=> Mage::helper('sales')->__('Channel Order ID'),
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
