<?php
namespace PaymentExpress\PxPay2\Controller\PxPay2;

use \Magento\Framework\App\Action\Context;
use \PaymentExpress\PxPay2\Controller\PxPay2\CommonAction;

class RedirectSuccess extends CommonAction
{
    
    /**
     *
     * @var \PaymentExpress\PxPay2\Logger\DpsLogger
     */
    private $_logger;

    public function __construct(
        Context $context,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Status\HistoryFactory $orderHistoryFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Quote\Model\GuestCart\GuestCartManagement $guestCartManagement,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Framework\Notification\NotifierInterface $notifierInterface
    ) {
        parent::__construct(
            $context,
            $orderRepository,
            $orderHistoryFactory,
            $checkoutSession,
            $quoteManagement,
            $guestCartManagement,
            $quoteIdMaskFactory,
            $quoteFactory,
            $txnBuilder,
            $searchCriteriaBuilder,
            $orderSender,
            $notifierInterface
        );
        $this->_logger = $this->_objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);
    }

    public function execute()
    {
        $this->_logger->info(__METHOD__);
        $this->success();
    }
}
