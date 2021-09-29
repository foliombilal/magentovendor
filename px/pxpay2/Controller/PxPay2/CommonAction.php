<?php
namespace PaymentExpress\PxPay2\Controller\PxPay2;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order\ShipmentFactory;
use Magento\Sales\Model\Order\Invoice;

use PaymentExpress\PxPay2\Helper\FileLock;

abstract class CommonAction extends \Magento\Framework\App\Action\Action
{
    const STATUS_AUTHORIZED = 'paymentexpress_authorized';
    const STATUS_FAILED = 'paymentexpress_failed';

    /**
     *
     * @var \Magento\Quote\Model\QuoteManagement
     */
    private $_quoteManagement;

    /**
     *
     * @var \Magento\Quote\Model\GuestCart\GuestCartManagement
     */
    private $_guestCartManagement;

    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;

    /**
     *
     * @var \PaymentExpress\PxPay2\Helper\Communication
     */
    private $_communication;

    /**
     *
     * @var \PaymentExpress\PxPay2\Helper\Configuration
     */
    private $_configuration;

    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_messageManager;

    /**
     *
     * @var \PaymentExpress\PxPay2\Logger\DpsLogger
     */
    private $_logger;

    /**
     *
     * @var \Magento\Quote\Model\QuoteIdMaskFactory
     */
    private $_quoteIdMaskFactory;


    /**
     *
     * @var \Magento\Quote\Model\QuoteFactory
     */
    private $_quoteFactory;


    /**
     *
     * @var \Magento\Sales\Model\Order\Status\HistoryFactory
     */
    private $_orderHistoryFactory;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface
     */
    private $_transactionBuilder;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    private $_searchCriteriaBuilder;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface;
     */
    private $_orderRepository;

    /**
     *
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private $_orderSender;

    /**
     * @var \Magento\Framework\Notification\NotifierInterface
     */
    private $_notifierInterface;

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
        parent::__construct($context);

        $this->_logger = $this->_objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
        $this->_communication = $this->_objectManager->get("\PaymentExpress\PxPay2\Helper\Communication");
        $this->_configuration = $this->_objectManager->get("\PaymentExpress\PxPay2\Helper\Configuration");

        $this->_messageManager = $context->getMessageManager();

        $this->_quoteManagement = $quoteManagement;
        $this->_guestCartManagement = $guestCartManagement;
        $this->_checkoutSession = $checkoutSession;
        $this->_quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->_quoteFactory = $quoteFactory;
        $this->_transactionBuilder = $txnBuilder;
        $this->_searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->_orderRepository = $orderRepository;
        $this->_orderSender = $orderSender;

        $this->_orderRepository = $orderRepository;
        $this->_orderHistoryFactory = $orderHistoryFactory;
        $this->_notifierInterface = $notifierInterface;

        $this->_logger->info(__METHOD__);
    }

    public function success()
    {
        $this->_logger->info(__METHOD__);
        $this->_handlePaymentResponse(true);
    }

    public function fail()
    {
        $this->_logger->info(__METHOD__);
        $this->_handlePaymentResponse(false);
    }

    private function _handlePaymentResponse($success)
    {
        $pxPayUserId = $this->_configuration->getPxPayUserId();
        $token = $this->getRequest()->getParam('result');
        $this->_logger->info(__METHOD__ . " userId:{$pxPayUserId} token:{$token} success:{$success}");

        /**
         *
         * @var PaymentExpress\PxPay2\Helper\FileLock;
         */
        $lockHandler = null;
        try {
            $lockFolder = $this->_configuration->getLocksFolder();
            if (empty($lockFolder)) {
                $lockFolder = BP . "/var/locks";
            }

            $lockHandler = new FileLock($token, $lockFolder);
            if (!$lockHandler->tryLock(false)) {
                $action = $this->getRequest()->getActionName();
                $params = $this->getRequest()->getParams();
                $triedTime = 0;
                if (array_key_exists('TriedTime', $params)) {
                    $triedTime = $params['TriedTime'];
                }
                if ($triedTime > 40) { // 40 seconds should be enough
                    $this->_redirectToCartPageWithError("Failed to process the order, please contact support.");
                    $this->_logger->critical(__METHOD__ . " lock timeout. userId:{$pxPayUserId} token:{$token} success:{$success} triedTime:{$triedTime}");
                    return;
                }
                
                $params['TriedTime'] = $triedTime + 1;
                
                $this->_logger->info(__METHOD__ . " redirecting to self, wait for lock release. userId:{$pxPayUserId} token:{$token} success:{$success} triedTime:{$triedTime}");
                sleep(1); // wait for sometime about lock release
                return $this->_forward($action, null, null, $params);
            }
            
            $this->_handlePaymentResponseWithoutLock2($success, $pxPayUserId, $token);
            $lockHandler->release();
        } catch (\Exception $e) {
            if (isset($lockHandler)) {
                $lockHandler->release();
            }
            
            $this->_notifierInterface->addMajor(
                "Failed to process PxPay 2.0 response.",
                "SessionId: " . $token . ". See Payment Express extension log for more details."
            );

            $this->_logger->critical(__METHOD__ . "  " . "\n" . $e->getMessage() . $e->getTraceAsString());
            $this->_redirectToCartPageWithError("Failed to processing the order, please contact support.");
        }
    }

    /**
     * @return \Magento\Sales\Model\Order
     */
    private function _getOrderByIncrementId($incrementId)
    {
        $searchCriteria = $this->_searchCriteriaBuilder->addFilter('increment_id', $incrementId, 'eq')->create();
        $orderList = $this->_orderRepository->getList($searchCriteria)->getItems();
        if (count($orderList) == 0) {
            $this->_logger->error(__METHOD__ . " Unable to load order with incrementId:{$incrementId}");
            throw new \Exception("Unable to load order with incrementId={$incrementId}");
        }

        return reset($orderList);
    }

    private function _handlePaymentResponseWithoutLock2($success, $pxPayUserId, $token)
    {
        // This function is for handling the response for the case when we place the order prior to redirecting to PxPay.

        $this->_logger->info(__METHOD__ . " userId:{$pxPayUserId} token:{$token} success:{$success}");
        
        // There will arrive two responses in general - one is from user redirect, another one is FPRN.
        // We need to do all the processing logic only once. So if there is already txn status in DB,
        // just redirecting to WaitForResult.

        // No transaction status means the result hasn't been processed yet
        $cache = $this->_loadTransactionStatusFromCache($pxPayUserId, $token);
        $orderIncrementId = $cache->getOrderIncrementId();
        if (empty($orderIncrementId)) {
            // 1. Sending PxPay request to get the transaction result.
            $responseXmlElement = $this->_getTransactionStatus($pxPayUserId, $token);
            if (!$responseXmlElement) {

                $this->_notifierInterface->addMajor(
                    "Failed to process PxPay 2.0 response.",
                    "SessionId: " . $token . ". See Payment Express extension log for more details."
                );
   
                $this->_logger->warning(__METHOD__ . " no response element. Xml:" . $responseXmlElement);
                return;
            }

            $orderIncrementId = (string)$responseXmlElement->MerchantReference;
            $dpsTxnRef = (string)$responseXmlElement->DpsTxnRef;
            $this->_logger->info(__METHOD__ . " Transaction status received. OrderIncrementId:{$orderIncrementId} DpsTxnRef:{$dpsTxnRef}");

            /**
             * @var \Magento\Sales\Model\Order $order
             */
            $order = $this->_getOrderByIncrementId($orderIncrementId);
            // $order = $this->_orderRepository->get(intval($orderIncrementId, 10));
            if ($order == null) {

                $this->_notifierInterface->addMajor(
                    "Failed to load the order to process PxPay 2.0 result.",
                    "SessionId: " . $token . ", OrderId: " . $orderIncrementId . ", DpsTxnRef: " . $dpsTxnRef .
                    ". See Payment Express extension log for more details."
                );

                $error = "Failed to load order: {$orderIncrementId}";
                $this->_logger->critical($error);
                $this->_redirectToCartPageWithError($error);
                return;
            }

            /**
             * @var \Magento\Quote\Model\Quote $quote
             */
            $quote = $this->_quoteFactory->create()->load($order->getQuoteId());

            // 2. Saving the result details into PaymentResult table
            $this->_savePaymentResult($pxPayUserId, $token, $quote, $responseXmlElement);

            /**
             * @var \Magento\Sales\Model\Order\Payment $payment
             */
            $payment = $order->getPayment();

            if (!$success) {
                $this->_logger->info(__METHOD__ . " Processing failure. CheckoutSessionId: {$this->_checkoutSession->getSessionId()}");

                $txnInfo = [];
                $txnInfo["DpsTransactionType"] = (string)$responseXmlElement->TxnType;
                $txnInfo["DpsResponseText"] = (string)$responseXmlElement->ResponseText;
                $txnInfo["ReCo"] = (string)$responseXmlElement->ReCo;
                $txnInfo["DpsTransactionId"] = (string)$responseXmlElement->TxnId;
                $txnInfo["DpsTxnRef"] = (string)$responseXmlElement->DpsTxnRef;
                $txnInfo["CardName"] = (string)$responseXmlElement->CardName;
                
                // TODO: Save currency because I do not know how to get currency in payment::capture. Remove it when found a better way
                $txnInfo["Currency"] = (string)$responseXmlElement->CurrencyInput;
    
                $this->_logger->warn(__METHOD__ . " Txn failed. TxnInfo: " . var_export($txnInfo, true));

                // TODO this is not correct. When FPRN comes in without the cookie, Magento creates a new session, so getSessionId will never be empty
                // There is currently no reliable way to differentiate the redirect from FPRN
                if (empty($this->_checkoutSession->getSessionId())) {
                    // FPRN notification won't have SessionID.
                    // In this case just cancelling order and that's it.

                    $this->_logger->info(__METHOD__ . " Processing is driven by FPRN.");
                    $this->_savePaymentInfoForFailedPayment($payment);

                    $txnType = (string)$responseXmlElement->TxnType;

                    $this->_logger->info(__METHOD__ . " FPRN. Adding txn.");
                    /*$txn = $this->_addTransaction($payment, $order, $txnType, $dpsTxnRef, true, $txnInfo);
                    if ($txn) {
                        $this->_logger->info(__METHOD__ . " FPRN. Saving txn and payment.");
                        $txn->save();
                        $payment->save();
                    }*/

                    $this->_logger->info(__METHOD__ . " FPRN. Sending payment failed email.");
                    $error = "Payment failed. Error: " . $responseXmlElement->ResponseText;
                    $this->_objectManager->get(\Magento\Checkout\Helper\Data::class)
                        ->sendPaymentFailedEmail($quote, $error);


                    $this->_logger->info(__METHOD__ . " FPRN. Cancelling order.");
                    $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
                    $order->cancel()->save();
                    $this->_logger->info(__METHOD__ . " FPRN. Done.");
                    return;
                }

                $this->_logger->info(__METHOD__ . " txn failed. Redirect. Attempting to restore cart. lastSuccessQuoteId:" . $this->_checkoutSession->getLastSuccessQuoteId().
                    " lastQuoteId:" . $this->_checkoutSession->getLastQuoteId().
                    " lastOrderId:" . $this->_checkoutSession->getLastOrderId().
                    " lastRealOrderId:" . $this->_checkoutSession->getLastRealOrderId());

                // 3. Failed? Adding appropriate details into the order payment.
                $this->_savePaymentInfoForFailedPayment($payment);

                $this->_logger->info(__METHOD__ . "  Redirect. adding txn with details");
                $txnType = (string)$responseXmlElement->TxnType;
                $dpsTxnRef = (string)$responseXmlElement->DpsTxnRef;

                $txn = $this->_addTransaction($payment, $order, $txnType, $dpsTxnRef, true, $txnInfo);
                if ($txn) {
                    $this->_logger->info(__METHOD__ . " Redirect. Saving txn and payment.");
                    $txn->save();
                    $payment->save();
                }

                $this->_logger->info(__METHOD__ . " Redirect. Saving order.");
                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                    ->setStatus(CommonAction::STATUS_FAILED);
                $order->save();
                // TODO: need to cancel the order and restore the quote
                
                $error = "Payment failed. Error: " . $responseXmlElement->ResponseText;
                $this->_logger->err($error);

                $this->_logger->info(__METHOD__ . " Sending payment failed email.");
                $this->_objectManager->get(\Magento\Checkout\Helper\Data::class)
                    ->sendPaymentFailedEmail($quote, $error);

                $this->_logger->info(__METHOD__ . " Redirect. Restoring cart.");
                $this->_restoreCart($order);

                $this->_logger->info(__METHOD__ . " Redirect. Redirecting to cart.");
                $this->_redirectToCartPageWithError($error);
                $this->_logger->info(__METHOD__ . " Redirect. Done.");
                return;
            }

            $isRegisteredCustomer = !empty($quote->getCustomerId());
            if ($isRegisteredCustomer) {
                $paymentInfo = $payment->getAdditionalInformation();
                $enableAddBillCard = $this->_getBoolValue($paymentInfo, "EnableAddBillCard");
                if ($enableAddBillCard) {
                    $this->_saveRebillToken($payment, $order->getId(), $quote->getCustomerId(), $responseXmlElement);
                }
            }

            $this->_logger->info(__METHOD__ . " Marking order as paid.");
            // 4. Marking order as paid for the case when it's a Purchase. What to do in case of Auth?
            $success = $this->_markOrderAsPaid($order, $responseXmlElement);
            $this->_logger->info(__METHOD__ . " Marking order as paid. Done. Success:{$success}");

            if ($success) {
                // 4. Succeeded? Perfect. Adding details into the payment
                $this->_savePaymentInfoForSuccessfulPayment($payment, $responseXmlElement);

                $this->sendEmailForTheOrder($order);

                $this->_logger->info(__METHOD__ . " Redirecting to success page.");
                $this->_redirect("checkout/onepage/success", [
                    "_secure" => true
                ]);
            }
            return;
        }

        // The result seem to be processed by the other thread. Triggering WaitingQuote thing
        $this->_redirect(
            "pxpay2/pxpay2/waitForResult",
            [
            "_secure" => true,
            "triedTimes" => 0,
            "reservedOrderId" => $orderIncrementId
            ]
        );
    }

    /**
     *
     * @param \Magento\Sales\Model\Order $order
     * @param \SimpleXMLElement $responseXmlElement
     * @return bool
     *  */
    private function _markOrderAsPaid(\Magento\Sales\Model\Order $order, \SimpleXMLElement $responseXmlElement)
    {
        $txnType = (string)$responseXmlElement->TxnType;
        $dpsTxnRef = (string)$responseXmlElement->DpsTxnRef;
        $amount = floatval($responseXmlElement->AmountSettlement);

        $this->_logger->info(__METHOD__ . " orderId:{$order->getEntityId()} txnType:{$txnType} dpsTxnRef:{$dpsTxnRef} amount:{$amount}");

        $order->setCanSendNewEmailFlag(true);
        
        if ($txnType == \PaymentExpress\PxPay2\Model\Config\Source\PaymentOptions::PURCHASE) {
            $this->_logger->info(__METHOD__ . " Purchase. Issuing an invoice.");
            $this->_invoice($order, $txnType, $dpsTxnRef, $amount);
            return true;
        } elseif ($txnType == \PaymentExpress\PxPay2\Model\Config\Source\PaymentOptions::AUTH) {
            $this->_logger->info(__METHOD__ . " Auth. Adding the transaction.");
            $payment = $order->getPayment();
            $info = $payment->getAdditionalInformation();
            $txn = $this->_addTransaction($payment, $order, $txnType, $dpsTxnRef, false, $info);
            if ($txn) {
                $txn->save();
                $order->getPayment()->save();

                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                  ->setStatus(CommonAction::STATUS_AUTHORIZED);
                
                $order->save();

                $this->_logger->info(__METHOD__ . " Auth. Transaction and Order saved.");
            }
            return true;
        } else {
            $this->_logger->error(__("Unexpected txn type"));
            return false;
        }
    }

    /**
     * Gets the additional information for the order payment.
     *
     * @param string[] $info
     */
    private function _addTransaction(
        \Magento\Sales\Model\Order\Payment $payment,
        \Magento\Sales\Model\Order $order,
        $txnType,
        $dpsTxnRef,
        $isClosed,
        $info
    ) {
        $this->_transactionBuilder
          ->setPayment($payment)
          ->setOrder($order)
          ->setTransactionId($dpsTxnRef)
          ->setFailSafe(true);

        if (isset($info)) {
            $this->_transactionBuilder->setAdditionalInformation([\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array)$info]);
        }

        $type = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
        if ($txnType == \PaymentExpress\PxPay2\Model\Config\Source\PaymentOptions::PURCHASE) {
            $type = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_PAYMENT;
        }

        $txn = $this->_transactionBuilder->build($type);
        $txn->setIsClosed($isClosed);

        return $txn;
    }

    private function _invoice(\Magento\Sales\Model\Order $order, $txnType, $dpsTxnRef, $amount)
    {
        $invoice = $order->prepareInvoice();
        $invoice->getOrder()->setIsInProcess(true);

        $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
        $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);

        $invoice->setTransactionId($dpsTxnRef);
        $invoice->register()
                ->pay()
                ->save();

        $order->save();

        $message = __(
            'Invoiced amount of %1 Transaction ID: %2',
            $order->getBaseCurrency()->formatTxt($amount),
            $dpsTxnRef
        );
        $this->_addHistoryComment($order, $message);
    }

    /**
     * @desc Add a comment to order history
     *
     * @param \Magento\Sales\Mode\Order $order
     * @param string                    $message
     */
    private function _addHistoryComment($order, $message)
    {
        $history = $this->_orderHistoryFactory->create()
          ->setComment($message)
          ->setEntityName('order')
          ->setOrder($order);

        $history->save();
    }


    private function _loadTransactionStatusFromCache($pxPayUserId, $token)
    {
        $this->_logger->info(__METHOD__ . " userId:{$pxPayUserId} token:{$token}");
        
        $paymentResultModel = $this->_objectManager->create("\PaymentExpress\PxPay2\Model\PaymentResult");
        
        $paymentResultModelCollection = $paymentResultModel->getCollection()
            ->addFieldToFilter('token', $token)
            ->addFieldToFilter('user_name', $pxPayUserId);
        
        $paymentResultModelCollection->getSelect();
        
        $dataBag = $this->_objectManager->create("\Magento\Framework\DataObject");

        $orderIncrementId = null;
        foreach ($paymentResultModelCollection as $item) {
            $orderIncrementId = $item->getReservedOrderId();
            $quoteId = $item->getQuoteId();
            $dataBag->setQuoteId($quoteId);
            $responseXmlElement = simplexml_load_string($item->getRawXml());
            $dataBag->setResponseXmlElement($responseXmlElement);
            $this->_logger->info(__METHOD__ . " userId:{$pxPayUserId} token:{$token} orderId:{$orderIncrementId} quoteId:{$quoteId}");
            break;
        }
        
        $dataBag->setOrderIncrementId($orderIncrementId);
        
        $this->_logger->info(__METHOD__ . " userId:{$pxPayUserId} token:{$token} orderIncrementId:{$orderIncrementId}");
        return $dataBag;
    }

    private function _getBoolValue($array, $fieldName)
    {
        if (!isset($array)) {
            return false;
        }
        if (!isset($array[$fieldName])) {
            return false;
        }

        return filter_var($array[$fieldName], FILTER_VALIDATE_BOOLEAN);
    }

    private function _restoreCart(\Magento\Sales\Model\Order $order)
    {
        $this->_logger->info(__METHOD__);
        $orderId = $this->_checkoutSession->getLastRealOrderId();
        if ($order->getRealOrderId() == $orderId) {
            $this->_logger->info(__METHOD__ . " order id matches. LastReadOrderId:{$orderId} OrderToCancel:{$order->getRealOrderId()}");
            // restore the quote
            if ($this->_checkoutSession->restoreQuote()) {
                $this->_logger->info(__METHOD__ . " Quote has been restored.");
                $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
                $order->cancel()->save();
            } else {
                $this->_logger->error(__METHOD__ . " Failed to restore the quote.");
            }
        } elseif ($order->getId()) {
            $this->_logger->warn(__METHOD__ . " attempting to cancel the order which is not the last one. " .
                "LastRealOrderId:{$this->_checkoutSession->getLastRealOrderId()} " .
                "OrderToCancel:{$order->getRealOrderId()}. Silently cancelling.");
            $order->setActionFlag(\Magento\Sales\Model\Order::ACTION_FLAG_CANCEL, true);
            $order->cancel()->save();
        }
    }

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param \SimpleXMLElement $paymentResponseXmlElement
     */
    private function _savePaymentInfoForSuccessfulPayment($payment, $paymentResponseXmlElement)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
        
        $info = $this->_clearPaymentParameters($info);
        
        $info["DpsTransactionType"] = (string)$paymentResponseXmlElement->TxnType;
        $info["DpsResponseText"] = (string)$paymentResponseXmlElement->ResponseText;
        $info["ReCo"] = (string)$paymentResponseXmlElement->ReCo;
        $info["DpsTransactionId"] = (string)$paymentResponseXmlElement->TxnId;
        $info["DpsTxnRef"] = (string)$paymentResponseXmlElement->DpsTxnRef;
        $info["CardName"] = (string)$paymentResponseXmlElement->CardName;
        $info["CardholderName"] = (string)$paymentResponseXmlElement->CardHolderName;
        
        // TODO: Save currency because I do not know how to get currency in payment::capture. Remove it when found a better way
        $info["Currency"] = (string)$paymentResponseXmlElement->CurrencyInput;
        if ($this->_configuration->getAllowRebill()) {
            $info["DpsBillingId"] = (string)$paymentResponseXmlElement->DpsBillingId;
        }
        
        $payment->unsAdditionalInformation(); // ensure DpsBillingId is not saved to database.
        $payment->setAdditionalInformation($info);
        
        $this->_logger->info(__METHOD__ . " info: ".var_export($info, true));
        $payment->save();
    }
    

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     */
    private function _savePaymentInfoForFailedPayment($payment)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
    
        $info = $this->_clearPaymentParameters($info);

        $payment->unsAdditionalInformation(); // ensure DpsBillingId is not saved to database.
        $payment->setAdditionalInformation($info);
        $payment->save();
    }
    
    private function _clearPaymentParameters($info)
    {
        $this->_logger->info(__METHOD__);
        
        unset($info["cartId"]);
        unset($info["guestEmail"]);
        unset($info["UseSavedCard"]);
        unset($info["DpsBillingId"]);
        unset($info["EnableAddBillCard"]);
        unset($info["method_title"]);
        unset($info["PxPayHPPUrl"]);

        $this->_logger->info(__METHOD__ . " info: ".var_export($info, true));
        return $info;
    }

    private function _saveRebillToken($payment, $orderId, $customerId, $paymentResponseXmlElement)
    {
        $this->_logger->info(__METHOD__." orderId:{$orderId}, customerId:{$customerId}");
        $storeManager = $this->_objectManager->get("\Magento\Store\Model\StoreManagerInterface");
        $storeId = $storeManager->getStore()->getId();
        $billingModel = $this->_objectManager->create("\PaymentExpress\PxPay2\Model\BillingToken");
        $billingModel->setData(
            [
                "customer_id" => $customerId,
                "order_id" => $orderId,
                "store_id" => $storeId,
                "masked_card_number" => (string)$paymentResponseXmlElement->CardNumber,
                "cc_expiry_date" => (string)$paymentResponseXmlElement->DateExpiry,
                "dps_billing_id" => (string)$paymentResponseXmlElement->DpsBillingId
            ]
        );
        $billingModel->save();
    }

    private function _savePaymentResult(
        $pxpayUserId,
        $token,
        \Magento\Quote\Model\Quote $quote,
        $paymentResponseXmlElement
    ) {
        $this->_logger->info(__METHOD__ . " username:{$pxpayUserId}, token:{$token}");
        $payment = $quote->getPayment();
        $method = $payment->getMethod();
        
        $paymentResultModel = $this->_objectManager->create("\PaymentExpress\PxPay2\Model\PaymentResult");
        $paymentResultModel->setData(
            [
                "dps_transaction_type" => (string)$paymentResponseXmlElement->TxnType,
                "dps_txn_ref" => (string)$paymentResponseXmlElement->DpsTxnRef,
                "method" => $method,
                "user_name" => $pxpayUserId,
                "token" => $token,
                "quote_id" => $quote->getId(),
                "reserved_order_id" => (string)$paymentResponseXmlElement->MerchantReference,
                "updated_time" => new \DateTime(),
                "raw_xml" => (string)$paymentResponseXmlElement->asXML()
            ]
        );
        
        $paymentResultModel->save();
        
        $this->_logger->info(__METHOD__ . " done");
    }

    /**
     *
     * @return \SimpleXMLElement
     */
    private function _getTransactionStatus($pxPayUserId, $token)
    {
        $responseXml = $this->_communication->getTransactionStatus($pxPayUserId, $token);
        $responseXmlElement = simplexml_load_string($responseXml);
        if (!$responseXmlElement) { // defensive code. should never happen
            $this->_logger->critical(__METHOD__ . " userId:{$pxPayUserId} token:{$token} response format is incorrect");
            $this->_redirectToCartPageWithError("Failed to connect to Payment Express. Please try again later.");
            return false;
        }
        
        return $responseXmlElement;
    }
    
    private function _redirectToCartPageWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");
        
        $this->_messageManager->addErrorMessage($error);

        $redirectDetails = $this->_configuration->getRedirectOnErrorDetails();
        $this->_redirect($redirectDetails['url'], $redirectDetails['params']);
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    public function sendEmailForTheOrder(\Magento\Sales\Model\Order $order)
    {
        $payment = $order->getPayment();
        $method = $payment->getMethod();

        $this->_logger->info(__METHOD__ . " orderId:" . $order->getId() . " paymentMethod:{$method}");
        
        if ($method != \PaymentExpress\PxPay2\Model\Payment::PXPAY_CODE &&
            $method != \PaymentExpress\PxPay2\Model\PxFusion\Payment::CODE) {
            return; // only send mail for payment methods in dps
        }
        
        if ($order->getCanSendNewEmailFlag()) {
            try {
                $this->_orderSender->send($order);
            } catch (\Exception $e) {
                $this->_logger->critical($e);
            }
        }
    }
}
