<?php
class Tritac_ChannelEngineApiClient_Models_Order extends Tritac_ChannelEngineApiClient_Models_BaseModel {

    public static $typeMap = array(
        'billingAddress' => 'Tritac_ChannelEngineApiClient_Models_Address',
        'cancellations' => 'Tritac_ChannelEngineApiClient_Helpers_Collection(Tritac_ChannelEngineApiClient_Models_Cancellation)',
        'extraData' => 'Tritac_ChannelEngineApiClient_Helpers_Collection(Tritac_ChannelEngineApiClient_Models_OrderExtraDataItem)',
        'shippingAddress' => 'Tritac_ChannelEngineApiClient_Models_Address',
        'lines' => 'Tritac_ChannelEngineApiClient_Helpers_Collection(Tritac_ChannelEngineApiClient_Models_OrderLine)',
        'shipments' => 'Tritac_ChannelEngineApiClient_Helpers_Collection(Tritac_ChannelEngineApiClient_Models_Shipment)',
    );

    protected $phone;
    protected $email;
    protected $cocNo;
    protected $vatNo;
    protected $paymentMethod;
    protected $orderDate;
    protected $createdDate;
    protected $updatedDate;
    protected $checkoutId;
    protected $checkoutOrderNo;
    protected $checkoutCustomerNo;
    protected $billingAddress;
    protected $cancellations;
    protected $channelName;
    protected $doSendMails;
    protected $canShipPartialOrderLines;

    protected $merchantId;
    protected $checkoutMerchantNo;
    protected $shippingCostsInclVat;
    protected $shippingCostsVat;
    protected $subTotalInclVat;
    protected $subTotalVat;
    protected $totalInclVat;
    protected $totalVat;
    protected $refundInclVat;
    protected $refundExclVat;
    protected $extraData;
    protected $shippingAddress;
    protected $status;
    protected $closedDate;
    protected $lines;
    protected $shipments;
    protected $maxVatRate;

    public function __construct()
    {
        self::$typeMap = array_merge(parent::$typeMap, self::$typeMap);

        $this->lines = new Tritac_ChannelEngineApiClient_Helpers_Collection('Tritac_ChannelEngineApiClient_Models_OrderLine');
    }

    function setPhone($phone) { $this->phone = $phone; }
    function getPhone() { return $this->phone; }

    function setEmail($email) { $this->email = $email; }
    function getEmail() { return $this->email; }

    function setCocNo($cocNo) { $this->cocNo = $cocNo; }
    function getCocNo() { return $this->cocNo; }

    function setVatNo($vatNo) { $this->vatNo = $vatNo; }
    function getVatNo() { return $this->vatNo; }

    function setPaymentMethod($paymentMethod) { $this->paymentMethod = $paymentMethod; }
    function getPaymentMethod() { return $this->paymentMethod; }

    function setOrderDate($orderDate) { $this->orderDate = $orderDate; }
    function getOrderDate() { return $this->orderDate; }

    function setCreatedDate( $createdDate) { $this->createdDate = $createdDate; }
    function getCreatedDate() { return $this->createdDate; }

    function setUpdatedDate( $updatedDate) { $this->updatedDate = $updatedDate; }
    function getUpdatedDate() { return $this->updatedDate; }

    function setChannelId($checkoutId) { $this->checkoutId = $checkoutId; }
    function getChannelId() { return $this->checkoutId; }

    function setChannelOrderNo($checkoutOrderNo) { $this->checkoutOrderNo = $checkoutOrderNo; }
    function getChannelOrderNo() { return $this->checkoutOrderNo; }

    function setChannelCustomerNo($checkoutCustomerNo) { $this->checkoutCustomerNo = $checkoutCustomerNo; }
    function getChannelCustomerNo() { return $this->checkoutCustomerNo; }

    function setBillingAddress(Tritac_ChannelEngineApiClient_Models_Address $billingAddress) { $this->billingAddress = $billingAddress; }
    function getBillingAddress() { return $this->billingAddress; }

    function setCancellations($cancellations) { $this->cancellations = $cancellations; }
    function getCancellations() { return $this->cancellations; }

    function setChannelName($channelName) { $this->channelName = $channelName; }
    function getChannelName() { return $this->channelName; }

    function setDoSendMails($doSendMails) { $this->doSendMails = $doSendMails; }
    function getDoSendMails() { return $this->doSendMails; }

    function setCanShipPartialOrderLines($canShipPartialOrderLines) { $this->canShipPartialOrderLines = $canShipPartialOrderLines; }
    function getCanShipPartialOrderLines() { return $this->canShipPartialOrderLines; }

    function setMerchantId($merchantId) { $this->merchantId = $merchantId; }
    function getMerchantId() { return $this->merchantId; }

    function setChannelMerchantNo($checkoutMerchantNo) { $this->checkoutMerchantNo = $checkoutMerchantNo; }
    function getChannelMerchantNo() { return $this->checkoutMerchantNo; }

    function setShippingCostsInclVat($shippingCostsInclVat) { $this->shippingCostsInclVat = $shippingCostsInclVat; }
    function getShippingCostsInclVat() { return $this->shippingCostsInclVat; }

    function setShippingCostsVat($shippingCostsVat) { $this->shippingCostsVat = $shippingCostsVat; }
    function getShippingCostsVat() { return $this->shippingCostsVat; }

    function setSubTotalInclVat($subTotalInclVat) { $this->subTotalInclVat = $subTotalInclVat; }
    function getSubTotalInclVat() { return $this->subTotalInclVat; }

    function setSubTotalVat($subTotalVat) { $this->subTotalVat = $subTotalVat; }
    function getSubTotalVat() { return $this->subTotalVat; }

    function setTotalInclVat($totalInclVat) { $this->totalInclVat = $totalInclVat; }
    function getTotalInclVat() { return $this->totalInclVat; }

    function setTotalVat($totalVat) { $this->totalVat = $totalVat; }
    function getTotalVat() { return $this->totalVat; }

    function setRefundInclVat($refundInclVat) { $this->refundInclVat = $refundInclVat; }
    function getRefundInclVat() { return $this->refundInclVat; }

    function setRefundExclVat($refundExclVat) { $this->refundExclVat = $refundExclVat; }
    function getRefundExclVat() { return $this->refundExclVat; }

    function setExtraData($extraData) { $this->extraData = $extraData; }
    function getExtraData() { return $this->extraData; }

    function setShippingAddress($shippingAddress) { $this->shippingAddress = $shippingAddress; }
    function getShippingAddress() { return $this->shippingAddress; }

    function setStatus($status) { $this->status = $status; }
    function getStatus() { return $this->status; }

    function setClosedDate( $closedDate) { $this->closedDate = $closedDate; }
    function getClosedDate() { return $this->closedDate; }

    function setLines($lines) { $this->lines = $lines; }
    function getLines() { return $this->lines; }

    function setShipments($shipments) { $this->shipments = $shipments; }
    function getShipments() { return $this->shipments; }

    function setMaxVatRate($maxVatRate) { $this->maxVatRate = $maxVatRate; }
    function getMaxVatRate() { return $this->maxVatRate; }

}