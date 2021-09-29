<?php
namespace PaymentExpress\PxPay2\Model\Config\Source;

class RedirectOnErrorOptions
{
    const CART = 'cart';
    const PAYMENT_INFO = 'payment_info';
    
    public function toOptionArray()
    {
        return [
            [
                'value' => self::CART,
                'label' => 'Cart'
            ],
            [
                'value' => self::PAYMENT_INFO,
                'label' => 'Review & Payments'
            ]
        ];
    }
}
