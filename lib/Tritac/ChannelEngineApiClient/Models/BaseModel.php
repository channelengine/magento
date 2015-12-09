<?php
abstract class Tritac_ChannelEngineApiClient_Models_BaseModel {

    protected $id;

    public static $typeMap = array(

    );

    public function getTypeName()
    {
        return get_called_class();
    }

    public function getProperties()
    {
        return get_object_vars($this);
    }

    function setId($id) { $this->id = $id; }
    function getId() { return $this->id; }

}
