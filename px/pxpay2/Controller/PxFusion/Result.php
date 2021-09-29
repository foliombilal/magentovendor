<?php
namespace PaymentExpress\PxPay2\Controller\PxFusion;

use PaymentExpress\PxPay2\Helper\FileLock;

class Result extends CommonAction
{
    /**
     *
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $_storeManager;

    /**
     *
     * @var \Magento\Quote\Model\QuoteIdMaskFactory
     */
    private $_quoteIdMaskFactory;

    /**
     *
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $_orderRepository;

    private $_orderManager;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Quote\Model\QuoteIdMaskFactory $quoteIdMaskFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder
    ) {
        parent::__construct($context, $txnBuilder);
        $this->_storeManager = $storeManager;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->_orderRepository = $orderRepository;
        $this->_orderManager = $this->_objectManager->get(\Magento\Sales\Model\Order::class);
        $this->_logger->info(__METHOD__);
    }

    public function execute()
    {
        $transactionId = $this->getRequest()->getParam('sessionid');
        $this->_logger->info(__METHOD__ . " transactionId:{$transactionId}");
        
        /**
         *
         * @var \PaymentExpress\PxPay2\Helper\FileLock
         */
        $lockHandler = null;
        try {
            // avoid place order twice, as there is fprn in dps
            $lockFolder = $this->_configuration->getLocksFolder();
            if (empty($lockFolder)) {
                $lockFolder = BP . "/var/locks";
            }

            $lockHandler = new FileLock($transactionId, $lockFolder);
            if (!$lockHandler->tryLock(false)) {
                $action = $this->getRequest()->getActionName();
                $params = $this->getRequest()->getParams();
                $triedTime = 0;
                if (array_key_exists('TriedTime', $params)) {
                    $triedTime = $params['TriedTime'];
                }
                if ($triedTime > 40) { // 40 seconds should be enough
                    $this->_messageManager->addErrorMessage("Failed to process the order, please contact support");

                    $redirectDetails = $this->_configuration->getRedirectOnErrorDetails();
                    $this->_redirect($redirectDetails['url'], $redirectDetails['params']);

                    $this->_logger->critical(
                        __METHOD__ .
                        " lock timeout. transactionId:{$transactionId} triedTime:{$triedTime}"
                    );
                    return;
                }
                $params['TriedTime'] = $triedTime + 1;
                $this->_logger->info(
                    __METHOD__ .
                    " redirecting to self, wait for lock release. transactionId:{$transactionId} triedTime:{$triedTime}"
                );
                sleep(1); // give sometime for the previous response, before redirecting.
                return $this->_forward($action, null, null, $params);
            }

            $this->_processPaymentResult($transactionId);
            $lockHandler->release();
        } catch (\Exception $e) {
            if (isset($lockHandler)) {
                $lockHandler->release();
            }
            
            $this->_logger->critical(__METHOD__ . "  " . "\n" . $e->getMessage() . $e->getTraceAsString());
            $this->_messageManager->addErrorMessage("Failed to process the order, please contact support.");

            $redirectDetails = $this->_configuration->getRedirectOnErrorDetails();
            $this->_redirect($redirectDetails['url'], $redirectDetails['params']);
        }
    }

    private function _findTransactionResultField($result, $fieldName)
    {
        if (!isset($result['transactionResultFields']) ||
            !isset($result['transactionResultFields']->transactionResultField)
        ) {
            return null;
        }

        foreach ($result['transactionResultFields']->transactionResultField as $value) {
            if (!isset($value->fieldName)) {
                continue;
            }

            if ($value->fieldName != $fieldName) {
                continue;
            }

            if (!isset($value->fieldValue)) {
                return null;
            }

            return $value->fieldValue;
        }

        return null;
    }

    private function _processPaymentResult($transactionId)
    {
        $userName = $this->_configuration->getUserName();
        $this->_logger->info(__METHOD__ . " userName:{$userName} transactionId:{$transactionId}");
        
        $dataBag = $this->_loadTransactionResultFromCache($userName, $transactionId);
        $status = self::RESULT_UNKOWN;
        $quoteId = null;
        if (empty($dataBag)) {
            // 1. Sending PxFusion request to get the transaction result.
            $transactionResult = $this->_getPaymentResult($transactionId, 0);
            if (!$transactionResult) {

                $this->_notifierInterface->addMajor(
                    "Failed to process PxFusion response.",
                    "SessionId: " . $token . ". See Windcave extension log for more details."
                );
   
                $this->_logger->warning(__METHOD__ . " no response element. Json:" . $transactionResult);
                return;
            }
            $status = $transactionResult["status"];
            $quoteId = $transactionResult["txnRef"];
            $quote = $this->_quoteRepository->get($quoteId);
            $payment = $quote->getPayment();
            // 2. Saving the result details into PaymentResult table
            $this->_savePaymentResult($userName, $transactionId, $quote, $transactionResult);
            if ($status == self::APPROVED) {
                $data = $payment->getAdditionalInformation();
                $this->_logger->info(__METHOD__ . " data:" . var_export($data, true));
                
                $this->_updatePaymentData($payment, $transactionResult);

                if ($quote->getCheckoutMethod() != \Magento\Quote\Api\CartManagementInterface::METHOD_GUEST) {
                    $this->_logger->info(__METHOD__ . " placing order for logged in customer. quoteId:{$quoteId}");
                    // create order, and redirect to success page.
                    $orderId = $this->_quoteManagement->placeOrder($quoteId);
                    $enableAddBillCard =  filter_var($data["EnableAddBillCard"], FILTER_VALIDATE_BOOLEAN);
                    
                    if ($this->_configuration->getAllowRebill() && $enableAddBillCard) {
                        $customerId = $quote->getCustomer()->getId();
                        $cardNumber = $transactionResult["cardNumber"];
                        $dateExpiry = $transactionResult["dateExpiry"];
                        $dpsBillingId = $transactionResult["dpsBillingId"];
                        $this->_saveRebillToken($orderId, $customerId, $cardNumber, $dateExpiry, $dpsBillingId);
                    }
                } else {
                    // Guest:
                    $cartId = $this->_quoteIdMaskFactory->create()->load($quoteId, 'quote_id')->getMaskedId();

                    $this->_logger->info(__METHOD__ . " placing order for guest. quoteId:{$quoteId} cartId:{$cartId}");
                    $orderId = $this->_guestCartManagement->placeOrder($cartId);
                }

                $this->_logger->info(__METHOD__ . " adding transaction.");
                $order = $this->_orderRepository->get($orderId);
                $txnType = $transactionResult["txnType"];
                $dpsTxnRef = $transactionResult["dpsTxnRef"];

                $info = $payment->getAdditionalInformation();
                $txn = $this->_addTransaction(
                    $order->getPayment(),
                    $order,
                    $txnType,
                    $dpsTxnRef,
                    $txnType == \PaymentExpress\PxPay2\Model\Config\Source\PaymentOptions::PURCHASE,
                    $info
                );
                if ($txn) {
                    $this->_logger->info(__METHOD__ . " Check if this is Authentication ype");
                    $txn->save();
                    if ($txnType == \PaymentExpress\PxPay2\Model\Config\Source\PaymentOptions::AUTH) {
                        $order->getPayment()->save();

                        $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                        ->setStatus(CommonAction::STATUS_AUTHORIZED);
                        
                        $order->save();
                    }
                }
                $this->_logger->info(
                    __METHOD__ .
                    " transaction added. TxnType:{$txnType} DpsTxnRef:{$dpsTxnRef} OrderId:{$orderId}"
                );

                $this->_logger->info(
                    __METHOD__ .
                    " placing order done lastRealOrderId:" .
                    $this->_checkoutSession->getLastRealOrderId()
                );

                $this->_redirect("checkout/onepage/success", [
                    "_secure" => true
                    ]);
                return;
            }
        } else {
            $transactionResult = $dataBag->getTransactionResult();
            $status = $transactionResult["status"];

            $order = $this->_orderManager->loadByAttribute("increment_id", $dataBag->getReservedOrderId());
            if ($order->getId()) {
                $quoteId = $order->getQuoteId();
            }
        }
        if ($status == self::APPROVED) {
            $this->_redirect(
                "pxpay2/pxfusion/waitingQuote",
                [
                    "_secure" => true,
                    "triedTimes" => 0,
                    "reservedOrderId" => $dataBag->getReservedOrderId()
                ]
            );
            return;
        }
        
        // failed case handled here. Success one is redirected to the onepage/success already.
        $error = "Failed to process the order.";
        if ($status == self::NO_TRANSACTION || $status == self::RESULT_UNKOWN) {
            // Not able to found transaction in dps. And even not able to know which quote does the payment belongs to.
            $error = "The order is not found. Please contact support";
        }
        if ($status == self::DECLINED) {

            $resultToDisplay = $this->_findTransactionResultField($transactionResult, "CardHolderHelpText");
            if (!isset($resultToDisplay) || empty($resultToDisplay)) {
                $resultToDisplay = $this->_findTransactionResultField($transactionResult, "CardHolderResponseText");
            }

            if (!isset($resultToDisplay) || empty($resultToDisplay)) {
                $resultToDisplay = $transactionResult['responseText'];
            }

            $error = "Payment failed. " . $resultToDisplay;

            if (isset($quoteId) && !empty($quoteId)) {
                $quote = $this->_quoteRepository->get($quoteId);

                $this->_objectManager->get(\Magento\Checkout\Helper\Data::class)
                    ->sendPaymentFailedEmail($quote, $error);
            }
        }
        
        $this->_messageManager->addErrorMessage($error);
        $this->_logger->critical(__METHOD__ . " status:{$status} error:{$error}");

        $redirectDetails = $this->_configuration->getRedirectOnErrorDetails();
        $this->_redirect($redirectDetails['url'], $redirectDetails['params']);
    }
    
    private function _saveRebillToken($orderId, $customerId, $cardNumber, $dateExpiry, $dpsBillingId)
    {
        $this->_logger->info(__METHOD__." orderId:{$orderId}, customerId:{$customerId}");
        $storeId = $this->_storeManager->getStore()->getId();
        $billingModel = $this->_objectManager->create("\PaymentExpress\PxPay2\Model\BillingToken");
        $billingModel->setData(
            [
                        "customer_id" => $customerId,
                        "order_id" => $orderId,
                        "store_id" => $storeId,
                        "masked_card_number" => $cardNumber,
                        "cc_expiry_date" => $dateExpiry,
                        "dps_billing_id" => $dpsBillingId
            ]
        );
        $billingModel->save();
    }
}
