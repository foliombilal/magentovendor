<?php
namespace PaymentExpress\PxPay2\Helper\PxFusion;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Magento\Payment\Gateway\Http\Client\Soap;
use Magento\Catalog\Model\Product\Exception;

class Communication extends AbstractHelper
{
    /**
     *
     * @var \Magento\Framework\Webapi\Soap\ClientFactory
     */
    private $_clientFactory;
    
    /**
     *
     * @var \Magento\Framework\Url
     */
    private $_url;

    /**
     *
     * @var \Magento\Framework\App\ObjectManager::getInstance
     */
    private $_objectManager;

    /**
     *
     * @var \PaymentExpress\PxPay2\Helper\PxFusion\Configuration
     */
    private $_configuration;
    
    /**
     *
     * @var \PaymentExpress\PxPay2\Helper\PaymentUtil
     */
    private $_paymentUtil;
    
    /**
     *
     * @var \PaymentExpress\PxPay2\Helper\Common\PxPost
     */
    private $_pxPost;

    /**
     *
     * @var \Magento\Framework\Message\ManagerInterface
     */
    private $_messageManager;
    
    /**
     *
     * @var \Magento\Quote\Model\QuoteManagement
     */
    private $_quoteManagement;

    /**
     *
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $_orderRepository;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface
     */
    private $_transactionBuilder;
    
    /**
     *
     * @var \Magento\Checkout\Model\Session
     */
    private $_checkoutSession;
    
    const MAX_RETRY_COUNT = 10;

    public function __construct(
        Context $context,
        \Magento\Framework\Webapi\Soap\ClientFactory $clientFactory,
        \Magento\Framework\Message\ManagerInterface $messageIntf,
        \Magento\Checkout\Model\Session $session,
        \Magento\Quote\Model\QuoteManagement $quoteManagement,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $txnBuilder
    ) {
        parent::__construct($context);
        $this->_clientFactory = $clientFactory;
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_url = $objectManager->get("\Magento\Framework\Url");
        $this->_logger = $objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
        $this->_objectManager = $objectManager;
        $this->_messageManager = $messageIntf;
        $this->_checkoutSession = $session;
        $this->_quoteManagement = $quoteManagement;
        $this->_transactionBuilder = $txnBuilder;
        $this->_orderRepository = $orderRepository;
        
        $this->_configuration = $objectManager->get("\PaymentExpress\PxPay2\Helper\PxFusion\Configuration");
        $this->_paymentUtil = $objectManager->get("\PaymentExpress\PxPay2\Helper\PaymentUtil");
        $this->_pxPost = $objectManager->get("\PaymentExpress\PxPay2\Helper\Common\PxPost");
        $this->_logger->info(__METHOD__);
    }
    

    /**
     *
     * @param Magento\Checkout\Model\Quote $quote
     * @param string $returnUrl
     * @param boolean $addBillCard
     */
    public function createTransaction($quote, $returnUrl, $addBillCard = false, $dpsBillingId = null)
    {
        $this->_logger->info(__METHOD__);
        $parameters = $this->_buildTransactionParameters($quote, $returnUrl, $addBillCard, $dpsBillingId);
        
        // http://stackoverflow.com/questions/11391442/fatal-error-class-soapclient-not-found
        $soapClient = $this->_clientFactory->create(
            $this->_configuration->getWsdl(),
            [
                'trace' => true,
                'soap_version' =>\SOAP_1_1
            ]
        );
        
        $response = $soapClient->GetTransactionId($parameters);
        
        // stdClass::__set_state(array( 'GetTransactionIdResult' =>
        // stdClass::__set_state(
        // array(
        // 'sessionId' => '000001000084842500ff6dee5fc4d981',
        // 'success' => true,
        // 'transactionId' => '000001000084842500ff6dee5fc4d981',
        // )
        // ),
        // )
        // )
        
        $this->_logger->info(__METHOD__ . " response: " . var_export($response, true));
        return $response->GetTransactionIdResult;
    }

    /**
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param string $dpsBillingId
     * @param int $storeId
     * @return array Redirect URL
     */
    public function rebill($quote, $dpsBillingId, $storeId)
    {
        $this->_logger->info(__METHOD__);
        
        // We need this txnId as we will need it for Status request if get StatusRequired in the response
        $txnId = substr(uniqid(rand()), 0, 16);
        $quoteId = $quote->getId();
        
        $responseText = $this->_sendPxPostRequestForRebill($quote, $dpsBillingId, $storeId, $txnId);
        $responseXmlElement = simplexml_load_string($responseText);
        if (!$responseXmlElement) {
            $this->_logger->critical(__METHOD__ . " dpsBillingId:{$dpsBillingId} quoteId:{$quoteId} response format is incorrect");
            return $this->_redirectToCartPageWithError("Internal error. Please contact support.");
        }

        $statusRequired = (string)$responseXmlElement->Transaction->StatusRequired;
        $txnResponseText = "Cannot get the payment status for quote #{$quoteId}. Please contact support";
        $authorized = null;
        $txnOutcomeReceived = $statusRequired == "0";
        if (!$txnOutcomeReceived) {
            $triedCount = 0;
            while ($triedCount < self::MAX_RETRY_COUNT) {
                $responseText = $this->_sendPxPostStatusRequest($storeId, $txnId);
                $responseXmlElement = simplexml_load_string($responseText);
                if (!$responseXmlElement) {
                    $this->_logger->critical(__METHOD__ . " dpsBillingId:{$dpsBillingId} quoteId:{$quote->getId()} response format is incorrect");
                    return $this->_redirectToCartPageWithError("Internal error while processing quote #{$quoteId}. Please contact support.");
                }
                $statusRequired = (string)$responseXmlElement->Transaction->StatusRequired;
                $txnOutcomeReceived = $statusRequired == "0";
                if ($txnOutcomeReceived) {
                    break;
                }
                $triedCount++;
            }
        }

        if ($txnOutcomeReceived) {
            $authorized = $responseXmlElement->Transaction->Authorized;
            $displayError = $responseXmlElement->Transaction->CardHolderHelpText;
            if (!isset($displayError) || empty($displayError)) {
                $displayError = $responseXmlElement->ResponseText;
            }
        
            $txnResponseText = "Payment failed for quote #{$quoteId}. Error: " . $displayError;
        }
        
        $postUserId = $this->_configuration->getPxPostUsername($storeId);
        $this->_savePaymentResult($postUserId, $quote, $responseXmlElement);
        
        if (!$authorized || $authorized == "0") {
            $payment = $quote->getPayment();
            $this->_savePaymentInfoForFailedPayment($payment);
            
            $this->_logger->info($txnResponseText);
        
            return $this->_redirectToCartPageWithError($txnResponseText);
        }

        $orderId = $this->_placeOrder($quote, $responseXmlElement);

        $this->_logger->info(__METHOD__ . " adding transaction.");
        $order = $this->_orderRepository->get($orderId);
        $txnType = (string)$responseXmlElement->Transaction->TxnType;
        $dpsTxnRef = (string)$responseXmlElement->Transaction->DpsTxnRef;
        $payment = $quote->getPayment();
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
            $txn->save();
            $this->_logger->info(__METHOD__ . " Check if this is Authentication type");
            if ($txnType == \PaymentExpress\PxPay2\Model\Config\Source\PaymentOptions::AUTH) {
                $order->getPayment()->save();
                $order->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)
                    ->setStatus(\PaymentExpress\PxPay2\Controller\PxFusion\CommonAction::STATUS_AUTHORIZED);
                $order->save();
            }
        }

        $this->_logger->info(__METHOD__ . " transaction saved.");

        return [ 'url' => "checkout/onepage/success", 'params' => [] ];
    }
    

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

    private function _getPaymentResult($transactionId, $triedCount)
    {
        $this->_logger->info(__METHOD__ . " transactionId:{$transactionId}, triedCount:{$triedCount}");
    
        $transactionResult = $this->_communication->getTransaction($transactionId);
    
        $status = $transactionResult["status"];
        if ($status == self::RESULT_UNKOWN && $triedCount < self::MAX_RETRY_COUNT) {
            return $this->_getPaymentResult($transactionId, $triedCount + 1);
        }
        return $transactionResult;
    }

    /*
     *
     */
    private function _placeOrder(\Magento\Quote\Model\Quote $quote, $responseXmlElement)
    {
        $orderIncrementId = (string)$responseXmlElement->MerchantReference;
        $this->_logger->info(__METHOD__ . " orderIncrementId:{$orderIncrementId}");
    
        $quoteId = $quote->getId();
        $payment = $quote->getPayment();
    
        $info = $payment->getAdditionalInformation();
        $this->_logger->info(__METHOD__ . " info:" . var_export($info, true));
    
        $this->_savePaymentInfoForSuccessfulPayment($payment, $responseXmlElement);
    
        $quote->setPayment($payment); // looks like $payment is copy by reference. ensure the $payment data of order is exactly same with the quote.
        $this->_logger->info(__METHOD__ . " placing order for logged in customer. quoteId:{$quoteId}");
        // create order, and redirect to success page.
        $orderId = $this->_quoteManagement->placeOrder($quoteId);
    
        $this->_checkoutSession->setLoadInactive(false);
        $this->_checkoutSession->replaceQuote($this->_checkoutSession->getQuote()->save());
    
        $this->_logger->info(__METHOD__ . " placing order done lastSuccessQuoteId:". $this->_checkoutSession->getLastSuccessQuoteId().
                " lastQuoteId:".$this->_checkoutSession->getLastQuoteId().
                " lastOrderId:".$this->_checkoutSession->getLastOrderId().
                " lastRealOrderId:" . $this->_checkoutSession->getLastRealOrderId());
    
        return $orderId;
    }
    

    private function _savePaymentInfoForSuccessfulPayment($payment, $paymentResponseXmlElement)
    {
        $this->_logger->info(__METHOD__);
        $info = $payment->getAdditionalInformation();
    
        $info = $this->_clearPaymentParameters($info);
    
        $info["DpsTransactionType"] = (string)$paymentResponseXmlElement->Transaction->TxnType;
        $info["DpsResponseText"] = (string)$paymentResponseXmlElement->ResponseText;
        $info["ReCo"] = (string)$paymentResponseXmlElement->Transaction->ReCo;
        $info["DpsTxnRef"] = (string)$paymentResponseXmlElement->DpsTxnRef;
        $info["CardName"] = (string)$paymentResponseXmlElement->Transaction->CardName;
        $info["CardholderName"] = (string)$paymentResponseXmlElement->Transaction->CardHolderName;
        
        // TODO: Save currency because I do not know how to get currency in payment::capture. Remove it when found a better way
        $info["Currency"] = (string)$paymentResponseXmlElement->Transaction->InputCurrencyName;
    
        $payment->unsAdditionalInformation();
        $payment->setAdditionalInformation($info);
    
        $info = $payment->getAdditionalInformation();
        $this->_logger->info(__METHOD__ . " info: ".var_export($info, true));
        $payment->save();
    }
    
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
        unset($info["sessionId"]);
        unset($info["transactionId"]);
        unset($info["UseSavedCard"]);
        unset($info["DpsBillingId"]);
        unset($info["DpsTransactionId"]);
        unset($info["EnableAddBillCard"]);
        unset($info["method_title"]);
    
        $this->_logger->info(__METHOD__ . " info: ".var_export($info, true));
        return $info;
    }
    
    private function _redirectToCartPageWithError($error)
    {
        $this->_logger->info(__METHOD__ . " error:{$error}");
    
        $this->_messageManager->addErrorMessage($error);
        return $this->_configuration->getRedirectOnErrorDetails();
    }
    
    private function _savePaymentResult($pxpostUserId, \Magento\Quote\Model\Quote $quote, $paymentResponseXmlElement)
    {
        $this->_logger->info(__METHOD__ . " username:{$pxpostUserId}");
        $payment = $quote->getPayment();
        $method = $payment->getMethod();
    
        $paymentResultModel = $this->_objectManager->create("\PaymentExpress\PxPay2\Model\PaymentResult");
        $paymentResultModel->setData(
            [
                        "dps_transaction_type" => (string)$paymentResponseXmlElement->Transaction->TxnType,
                        "dps_txn_ref" => (string)$paymentResponseXmlElement->DpsTxnRef,
                        "method" => $method,
                        "user_name" => $pxpostUserId,
                        "token" => "", // rebilling with PxPost doesn't have any tokens
                        "quote_id" => $quote->getId(),
                        "reserved_order_id" => (string)$paymentResponseXmlElement->Transaction->MerchantReference,
                        "updated_time" => new \DateTime(),
                        "raw_xml" => (string)$paymentResponseXmlElement->asXML()
            ]
        );
    
        $paymentResultModel->save();
    
        $this->_logger->info(__METHOD__ . " done");
    }
    
    /**
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param string $dpsBillingId
     * @param int $storeId
     * @param string $txnId
     */
    private function _sendPxPostRequestForRebill($quote, $dpsBillingId, $storeId, $txnId)
    {
        $this->_logger->info(__METHOD__ . " entered. DpsBillingId:{$dpsBillingId} txnId:{$txnId} quoteId:{$quote->getId()}");
        
        $dataBag = $this->_objectManager->create("\Magento\Framework\DataObject");
        $currency = $quote->getCurrency()->getStoreCurrencyCode();
        $formattedAmount = $this->_paymentUtil->formatCurrency($quote->getBaseGrandTotal(), $currency);
        $txnType = $this->_configuration->getPaymentType($quote->getStoreId());

        $this->_logger->info(__METHOD__ . " amount:{$formattedAmount} currency:{$currency} txnType:{$txnType} dpsBillingId:{$dpsBillingId} storeId:{$storeId}");
        
        $dataBag->setUsername($this->_configuration->getPxPostUsername($storeId));
        $dataBag->setPassword($this->_configuration->getPxPostPassword($storeId));
        $dataBag->setPostUrl($this->_configuration->getPxPostUrl($storeId));
        $dataBag->setAmount($formattedAmount);
        $dataBag->setCurrency($currency);
        $dataBag->setTxnType($txnType);
        $dataBag->setTxnRef($quote->getId());
        $dataBag->setDpsBillingId($dpsBillingId);
        $dataBag->setAccountInfo($quote->getCustomerId());
        $dataBag->setMerchantReference($quote->getReservedOrderId());
        $dataBag->setTxnId($txnId);
        return $this->_pxPost->send($dataBag);
    }
    
    /**
     *
     * @param int $storeId
     * @param string $txnId
     */
    private function _sendPxPostStatusRequest($storeId, $txnId)
    {
        $this->_logger->info(__METHOD__ . " entered. DpsBillingId:{$storeId} txnId:{$txnId}");
         
        $dataBag = $this->_objectManager->create("\Magento\Framework\DataObject");
        $dataBag->setUsername($this->_configuration->getPxPostUsername($storeId));
        $dataBag->setPassword($this->_configuration->getPxPostPassword($storeId));
        $dataBag->setPostUrl($this->_configuration->getPxPostUrl($storeId));
        $dataBag->setTxnId($txnId);
        return $this->_pxPost->sendStatusRequest($dataBag);
    }
    
    
    public function getTransaction($transactionId)
    {
        $this->_logger->info(__METHOD__ . " transactionId:{$transactionId}");
        
        $soapClient = $this->_clientFactory->create(
            $this->_configuration->getWsdl(),
            [
                'trace' => true,
                'soap_version' =>\SOAP_1_1
            ]
        );
        $parameters = [
            'username' => $this->_configuration->getUserName(),
            'password' => $this->_configuration->getPassword(),
            'transactionId' => $transactionId
        ];
        
        $response = $soapClient->GetTransaction($parameters);
        $this->_logger->info(__METHOD__ . " response: ". var_export($response, true));
        
        $converted = get_object_vars($response->GetTransactionResult);
        $this->_logger->info(__METHOD__ . " response array: ". var_export($converted, true));
        return $converted;
    }
    
    public function refund($amount, $currency, $dpsTxnRef, $storeId)
    {
        $this->_logger->info(__METHOD__);
        return $this->_sendPxPostRequest($amount, $currency, "Refund", $dpsTxnRef, $storeId);
    }
    
    public function complete($amount, $currency, $dpsTxnRef, $storeId)
    {
        $this->_logger->info(__METHOD__);
        return $this->_sendPxPostRequest($amount, $currency, "Complete", $dpsTxnRef, $storeId);
    }
    
    private function _sendPxPostRequest($amount, $currency, $txnType, $dpsTxnRef, $storeId)
    {
        $this->_logger->info(__METHOD__ . " amount:{$amount} currency:{$currency} txnType:{$txnType} dpsTxnRef:{$dpsTxnRef} storeId:{$storeId}");
        
        $dataBag = $this->_objectManager->create("\Magento\Framework\DataObject");
        $formattedAmount = $this->_paymentUtil->formatCurrency($amount, $currency);
        
        $dataBag->setUsername($this->_configuration->getPxPostUsername($storeId));
        $dataBag->setPassword($this->_configuration->getPxPostPassword($storeId));
        $dataBag->setPostUrl($this->_configuration->getPxPostUrl($storeId));
        $dataBag->setAmount($formattedAmount);
        $dataBag->setCurrency($currency);
        $dataBag->setDpsTxnRef($dpsTxnRef);
        $dataBag->setTxnType($txnType);
        
        return $this->_pxPost->send($dataBag);
    }
    
    /**
     * Retrieve customer name
     *
     * @return string
     */
    private function _getCustomerName(\Magento\Quote\Model\Quote $quote)
    {
        if ($quote->getBillingAddress()->getName()) {
            $customerName = $quote->getBillingAddress()->getName();
        } elseif ($quote->getShippingAddress()->getName()) {
            $customerName = $quote->getShippingAddress()->getName();
        } elseif ($quote->getCustomerFirstname()) {
            $customerName = $quote->getCustomerFirstname() . ' ' . $quote->getCustomerLastname();
        } else {
            $customerName = (string)__('Guest');
        }

        $this->_logger->info(__METHOD__ . " customerName:{$customerName}");
        return $customerName;
    }
    
    /**
     *
     * @param Magento\Checkout\Model\Quote $quote
     * @param string $returnUrl
     * @param boolean $addBillCard
     */
    private function _buildTransactionParameters($quote, $returnUrl, $addBillCard, $dpsBillingId)
    {
        $this->_logger->info(__METHOD__);
        
        
        $amount = $quote->getBaseGrandTotal();
        $currency = $quote->getBaseCurrencyCode();
        $orderId = $quote->getReservedOrderId();
        $transactionDetail = [
            'amount' => $this->_paymentUtil->formatCurrency($amount, $currency),
            'currency' => $currency,
            'enableAddBillCard' => $addBillCard ? '1' : '0',
            'merchantReference' => $orderId,
            'txnRef' => $quote->getId(),
            'txnType' => $this->_configuration->getPaymentType($quote->getStoreId()),
            'returnUrl' => $returnUrl
        ];

        try {
            $customerName = $this->_getCustomerName($quote);
            $address = $quote->getBillingAddress();
            if ($address) {
                $streetFull = implode(" ", $address->getStreet()) . " " . $address->getCity() . ", " .
                     $address->getRegion() . " " . $address->getPostcode() . " " . $address->getCountryId();

                $transactionDetail += [
                    'txnData1' => $customerName,
                    'txnData2' => $address->getTelephone(),
                    'txnData3' => $streetFull
                ];
            }
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->_logger->critical($e->_toString());
        }

        if (isset($dpsBillingId) && !empty($dpsBillingId)) {
            $transactionDetailsFields = [
                'transactionDetailsField' => [
                    'fieldName' => 'dpsBillingId',
                    'fieldValue' => $dpsBillingId
                ],
            ];
            $transactionDetail += [
                'transactionDetailsFields' => $transactionDetailsFields
            ];
        }

        $userName = $this->_configuration->getUserName();
        $parameters = [
            'username' => $userName,
            'password' => $this->_configuration->getPassword(),
            'tranDetail' => $transactionDetail
        ];
        
        
        $parametersForLog = [
            'username' => $userName,
            'password' => "*********",
            'tranDetail' => $transactionDetail
        ];
        $this->_logger->info(__METHOD__ . " request: " . var_export($parametersForLog, true));
        return $parameters;
    }
    
    public function void($dpsTxnRef, $storeId)
    {
        $this->_logger->info(__METHOD__ . " dpsTxnRef:{$dpsTxnRef} storeId:{$storeId}");

        $dataBag = $this->_objectManager->create("\Magento\Framework\DataObject");
        
        $dataBag->setUsername($this->_configuration->getPxPostUsername($storeId));
        $dataBag->setPassword($this->_configuration->getPxPostPassword($storeId));
        $dataBag->setPostUrl($this->_configuration->getPxPostUrl($storeId));
        $dataBag->setDpsTxnRef($dpsTxnRef);
        $dataBag->setTxnType("Void");
        
        return $this->_pxPost->send($dataBag);
    }
}
