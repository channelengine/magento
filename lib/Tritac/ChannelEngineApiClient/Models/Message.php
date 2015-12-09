<?php
class Tritac_ChannelEngineApiClient_Models_Message extends Tritac_ChannelEngineApiClient_Models_BaseModel {

    public static $typeMap = array(

    );

    protected $message;

    function setMessage($message) { $this->message = $message; }
    function getMessage() { return $this->message; }
}