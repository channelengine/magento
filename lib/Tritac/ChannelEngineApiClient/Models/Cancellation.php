<?php
class Tritac_ChannelEngineApiClient_Models_Cancellation extends Tritac_ChannelEngineApiClient_Models_BaseModel {

    public static $typeMap = array(
        'lines' => 'Tritac_ChannelEngineApiClient_Helpers_Collection(Tritac_ChannelEngineApiClient_Models_CancellationLine)',
    );

    protected $orderId;
    protected $checkoutOrderNo;
    protected $lines;
    protected $cancellationStatus;
    protected $refundInclVat;
    protected $refundExclVat;

    public function __construct()
    {
        self::$typeMap = array_merge(parent::$typeMap, self::$typeMap);

        $this->lines = new Tritac_ChannelEngineApiClient_Helpers_Collection('Tritac_ChannelEngineApiClient_Models_CancellationLine');
    }

    function setOrderId($orderId) { $this->orderId = $orderId; }
    function getOrderId() { return $this->orderId; }

    function setChannelOrderNo($checkoutOrderNo) { $this->checkoutOrderNo = $checkoutOrderNo; }
    function getChannelOrderNo() { return $this->checkoutOrderNo; }

    function setLines($lines) { $this->lines = $lines; }
    function getLines() { return $this->lines; }

    function setCancellationStatus($cancellationStatus) { $this->cancellationStatus = $cancellationStatus; }
    function getCancellationStatus() { return $this->cancellationStatus; }

    function setRefundInclVat($refundInclVat) { $this->refundInclVat = $refundInclVat; }
    function getRefundInclVat() { return $this->refundInclVat; }

    function setRefundExclVat($refundExclVat) { $this->refundExclVat = $refundExclVat; }
    function getRefundExclVat() { return $this->refundExclVat; }
}
