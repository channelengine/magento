<?php
class Tritac_ChannelEngine_Adminhtml_GenerateController extends Mage_Adminhtml_Controller_Action
{
    public function ajaxAction() {

        $observer = Mage::getModel('channelengine/observer');

        if($observer->generateFeed()) {
            $this->getResponse()->setBody(1);
        }
    }
}