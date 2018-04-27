<?php
/*
 * MundiAPILib
 *
 * This file was automatically generated by APIMATIC v2.0 ( https://apimatic.io ).
 */

namespace MundiAPILib\Models;

use JsonSerializable;

/**
 *Request for creating an order
 */
class CreateOrderRequest implements JsonSerializable
{
    /**
     * Items
     * @required
     * @var \MundiAPILib\Models\CreateOrderItemRequest[] $items public property
     */
    public $items;

    /**
     * Customer
     * @required
     * @var \MundiAPILib\Models\CreateCustomerRequest $customer public property
     */
    public $customer;

    /**
     * Payment data
     * @required
     * @var \MundiAPILib\Models\CreatePaymentRequest[] $payments public property
     */
    public $payments;

    /**
     * The order code
     * @required
     * @var string $code public property
     */
    public $code;

    /**
     * The customer id
     * @required
     * @maps customer_id
     * @var string $customerId public property
     */
    public $customerId;

    /**
     * Shipping data
     * @required
     * @var \MundiAPILib\Models\CreateShippingRequest $shipping public property
     */
    public $shipping;

    /**
     * Metadata
     * @required
     * @var array $metadata public property
     */
    public $metadata;

    /**
     * @todo Write general description for this property
     * @required
     * @var bool $closed public property
     */
    public $closed;

    /**
     * Defines whether the order will go through anti-fraud
     * @maps antifraud_enabled
     * @var bool|null $antifraudEnabled public property
     */
    public $antifraudEnabled;

    /**
     * Ip address
     * @var string|null $ip public property
     */
    public $ip;

    /**
     * Session id
     * @maps session_id
     * @var string|null $sessionId public property
     */
    public $sessionId;

    /**
     * Request's location
     * @var \MundiAPILib\Models\CreateLocationRequest|null $location public property
     */
    public $location;

    /**
     * Device's informations
     * @var \MundiAPILib\Models\CreateDeviceRequest|null $device public property
     */
    public $device;

    /**
     * Currency
     * @var string|null $currency public property
     */
    public $currency;

    /**
     * Constructor to set initial or default values of member properties
     * @param array                 $items            Initialization value for $this->items
     * @param CreateCustomerRequest $customer         Initialization value for $this->customer
     * @param array                 $payments         Initialization value for $this->payments
     * @param string                $code             Initialization value for $this->code
     * @param string                $customerId       Initialization value for $this->customerId
     * @param CreateShippingRequest $shipping         Initialization value for $this->shipping
     * @param array                 $metadata         Initialization value for $this->metadata
     * @param bool                  $closed           Initialization value for $this->closed
     * @param bool                  $antifraudEnabled Initialization value for $this->antifraudEnabled
     * @param string                $ip               Initialization value for $this->ip
     * @param string                $sessionId        Initialization value for $this->sessionId
     * @param CreateLocationRequest $location         Initialization value for $this->location
     * @param CreateDeviceRequest   $device           Initialization value for $this->device
     * @param string                $currency         Initialization value for $this->currency
     */
    public function __construct()
    {
        switch (func_num_args()) {
            case 14:
                $this->items            = func_get_arg(0);
                $this->customer         = func_get_arg(1);
                $this->payments         = func_get_arg(2);
                $this->code             = func_get_arg(3);
                $this->customerId       = func_get_arg(4);
                $this->shipping         = func_get_arg(5);
                $this->metadata         = func_get_arg(6);
                $this->closed           = func_get_arg(7);
                $this->antifraudEnabled = func_get_arg(8);
                $this->ip               = func_get_arg(9);
                $this->sessionId        = func_get_arg(10);
                $this->location         = func_get_arg(11);
                $this->device           = func_get_arg(12);
                $this->currency         = func_get_arg(13);
                break;

            default:
                $this->closed = true;
                break;
        }
    }


    /**
     * Encode this object to JSON
     */
    public function jsonSerialize()
    {
        $json = array();
        $json['items']             = $this->items;
        $json['customer']          = $this->customer;
        $json['payments']          = $this->payments;
        $json['code']              = $this->code;
        $json['customer_id']       = $this->customerId;
        $json['shipping']          = $this->shipping;
        $json['metadata']          = $this->metadata;
        $json['closed']            = $this->closed;
        $json['antifraud_enabled'] = $this->antifraudEnabled;
        $json['ip']                = $this->ip;
        $json['session_id']        = $this->sessionId;
        $json['location']          = $this->location;
        $json['device']            = $this->device;
        $json['currency']          = $this->currency;

        return $json;
    }
}
