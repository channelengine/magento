<?php
class Tritac_ChannelEngine_Adminhtml_GenerateController extends Mage_Adminhtml_Controller_Action
{
    public function feedAction()
    {
        $observer = Mage::getModel('channelengine/observer');
        $result = $observer->generateFeed();
        if($result) {
            $this->returnStatus(false, $result);
        } else {
            $this->returnStatus(true, $result);
        }
    }

    public function ordersAction()
    {
        $observer = Mage::getModel('channelengine/observer');
        $result = $observer->fetchNewOrders();
        if($result) {
            $this->returnStatus(false, $result);
        } else {
            $this->returnStatus(true, $result);
        }
    }

    public function returnsAction()
    {
        $observer = Mage::getModel('channelengine/observer');
        $result = $observer->fetchReturns();
        if($result) {
            $this->returnStatus(false, $result);
        } else {
            $this->returnStatus(true, $result);
        }
    }

    private function returnStatus($error, $message)
    {
        $res = $this->getResponse();
        $res->setHeader('Content-type', 'application/json');

        $body = json_encode(array('error' => $error, 'message' => $message));

        $res->setBody($body);
    }
}