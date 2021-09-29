<?php
namespace PaymentExpress\PxPay2\Helper\PxFusion;

use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Magento\Framework\Module\ModuleListInterface;

class Configuration extends AbstractHelper
{
    const PXFUSION_PATH = "payment/paymentexpress_pxfusion/";
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
        $this->_productMetadata = $objectManager->get("Magento\Framework\App\ProductMetadataInterface");
        $this->_moduleList = $objectManager->get("\Magento\Framework\Module\ModuleListInterface");
        $this->_logger = $objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
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
    
    public function isValidForPxFusion($storeId = null)
    {
        $success = true;
        $len = strlen($this->getUserName($storeId));
        if ($len < 1 || $len > 32) {
            $this->_logger->warn(__METHOD__ . " Invalid PxFusion Username");
            $success = false;
        }

        $len = strlen($this->getPassword($storeId));
        if ($len < 1 || $len > 64) {
            $this->_logger->warn(__METHOD__ . " Invalid PxFusion Key");
            $success = false;
        }

        if (filter_var($this->getWsdl($storeId), FILTER_VALIDATE_URL) === false) {
            $this->_logger->warn(__METHOD__ . " Invalid PxFusion WSDL URL");
            $success = false;
        }


        if (filter_var($this->getPostUrl($storeId), FILTER_VALIDATE_URL) === false) {
            $this->_logger->warn(__METHOD__ . " Invalid PxFusion Post URL");
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

        $len = strlen($this->getPxPostPassword($storeId));
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

    public function getEnabled($storeId = null)
    {
        return filter_var($this->_getStoreConfig("active", $storeId), FILTER_VALIDATE_BOOLEAN);
    }

    public function getLocksFolder($storeId = null)
    {
        return $this->_getStoreConfig("locksFolder", $storeId);
    }

    /**
     * @return array
     */
    public function getRedirectOnErrorDetails($storeId = null)
    {
        $redirectValue = $this->_getStoreConfig("redirectonerror", $storeId);
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
        $redirectValue = $this->_getStoreConfig("redirectonerror", $storeId);
        if ($redirectValue == \PaymentExpress\PxPay2\Model\Config\Source\RedirectOnErrorOptions::PAYMENT_INFO) {
            return 1;
        }
        return 0;
    }

    
    public function getUserName($storeId = null)
    {
        return $this->_getStoreConfig("username", $storeId);
    }
    
    public function getPassword($storeId = null)
    {
        return $this->_getStoreConfig("password", $storeId, true);
    }
    
    public function getPostUrl($storeId = null)
    {
        return $this->_getStoreConfig("postUrl", $storeId, false);
    }
    
    public function getWsdl($storeId = null)
    {
        return $this->_getStoreConfig("wsdl", $storeId);
    }
    
    public function getPaymentType($storeId = null)
    {
        return (string)$this->_getStoreConfig("paymenttype", $storeId);
    }
    
    public function getPxPostUsername($storeId = null)
    {
        return $this->_getStoreConfig("pxpostusername", $storeId);
    }
    
    public function getPxPostPassword($storeId = null)
    {
        return $this->_getStoreConfig("pxpostpassword", $storeId, true);
    }
    
    public function getPxPostUrl($storeId = null)
    {
        return $this->_getStoreConfig("pxposturl", $storeId);
    }

    public function getAllowRebill($storeId = null)
    {
        return filter_var($this->_getStoreConfig("allowRebill", $storeId), FILTER_VALIDATE_BOOLEAN);
    }

    public function getRequireCvcForRebilling($storeId = null)
    {
        return filter_var($this->_getStoreConfig("requireCvcForRebilling", $storeId), FILTER_VALIDATE_BOOLEAN);
    }
    
    private function _getStoreConfig($configName, $storeId = null, $isSensitiveData = false)
    {
        $this->_logger->info(__METHOD__. " storeId:{$storeId}");
    
        $value = $this->scopeConfig->getValue(self::PXFUSION_PATH . $configName, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    
        if (!$isSensitiveData) {
            $this->_logger->info(__METHOD__ . " storeId:{$storeId} configName:{$configName} value:{$value}");
        } else {
            $this->_logger->info(__METHOD__ . " storeId:{$storeId} configName:{$configName} value:*****");
        }
        return $value;
    }
}
