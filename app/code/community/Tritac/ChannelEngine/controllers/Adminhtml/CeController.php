<?php

class Tritac_ChannelEngine_Adminhtml_CeController extends Mage_Adminhtml_Controller_Action
{
    protected function _isAllowed()
    {
        return Mage::getSingleton('admin/session')->isAllowed('system/config');
    }

    public function feedAction()
    {
        $feed = Mage::helper('channelengine/feed');
        $result = $feed->generateFeeds();
        if ($result) {
            $this->returnStatus(false, $result);
        } else {
            $this->returnStatus(true, $result);
        }
    }

    public function ordersAction()
    {
        $observer = Mage::getModel('channelengine/observer');
        $result = $observer->fetchNewOrders();
        if ($result) {
            $this->returnStatus(false, $result);
        } else {
            $this->returnStatus(true, $result);
        }
    }

    public function fulfillmentordersAction()
    {
        $observer = Mage::getModel('channelengine/observer');
        $result = $observer->fetchFulfilmentOrders();
        if ($result) {
            $this->returnStatus(false, $result);
        } else {
            $this->returnStatus(true, $result);
        }
    }

    public function returnsAction()
    {
        $observer = Mage::getModel('channelengine/observer');
        $result = $observer->fetchReturns();
        if ($result) {
            $this->returnStatus(false, $result);
        } else {
            $this->returnStatus(true, $result);
        }
    }

    public function logAction()
    {
        $logFile = Mage::getBaseDir('log') . '/' . 'channelengine.log';
        if (!is_file($logFile) || !is_readable($logFile)) return;

        $this->getResponse()
            ->setHttpResponseCode(200)
            ->setHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0', true)
            ->setHeader('Pragma', 'public', true)
            ->setHeader('Content-type', 'application/force-download')
            ->setHeader('Content-Length', filesize($logFile))
            ->setHeader('Content-Disposition', 'attachment' . '; filename=' . basename($logFile));

        $this->getResponse()->clearBody();
        $this->getResponse()->sendHeaders();
        readfile($logFile);
    }

    private function returnStatus($error, $message)
    {
        $res = $this->getResponse();
        $res->setHeader('Content-type', 'application/json');

        $body = json_encode(array('error' => $error, 'message' => $message));

        $res->setBody($body);
    }
}