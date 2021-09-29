<?php

namespace PaymentExpress\PxPay2\Observer;

use \Magento\Framework\Event\ObserverInterface;
use \PaymentExpress;

/***
 * This observer is for old versions of Success/Fail actions.
 * Stays here to account for outstanding PxPay sessions.
 *
 * This class will be deprecated in the next major release.
 */
class OrderPlacedObserver implements ObserverInterface
{
    /**
     *
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private $_orderSender;
    
    /**
     *
     * @var \PaymentExpress\PxPay2\Logger\DpsLogger
     */
    private $_logger;
    
    public function __construct()
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
        $this->_orderSender = $objectManager->get("\Magento\Sales\Model\Order\Email\Sender\OrderSender");
    
        $this->_logger->info(__METHOD__);
    }
    
    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        
        if ($order->getState() == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
            // Old functionality would create order and set is straight to Pending mode.
            // New functionality places the order with Pending Payment state.
            return;
        }

        $payment = $order->getPayment();
        $method = $payment->getMethod();
       
        if ($method != PaymentExpress\PxPay2\Model\Payment::PXPAY_CODE &&
            $method !=  PaymentExpress\PxPay2\Model\PxFusion\Payment::CODE) {
            return; // only send mail for payment methods in dps
        }
    }
}
