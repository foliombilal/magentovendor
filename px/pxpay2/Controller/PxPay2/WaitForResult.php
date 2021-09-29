<?php
namespace PaymentExpress\PxPay2\Controller\PxPay2;

class WaitForResult extends \Magento\Framework\App\Action\Action
{

    /**
     *
     * @var \PaymentExpress\PxPay2\Logger\DpsLogger
     */
    private $_logger;

    /**
     *
     * @var \Magento\Sales\Model\Order
     */
    private $_orderManager;

    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $_quoteRepository;

    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_messageManager;

    /**
     *
     * @var \PaymentExpress\PxPay2\Helper\Configuration
     */
    private $_configuration;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $session,
        \Magento\Sales\Model\Order $orderManager,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
    ) {
        parent::__construct($context);
        $this->_checkoutSession = $session;
        $this->_orderManager = $orderManager;
        $this->_quoteRepository = $quoteRepository;
        $this->_messageManager = $context->getMessageManager();
        $this->_configuration = $this->_objectManager->get("\PaymentExpress\PxPay2\Helper\Configuration");
        $this->_logger = $this->_objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);
    }

    public function execute()
    {
        // If came here with FPRN, then don't do anything?
        // If came here via redirect, check the order status. If Payment_failed, then cancel the order and restore the cart.

        $reservedOrderId = $this->getRequest()->getParam('reservedOrderId');
        $triedTimes = $this->getRequest()->getParam("triedTimes");

        $lastRealOrderId = $this->_checkoutSession->getLastRealOrderId();
        $this->_logger->info(__METHOD__ . " reservedOrderId:{$reservedOrderId} triedTimes:{$triedTimes} lastRealOrderId:{$lastRealOrderId}");

        /**
         * @var \Magento\Sales\Model\Order $order
         */
        $order = $this->_orderManager->loadByAttribute("increment_id", $reservedOrderId);

        /**
         * @var \Magento\Sales\Model\Order\Payment $payment
         */
        $payment = $order->getPayment();
        // $info = $payment->getAdditionalInformation();
        $state = $order->getState();
        $status = $order->getStatus();

        $this->_logger->info(__METHOD__ . " reservedOrderId:{$reservedOrderId} state:{$state} status:{$status}");

        if ($state == \Magento\Sales\Model\Order::STATE_PROCESSING || $status == CommonAction::STATUS_AUTHORIZED) {
            $quoteId = $order->getQuoteId();
            $this->_checkoutSession->setLastQuoteId($quoteId);
            $this->_checkoutSession->setLastSuccessQuoteId($quoteId);
            $this->_checkoutSession->setLastOrderId($order->getId());
            $this->_checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->_checkoutSession->setLastOrderStatus($order->getStatus());

            $this->_logger->info(__METHOD__ . " load order:{$reservedOrderId} from db and redirect to the success page.");
            $this->_redirect("checkout/onepage/success", [
                "_secure" => true
            ]);
            return;
        } elseif ($state == \Magento\Sales\Model\Order::STATE_CANCELED || $status == CommonAction::STATUS_FAILED) {
            $this->_logger->error(__METHOD__ . " transaction has failed or been cancelled. Restoring the cart.");
            $this->_restoreCart($order);
            $this->_redirectToCartPageWithError("Payment failed.");
            return;
        }
        
        if ($triedTimes > 10) {
            // defensive code. should never happens.
            // TODO: if this happens, what should we do?! Order is already placed. Should we cancel it?
            $this->_logger->warn(__METHOD__ . " order:{$reservedOrderId} is not created yet, redirecting to the cart page, please check if there is any exception happened.");
            $this->_redirectToCartPageWithError("Failed to get the payment details.");
            return;
        }
        
        sleep(1); // wait for order ready.
        
        $this->_logger->info(__METHOD__ . " reservedOrderId:{$reservedOrderId}. Redirecting to WaitForResult");
        $this->_redirect("pxpay2/pxpay2/waitForResult", [
            "_secure" => true,
            "triedTimes" => $triedTimes + 1,
            "reservedOrderId" => $reservedOrderId
        ]);
        return;
    }

    private function _redirectToCartPageWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");
        
        $this->_messageManager->addErrorMessage($error);

        $redirectDetails = $this->_configuration->getRedirectOnErrorDetails();
        $this->_redirect($redirectDetails['url'], $redirectDetails['params']);
    }

    private function _restoreCart(\Magento\Sales\Model\Order $order)
    {
        $quote = $this->_quoteRepository->get($order->getQuoteId());
        if ($quote->getIsActive() == 1) {
            // Quote is already restored. Nothing to do here.
            $this->_logger->info(__METHOD__ . " Quote is already active. Skipping the step.");
            return;
        }

        $this->_logger->info(__METHOD__);
        $orderId = $this->_checkoutSession->getLastRealOrderId();
        if ($order->getRealOrderId() == $orderId) {
            $this->_logger->info(__METHOD__ . " order id matches. LastReadOrderId:{$orderId} OrderToCancel:{$order->getRealOrderId()}");
            // restore the quote
            if ($this->_checkoutSession->restoreQuote()) {
                $this->_logger->info(__METHOD__ . " Quote has been restored.");

                // Order will be cancelled by this moment
            } else {
                $this->_logger->error(__METHOD__ . " Failed to restore the quote.");
            }
        }
    }
}
