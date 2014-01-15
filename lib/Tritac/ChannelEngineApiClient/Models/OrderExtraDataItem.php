<?php

class Tritac_ChannelEngineApiClient_Models_OrderExtraDataItem extends Tritac_ChannelEngineApiClient_Models_BaseModel{

    public static $typeMap = array(

    );

    protected $key;
    protected $value;


    function setKey($key) { $this->key = $key; }
    function getKey() { return $this->key; }

    function setValue($value) { $this->value = $value; }
    function getValue() { return $this->value; }

}