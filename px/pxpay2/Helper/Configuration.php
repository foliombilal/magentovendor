<?php
namespace PaymentExpress\PxPay2\Helper;

use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Magento\Framework\Module\ModuleListInterface;

class Configuration extends AbstractHelper
{
    const PXPAY2_PATH = "payment/paymentexpress_pxpay2/";
    const MODULE_NAME = "PaymentExpress_PxPay2";

    /**
     *
     * @var \Magento\Framework\Module\ModuleListInterface
     */
    private $_moduleList;

    /**
     *
     * @var \Magento\Framework\App\ProductMetadataInterface
     */
    private $_productMetadata;

    public function __construct(Context $context)
    {
        parent::__construct($context);
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_moduleList = $objectManager->get("Magento\Framework\Module\ModuleListInterface");
        $this->_productMetadata = $objectManager->get("Magento\Framework\App\ProductMetadataInterface");
        $this->_logger = $objectManager->get("PaymentExpress\PxPay2\Logger\DpsLogger");
    }

    public function getModuleVersion()
    {
        $version = "unknown";
        if ($this->_productMetadata != null) {
            $version = $this->_productMetadata->getVersion();
        }

        if ($this->_moduleList == null) {
            return "M2:" . $version. " ext:unknown";
        }
        return "M2:" . $version . " ext:" . $this->_moduleList->getOne(self::MODULE_NAME)['setup_version'];
    }

    public function isValidForPxPay($storeId = null)
    {
        $success = true;
        $len = strlen($this->getPxPayUserId($storeId));
        if ($len < 1 || $len > 32) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPay Username");
            $success = false;
        }

        $len = strlen($this->getPxPayKey($storeId));
        if ($len < 1 || $len > 64) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPay Key");
            $success = false;
        }

        if (filter_var($this->getPxPayUrl($storeId), FILTER_VALIDATE_URL) === false) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPay URL");
            $success = false;
        }

        return $success;
    }

    public function isValidForPxPost($storeId = null)
    {
        $success = true;
        $len = strlen($this->getPxPostUsername($storeId));
        if ($len < 1 || $len > 27) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPost Username");
            $success = false;
        }

        $len = strlen($this->getPxPassword($storeId));
        if ($len < 1) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPost password");
            $success = false;
        }

        if (filter_var($this->getPxPostUrl($storeId), FILTER_VALIDATE_URL) === false) {
            $this->_logger->warn(__METHOD__ . " Invalid PxPost URL");
            $success = false;
        }

        return $success;
    }

    /**
     * @return array
     */
    public function getRedirectOnErrorDetails($storeId = null)
    {
        $redirectValue = $this->_getPxPay2StoreConfig("redirectonerror", $storeId);
        if ($redirectValue == \PaymentExpress\PxPay2\Model\Config\Source\RedirectOnErrorOptions::PAYMENT_INFO) {
            return [ 'url' => 'checkout', 'params' => [ '_fragment' => 'payment' ] ];
        }
        return [ 'url' => 'checkout/cart', 'params' => [] ];
    }

    /**
     * @return int
     */
    public function getRedirectOnErrorMode($storeId = null)
    {
        $redirectValue = $this->_getPxPay2StoreConfig("redirectonerror", $storeId);
        if ($redirectValue == \PaymentExpress\PxPay2\Model\Config\Source\RedirectOnErrorOptions::PAYMENT_INFO) {
            return 1;
        }
        return 0;
    }

    public function getLocksFolder($storeId = null)
    {
        return $this->_getPxPay2StoreConfig("locksFolder", $storeId);
    }

    public function getPxPayUserId($storeId = null)
    {
        return $this->_getPxPay2StoreConfig("pxPayUserId", $storeId);
    }

    public function getPxPayKey($storeId = null)
    {
        return $this->_getPxPay2StoreConfig("pxPayKey", $storeId, true);
    }

    public function getPxPayUrl($storeId = null)
    {
        return $this->_getPxPay2StoreConfig("pxPayUrl", $storeId);
    }

    public function getEnabled($storeId = null)
    {
        return filter_var($this->_getPxPay2StoreConfig("active", $storeId), FILTER_VALIDATE_BOOLEAN);
    }

    public function getAllowRebill($storeId = null)
    {
        return filter_var($this->_getPxPay2StoreConfig("allowRebill", $storeId), FILTER_VALIDATE_BOOLEAN);
    }

    public function getPaymentType($storeId = null)
    {
        return (string)$this->_getPxPay2StoreConfig("paymenttype", $storeId);
    }

    public function getForceA2A($storeId = null)
    {
        return filter_var($this->_getPxPay2StoreConfig("forcea2a", $storeId), FILTER_VALIDATE_BOOLEAN);
    }

    public function getPxPostUsername($storeId = null)
    {
        return $this->_getPxPay2StoreConfig("pxpostusername", $storeId);
    }

    public function getPxPassword($storeId = null)
    {
        return $this->_getPxPay2StoreConfig("pxpostpassword", $storeId, true);
    }

    public function getPxPostUrl($storeId = null)
    {
        return $this->_getPxPay2StoreConfig("pxposturl", $storeId);
    }

    public function getMerchantLinkData($storeId = null)
    {
        return [
            "Url" => $this->_getPxPay2StoreConfig("merchantLinkUrl", $storeId),
            "Text" => $this->_getPxPay2StoreConfig("merchantLinkText", $storeId)
        ];
    }

    public function getMerchantText($storeId = null)
    {
        return $this->_getPxPay2StoreConfig("merchantText", $storeId);
    }

    public function getLogoSource($logoPrefix, $storeId = null)
    {
        return $this->_getPxPay2StoreConfig($logoPrefix . "Source", $storeId);
    }

    public function getLogoAlt($logoPrefix, $storeId = null)
    {
        return $this->_getPxPay2StoreConfig($logoPrefix . "Alt", $storeId);
    }

    public function getLogoHeight($logoPrefix, $storeId = null)
    {
        return (int)$this->_getPxPay2StoreConfig($logoPrefix . "Height", $storeId);
    }

    public function getLogoWidth($logoPrefix, $storeId = null)
    {
        return (int)$this->_getPxPay2StoreConfig($logoPrefix . "Width", $storeId);
    }

    private function _getPxPay2StoreConfig($configName, $storeId = null, $isSensitiveData = false)
    {
        $this->_logger->info("Configuration::_getPxPay2StoreConfig storeId argument:" . $storeId);
        
        $value = $this->scopeConfig->getValue(self::PXPAY2_PATH . $configName, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
        
        if (!$isSensitiveData) {
            $this->_logger->info(__METHOD__ . " configName:{$configName} storeId:{$storeId} value:{$value}");
        } else {
            $this->_logger->info(__METHOD__ . " configName:{$configName} storeId:{$storeId} value:*****");
        }
        return $value;
    }
}
