<?php
class Tritac_ChannelEngine_Adminhtml_GenerateController extends Mage_Adminhtml_Controller_Action
{
    public function ajaxAction()
    {
        $observer = Mage::getModel('channelengine/observer');
    	$res = $this->getResponse();
    	$res->setHeader('Content-type', 'application/json');
        if($observer->generateFeed()) $res->setBody(1);
    }

    public function importOrdersAction()
    {
        $observer = Mage::getModel('channelengine/observer');
        $res = $this->getResponse();
        $res->setHeader('Content-type', 'application/json');
        if($observer->fetchNewOrders()) $res->setBody(1);
    }

    public function importReturnsAction()
    {
        $observer = Mage::getModel('channelengine/observer');
        $res = $this->getResponse();
        $res->setHeader('Content-type', 'application/json');
        if($observer->fetchReturns()) $res->setBody(1);
    }
}