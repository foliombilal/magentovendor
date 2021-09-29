<?php
namespace PaymentExpress\PxPay2\Controller\PxFusion;

abstract class CommonAction extends \Magento\Framework\App\Action\Action
{
    const STATUS_AUTHORIZED = 'paymentexpress_authorized';
    /**
     *
     * @var \PaymentExpress\PxPay2\Logger\DpsLogger
     */
    protected $_logger;

    /**
     *
     * @var \PaymentExpress\PxPay2\Helper\PxFusion\Communication
     */
    protected $_communication;
    
    /**
     *
     * @var \PaymentExpress\PxPay2\Helper\PxFusion\Configuration
     */
    protected $_configuration;

    /**
     *
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $_quoteRepository;

    /**
     *
     * @var \Magento\Quote\Model\QuoteManagement
     */
    protected $_quoteManagement;

    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     *
     * @var \Magento\Quote\Model\GuestCart\GuestCartManagement
     */
    protected $_guestCartManagement;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface
     */
    private $_transactionBuilder;

    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $_messageManager;
    
    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $_json;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Serialize
     */
    private $_serialize;

    // Transaction approved.
    const APPROVED = 0;
    
    // Transaction declined.
    const DECLINED = 1;
    
    // Transaction declined due to transient error (retry advised).
    const TRANSIENT_ERROR = 2;
    
    // Invalid data submitted in form post (alert site admin).
    const INVALID_DATA = 3;
    
    // Transaction result cannot be determined at this time (re-run GetTransaction).
    const RESULT_UNKOWN = 4;
    
    // Transaction did not proceed due to being attempted after timeout timestamp
    // or having been cancelled by a CancelTransaction call.
    const CANCELLED = 5;
    
    // No transaction found (SessionId query failed to return a transaction record � transaction not yet attempted).
    const NO_TRANSACTION = 6;

    const MAX_RETRY_COUNT = 10;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder
    ) {
        parent::__construct($context);

        $this->_transactionBuilder = $txnBuilder;

        $this->_quoteRepository = $this->_objectManager->get(\Magento\Quote\Model\QuoteRepository::class);
        $this->_quoteManagement = $this->_objectManager->get(\Magento\Quote\Model\QuoteManagement::class);
        $this->_checkoutSession = $this->_objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->_guestCartManagement = $this
                                    ->_objectManager
                                    ->get(\Magento\Quote\Model\GuestCart\GuestCartManagement::class);
        $this->_messageManager = $this->_objectManager->get(\Magento\Framework\Message\ManagerInterface::class);
        
        $this->_logger = $this->_objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
        $this->_communication = $this->_objectManager->get("\PaymentExpress\PxPay2\Helper\PxFusion\Communication");
        $this->_configuration = $this->_objectManager->get("\PaymentExpress\PxPay2\Helper\PxFusion\Configuration");
        
        $this->_json = $this->_objectManager->get(\Magento\Framework\Serialize\Serializer\Json::class);
        $this->_serialize = $this->_objectManager->get(\Magento\Framework\Serialize\Serializer\Serialize::class);

        $this->_logger->info(__METHOD__);
    }

    protected function _getPaymentResult($transactionId, $triedCount)
    {
        $this->_logger->info(__METHOD__ . " transactionId:{$transactionId}, triedCount:{$triedCount}");
        
        $transactionResult = $this->_communication->getTransaction($transactionId);
        
        $status = $transactionResult["status"];
        if ($status == self::RESULT_UNKOWN && $triedCount < self::MAX_RETRY_COUNT) {
            return $this->_getPaymentResult($transactionId, $triedCount + 1);
        }
        return $transactionResult;
    }
    
    protected function _loadTransactionResultFromCache($userName, $transactionId)
    {
        $this->_logger->info(__METHOD__ . " user:{$userName}, transactionid:{$transactionId}");
        
        $paymentResultModel = $this->_objectManager->create("\PaymentExpress\PxPay2\Model\PaymentResult");
        
        $paymentResultModelCollection = $paymentResultModel
                                        ->getCollection()
                                        ->addFieldToFilter('token', $transactionId)
                                        ->addFieldToFilter('user_name', $userName);
        
        $paymentResultModelCollection->getSelect();
        
        foreach ($paymentResultModelCollection as $item) {

            $dataBag = $this->_objectManager->create(\Magento\Framework\DataObject::class);
            $rawValue = $item->getRawXml();
            $result;
            try {
                $result = $this->_json->unserialize($rawValue);
            } catch (\Exception $e) {
                // TODO: deprecate unserialize completely
                $result = $this->_serialize->unserialize($rawValue);
            }
            $dataBag->setTransactionResult($result);
            $dataBag->setReservedOrderId($item->getReservedOrderId());
            
            return $dataBag;
        }
        return null;
    }

    protected function _updatePaymentData($payment, $transactionResult)
    {
        // use same key-value map as pxpay2.
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
        if (isset($info["cartId"])) {
            unset($info["cartId"]);
        }
        if (isset($info["guestEmail"])) {
            unset($info["guestEmail"]);
        }
        $info["DpsTransactionType"] = $transactionResult["txnType"];
        $info["DpsResponseText"] = $transactionResult["responseText"];
        $info["ReCo"] = $transactionResult["responseCode"];
        $info["DpsTransactionId"] = $transactionResult["transactionId"];
        $info["DpsTxnRef"] = $transactionResult["dpsTxnRef"];
        $info["CardName"] = $transactionResult["cardName"];
        $info["CardholderName"] = $transactionResult["cardHolderName"];
        $info["Currency"] = $transactionResult["currencyName"];
        
        $payment->setAdditionalInformation($info);
        $payment->save();
    }

    protected function _savePaymentResult(
        $userId,
        $transactionId,
        \Magento\Quote\Model\Quote $quote,
        $transactionResult
    ) {
        $this->_logger->info(__METHOD__);
        $payment = $quote->getPayment();
        $method = $payment->getMethod();
    
        $paymentResultModel = $this->_objectManager->create("\PaymentExpress\PxPay2\Model\PaymentResult");
        $paymentResultModel->setData(
            [
                "dps_transaction_type" => $transactionResult["txnType"],
                "dps_txn_ref" => (string)$transactionResult["dpsTxnRef"],
                "method" => $method,
                "user_name" => $userId,
                "token" => $transactionId,
                "quote_id" => $quote->getId(),
                "reserved_order_id" => $quote->getReservedOrderId(),
                "updated_time" => (new \DateTime()),
                "raw_xml" => json_encode($transactionResult)
            ]
        );
    
        $paymentResultModel->save();
    
        $this->_logger->info(__METHOD__ . " done");
    }

    /**
     * Gets the additional information for the order payment.
     *
     * @param string[] $info
     */
    protected function _addTransaction(
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
            $this
            ->_transactionBuilder
            ->setAdditionalInformation([\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array)$info]);
        }

        $type = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
        if ($txnType == \PaymentExpress\PxPay2\Model\Config\Source\PaymentOptions::PURCHASE) {
            $type = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_PAYMENT;
        }

        $txn = $this->_transactionBuilder->build($type);
        $txn->setIsClosed($isClosed);

        return $txn;
    }
}
