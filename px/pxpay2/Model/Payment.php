<?php

// Magento\Framework\DataObject implements the magic call function

// Create payment module http://www.josephmcdermott.co.uk/basics-creating-magento2-payment-method
// https://github.com/magento/magento2-samples/tree/master/sample-module-payment-provider
namespace PaymentExpress\PxPay2\Model;

use \Magento\Framework\Exception\State\InvalidTransitionException;

class Payment extends \Magento\Payment\Model\Method\AbstractMethod
{
    /**
     *
     * @var \Magento\Framework\App\ObjectManager
     */
    private $_objectManager;

    protected $_code;
    
    protected $_infoBlockType = 'PaymentExpress\PxPay2\Block\Info';

    protected $_isGateway = true;

    protected $_canAuthorize = true;

    protected $_canCapture = true;

    protected $_canCapturePartial = true;

    protected $_canUseInternal = false;

    protected $_canUseCheckout = true;

    protected $_canUseForMultishipping = false;

    protected $_canRefund = false;

    protected $_canRefundInvoicePartial = false;

    protected $_isInitializeNeeded = true;

    /**
     *
     * @var \PaymentExpress\PxPay2\Model\PaymentHelper
     */
    private $_paymentHelper;

    /**
     *
     * @var \PaymentExpress\PxPay2\Model\Api\ApiPxPayHelper
     */
    private $_apiPxPayHelper;

    /**
     *
     * @var \Magento\Framework\Notification\NotifierInterface
     */
    private $_notifierInterface;

    const PXPAY_CODE = "paymentexpress_pxpay2";

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Framework\Notification\NotifierInterface $notifierInterface,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_code = self::PXPAY_CODE;
        $this->_objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $this->_objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
        $this->_apiPxPayHelper = $this->_objectManager->get("\PaymentExpress\PxPay2\Model\Api\ApiPxPayHelper");
        
        /** @var \PaymentExpress\PxPay2\Helper\Configuration $configuration*/
        $configuration = $this->_objectManager->get("\PaymentExpress\PxPay2\Helper\Configuration");
        /** @var \PaymentExpress\PxPay2\Helper\Communication $communication*/
        $communication = $this->_objectManager->get("\PaymentExpress\PxPay2\Helper\Communication");
        $this->_paymentHelper = $this->_objectManager->create("\PaymentExpress\PxPay2\Model\PaymentHelper");
        $this->_paymentHelper->init($configuration, $communication);

        $this->_notifierInterface = $notifierInterface;

        $this->_logger->info(__METHOD__);
    }

    // invoked by Magento\Quote\Model\PaymentMethodManagement::set
    public function assignData(\Magento\Framework\DataObject $data)
    {
        $this->_logger->info(__METHOD__ . " data:" . var_export($data, true));
        $infoInstance = $this->getInfoInstance();

        $info = $infoInstance->getAdditionalInformation();
        if (isset($info) && !empty($info['DpsTxnRef'])) {
            $this->_logger->info(__METHOD__ . " payment finished. DpsTxnRef:" . $info['DpsTxnRef']);
            return $this; //The transaction is processed.
        }
        
        $source = $data;
        if (isset($data['additional_data'])) {
            $source = $this->_objectManager->create("\Magento\Framework\DataObject");
            $source->setData($data['additional_data']);
        }
        
        $info = [];
        $dpsBillingId = $source->getData("paymentexpress_billingId");
        $info["UseSavedCard"] = filter_var($source->getData("paymentexpress_useSavedCard"), FILTER_VALIDATE_BOOLEAN);
        $info["EnableAddBillCard"] = filter_var($source->getData("paymentexpress_enableAddBillCard"), FILTER_VALIDATE_BOOLEAN);
        if (isset($dpsBillingId) && !empty($dpsBillingId)) {
            $info["DpsBillingId"] = $dpsBillingId;
            $info["UseSavedCard"] = true;
            
            $info["EnableAddBillCard"] = false; // Do not add billing token when rebill.
        } else {
            $info["UseSavedCard"] = false;
        }
        $info["cartId"] = $source->getData("cartId");
        $info["guestEmail"] = $source->getData("guestEmail");
        $info["DpsHandler"] = "8";
        
        $infoInstance->setAdditionalInformation($info);
        $infoInstance->save();

        $this->_logger->info(__METHOD__ . " info:" . var_export($info, true));
        return $this;
    }
    
    public function getConfigPaymentAction()
    {
        return $this->_paymentHelper->getConfigPaymentAction($this->getStore());
    }
    
    public function canCapture()
    {
        $payment = $this->getInfoInstance();
        $info = $payment->getAdditionalInformation();
        $this->_canCapture =  $this->_paymentHelper->canCapture($this->getStore(), $info);
        return $this->_canCapture;
    }
    
    // Invoked by Mage_Sales_Model_Order_Payment::capture
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->_logger->info(__METHOD__ . " payment amount:" . $amount);
        $this->_paymentHelper->capture($payment, $amount, $this->getStore());
        return $this;
    }

    public function canVoid()
    {
        $payment = $this->getInfoInstance();
        $info = $payment->getAdditionalInformation();
        $this->_canVoid =  $this->_paymentHelper->canVoid($this->getStore(), $info);
        return $this->_canVoid;
    }

    public function void(\Magento\Payment\Model\InfoInterface $payment)
    {
        $this->_logger->info(__METHOD__);
        parent::void($payment);
        
        $this->_paymentHelper->void($payment, $this->getStore());
        return $this;
    }

    public function cancel(\Magento\Payment\Model\InfoInterface $payment)
    {
        $this->_logger->info(__METHOD__);
        $this->_paymentHelper->void($payment, $this->getStore());
        return $this;
    }

    public function canRefund()
    {
        $this->_logger->info(__METHOD__);
        $payment = $this->getInfoInstance();
        $info = $payment->getAdditionalInformation();
        $this->_canRefund = $this->_paymentHelper->canRefund($info);
        $this->_canRefundInvoicePartial = $this->_canRefund;
        return $this->_canRefund;
    }

    public function canRefundPartialPerInvoice()
    {
        return $this->canRefund();
    }

    // Mage_Sales_Model_Order_Payment::refund
    // use getInfoInstance to get object of Mage_Payment_Model_Info (Mage_Payment_Model_Info::getMethodInstance Mage_Sales_Model_Order_Payment is sub class of Mage_Payment_Model_Info)
    public function refund(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->_logger->info(__METHOD__);
        $this->_paymentHelper->refund($payment, $amount, $this->getStore());
        return $this;
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $this->_logger->info(__METHOD__);
        return $this->_paymentHelper->isAvailable($quote);
    }

    /**
     *
     * @return bool
     */
    public function isInitializeNeeded()
    {
        $payment = $this->getInfoInstance();
        $dpsHandler = $payment->getAdditionalInformation("DpsHandler");
        if (!isset($dpsHandler) || $dpsHandler != "8") {
            return false;
        }

        return parent::isInitializeNeeded();
    }

    /**
     * Method that will be executed instead of authorize or capture
     * if flag isInitializeNeeded set to true
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function initialize($paymentAction, $stateObject)
    {
        parent::initialize($paymentAction, $stateObject);

        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();

        $additionalInfo = $payment->getAdditionalInformation();
        unset($additionalInfo["DpsHandler"]);

        $this->_logger->info(__METHOD__ . " Attempting to generate PxPay link");
        try {
            $pxPayUrl = $this->_apiPxPayHelper->createUrl($order);
            $additionalInfo["PxPayHPPUrl"] = $pxPayUrl;
        } catch (InvalidTransitionException $exception) {
            // TODO: need to do something here
            $quoteId = $order->getQuoteId();
            $this->_notifierInterface->addMajor(
                "Failed to generate PxPay 2.0 session",
                "OrderId: " . $order->getRealOrderId() . ", QuoteId: " . $quoteId .
                ". See Payment Express extension log for more details."
            );

            throw new \Magento\Framework\Exception\LocalizedException(
                __("Internal error while processing quote #{$quoteId}. Please contact support.")
            );
        }

        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus($this->getConfigData('order_status'));
        $stateObject->setIsNotified(false);
        $this->_logger->info(__METHOD__ . " Order status changed to pending payment");

        $order->setCanSendNewEmailFlag(false);
        $order->save();

        $this->_logger->info(__METHOD__ . "  realOrderId:" . $order->getRealOrderId() . " Id:" . $order->getId());

        $payment->unsAdditionalInformation();
        $payment->setAdditionalInformation($additionalInfo);

        return $this;
    }
}
