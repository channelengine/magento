<?php

class Tritac_ChannelEngineApiClient_Models_ReturnObject extends Tritac_ChannelEngineApiClient_Models_BaseModel {

    public static $typeMap = array(
        'lines' => 'Tritac_ChannelEngineApiClient_Helpers_Collection(Tritac_ChannelEngineApiClient_Models_ReturnLine)'
    );

    protected $orderId;
    protected $shipmentId;
    protected $merchantReturnNo;
    protected $createdDate;
    protected $updatedDate;
    protected $status;
    protected $reason;
    protected $comment;
    protected $merchantComment;
    protected $refundInclVat;
    protected $refundExclVat;
    protected $lines;

    public function __construct()
    {
        self::$typeMap = array_merge(parent::$typeMap, self::$typeMap);

        $this->lines = new Tritac_ChannelEngineApiClient_Helpers_Collection('Tritac_ChannelEngineApiClient_Models_ReturnLine');
    }

    function setOrderId($orderId) { $this->orderId = $orderId; }
    function getOrderId() { return $this->orderId; }

    function setShipmentId($shipmentId) { $this->shipmentId = $shipmentId; }
    function getShipmentId() { return $this->shipmentId; }

    function setMerchantReturnNo($merchantReturnNo) { $this->merchantReturnNo = $merchantReturnNo; }
    function getMerchantReturnNo() { return $this->merchantReturnNo; }

    function setCreatedDate( $createdDate) { $this->createdDate = $createdDate; }
    function getCreatedDate() { return $this->createdDate; }

    function setUpdatedDate( $updatedDate) { $this->updatedDate = $updatedDate; }
    function getUpdatedDate() { return $this->updatedDate; }

    function setStatus($status) { $this->status = $status; }
    function getStatus() { return $this->status; }

    function setReason($reason) { $this->reason = $reason; }
    function getReason() { return $this->reason; }

    function setComment($comment) { $this->comment = $comment; }
    function getComment() { return $this->comment; }

    function setMerchantComment($merchantComment) { $this->merchantComment = $merchantComment; }
    function getMerchantComment() { return $this->merchantComment; }

    function setRefundInclVat($refundInclVat) { $this->refundInclVat = $refundInclVat; }
    function getRefundInclVat() { return $this->refundInclVat; }

    function setRefundExclVat($refundExclVat) { $this->refundExclVat = $refundExclVat; }
    function getRefundExclVat() { return $this->refundExclVat; }

    function setLines($lines) { $this->lines = $lines; }
    function getLines() { return $this->lines; }
}
