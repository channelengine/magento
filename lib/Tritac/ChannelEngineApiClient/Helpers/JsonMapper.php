<?php
class Tritac_ChannelEngineApiClient_Helpers_JsonMapper {

    public static function fromJson($jsonString, $rootType)
    {
        $jsonRoot = json_decode($jsonString);
        return self::convertToObject($jsonRoot, $rootType);
    }

    private static function convertToObject($jsonObject, $jsonType)
    {
        $rootObject = null;
        if(preg_match('/Collection\(([\\\\\w]+)\)$/', $jsonType))
        {
            $rootObject = new Tritac_ChannelEngineApiClient_Helpers_Collection($jsonType);
            if(is_array($jsonObject))
            {
                foreach ($jsonObject as $jsonChildObject)
                {
                    $rootObject[] = self::convertToObject($jsonChildObject, $rootObject->getTypeName());
                }
            }
        }
        else
        {
            $rootObject = new $jsonType();
            if($jsonObject != null){
                foreach (get_object_vars($jsonObject) as $key => $value)
                {
                    if(array_key_exists(lcfirst($key), $rootObject::$typeMap))
                    {
                        $value = self::convertToObject($value, $rootObject::$typeMap[lcfirst($key)]);
                    }
                    $action = 'set' . $key;
                    if(method_exists($rootObject, $action))
                    {
                         $rootObject->{$action}($value);
                    }
                }
            }
        }
        return $rootObject;
    }

    private static function isIterable($var)
    {
        return ($var !== null && (is_array($var) || $var instanceof Iterator || $var instanceof IteratorAggregate));
    }

    public static function toJson($object)
    {
        return json_encode(self::convertFromObject($object));
    }

    public static function convertFromObject($root, $d = 0, $mustexist = false)
    {
        if($root instanceof Tritac_ChannelEngineApiClient_Helpers_Collection)
        {
            $root = $root->getArrayCopy();
            foreach($root as $key => $value)
            {
                if($value instanceof Tritac_ChannelEngineApiClient_Models_BaseModel || $value instanceof Tritac_ChannelEngineApiClient_Helpers_Collection)
                    $root[ucfirst($key)] = self::convertFromObject($value, $d+1, true);
            }
            return $root;
        }
        elseif($root instanceof Tritac_ChannelEngineApiClient_Models_BaseModel)
        {
            $newRoot = new stdClass();
            foreach($root->getProperties() as $key => $value)
            {
                if($value instanceof Tritac_ChannelEngineApiClient_Models_BaseModel || $value instanceof Tritac_ChannelEngineApiClient_Helpers_Collection)
                {
                    $newRoot->{ucfirst($key)} = self::convertFromObject($value, $d+1, true);
                }
                else
                {
                    $newRoot->{ucfirst($key)} = $value;
                }

            }
            return $newRoot;
        }
        elseif($mustexist)
        {
            throw new Exception('No or invalid object given to the function.');
        }

        return new stdClass();
    }
}