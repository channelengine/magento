<?php

class Tritac_ChannelEngineApiClient_Models_Shipment extends Tritac_ChannelEngineApiClient_Models_BaseModel {

   public static $typeMap = array(
        'lines' => 'Tritac_ChannelEngineApiClient_Helpers_Collection(Tritac_ChannelEngineApiClient_Models_ShipmentLine)',
    );

    protected $orderId;
    protected $createdDate;
    protected $updatedDate;
    protected $trackTraceNo;
    protected $trackTraceUrl;
    protected $method;
    protected $merchantShipmentNo;
    protected $lines;
    protected $refundInclVat;
    protected $refundExclVat;
    protected $status;
    protected $mancoReason;
    protected $mancoComment;

    public function __construct()
    {
        self::$typeMap = array_merge(parent::$typeMap, self::$typeMap);
        $date = new DateTime();
        $this->orderId = null;
        $this->trackTraceNo = '';
        $this->trackTraceUrl = '';
        $this->method = '';
        $this->merchantShipmentNo = '';
        $this->lines = new Tritac_ChannelEngineApiClient_Helpers_Collection('Tritac_ChannelEngineApiClient_Models_ShipmentLine');
        $this->refundInclVat = null;
        $this->refundExclVat = null;
        $this->status = Tritac_ChannelEngineApiClient_Enums_ShipmentStatus::PENDING;
        $this->mancoReason = Tritac_ChannelEngineApiClient_Enums_MancoReason::NOT_IN_STOCK;
        $this->mancoComment = '';

    }

    function setOrderId($orderId) { $this->orderId = $orderId; }
    function getOrderId() { return $this->orderId; }

    function setTrackTraceNo($trackTraceNo) { $this->trackTraceNo = $trackTraceNo; }
    function getTrackTraceNo() { return $this->trackTraceNo; }

    function setTrackTraceUrl($trackTraceUrl) { $this->trackTraceUrl = $trackTraceUrl; }
    function getTrackTraceUrl() { return $this->trackTraceUrl; }

    function setMethod($method) { $this->method = $method; }
    function getMethod() { return $this->method; }

    function setMerchantShipmentNo($merchantShipmentNo) { $this->merchantShipmentNo = $merchantShipmentNo; }
    function getMerchantShipmentNo() { return $this->merchantShipmentNo; }

    function setLines($lines) { $this->lines = $lines; }
    function getLines() { return $this->lines; }

    function setRefundInclVat($refundInclVat) { $this->refundInclVat = $refundInclVat; }
    function getRefundInclVat() { return $this->refundInclVat; }

    function setRefundExclVat($refundExclVat) { $this->refundExclVat = $refundExclVat; }
    function getRefundExclVat() { return $this->refundExclVat; }

    function setStatus($status) { $this->status = $status; }
    function getStatus() { return $this->status; }

    function setMancoReason($mancoReason) { $this->mancoReason = $mancoReason; }
    function getMancoReason() { return $this->mancoReason; }

    function setMancoComment($mancoComment) { $this->mancoComment = $mancoComment; }
    function getMancoComment() { return $this->mancoComment; }
}
