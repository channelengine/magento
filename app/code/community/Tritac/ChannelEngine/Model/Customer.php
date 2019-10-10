<?php

/**
 * Observer model
 */
class Tritac_ChannelEngine_Model_Customer extends Tritac_ChannelEngine_Model_BaseCe
{

    private $billing_data;
    private $shipping_data;

    public function formatPhone($order)
    {
        $phone = $order->getPhone();
        if (empty($phone)) $phone = '-';
        return $phone;
    }

    /**
     * @return mixed
     */
    public function getBillingData()
    {
        return $this->billing_data;
    }

    /**
     * @param mixed $billing_data
     */
    public function setBillingData($billingAddress, $order)
    {
        $this->billing_data = array(
            'company' => $billingAddress->getCompanyName(),
            'firstname' => $billingAddress->getFirstName(),
            'lastname' => $billingAddress->getLastName(),
            'email' => $order->getEmail(),
            'telephone' => $this->formatPhone($order),
            'country_id' => $billingAddress->getCountryIso(),
            'postcode' => $billingAddress->getZipCode(),
            'city' => $billingAddress->getCity(),
            'street' =>
                $billingAddress->getStreetName() . "\n" .
                $billingAddress->getHouseNr() . " " .
                $billingAddress->getHouseNrAddition()
        );
    }

    /**
     * @return mixed
     */
    public function getShippingData()
    {
        return $this->shipping_data;
    }

    /**
     * @param mixed $shipping_data
     */
    public function setShippingData($shippingAddress, $order)
    {
        $this->shipping_data = array(
            'company' => $shippingAddress->getCompanyName(),
            'firstname' => $shippingAddress->getFirstName(),
            'lastname' => $shippingAddress->getLastName(),
            'email' => $order->getEmail(),
            'telephone' => $this->formatPhone($order),
            'country_id' => $shippingAddress->getCountryIso(),
            'postcode' => $shippingAddress->getZipCode(),
            'city' => $shippingAddress->getCity(),
            'street' =>
                $shippingAddress->getStreetName() . "\n" .
                $shippingAddress->getHouseNr() . " " .
                $shippingAddress->getHouseNrAddition()
        );
    }


}
