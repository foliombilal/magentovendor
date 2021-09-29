<?php
namespace PaymentExpress\PxPay2\Block;

use \Magento\Framework\View\Element\Template\Context;

class Info extends \Magento\Payment\Block\Info
{

     /**
      *
      * @var \Magento\Framework\Serialize\Serializer\Json
      */
    private $_json;

    /**
     * Serializer for encode/decode string/data.
     *
     * @var \Magento\Framework\Serialize\Serializer\Serialize
     */
    private $_serialize;

    /**
     *
     * @var string
     */
    protected $_template = 'PaymentExpress_PxPay2::info/default.phtml';

    public function __construct(Context $context, array $data = [])
    {
        parent::__construct($context, $data);
        
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->_logger = $objectManager->get("\PaymentExpress\PxPay2\Logger\DpsLogger");
        $this->_logger->info(__METHOD__);

        $this->_json = $objectManager->get(\Magento\Framework\Serialize\Serializer\Json::class);
        $this->_serialize = $objectManager->get(\Magento\Framework\Serialize\Serializer\Serialize::class);
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        $this->_logger->info(__METHOD__);
        if (null !== $this->_paymentSpecificInformation) {
            return $this->_paymentSpecificInformation;
        }

        $data = $this->getInfo()->getAdditionalInformation();
        $decodedData = [];
        foreach ($data as $key => $value) {
            if (strtotime($key)) {
                $decodedValue;
                try {
                    $decodedValue = $this->_json->unserialize($value);
                    // TODO: deprecate unserialize completely
                } catch (\Exception $e) {
                    $decodedValue = $this->_serialize->unserialize($value);
                }
                $decodedData[$key] = $decodedValue;
            } elseif ($key !== "PxPayHPPUrl") {
                // We don't want to display the URL in the admin panel
                $decodedData[$key] = $value;
            }
        }
        
        $transport = parent::_prepareSpecificInformation($transport);

        unset($decodedData["Currency"]);
        $this->_paymentSpecificInformation = $transport->setData(array_merge($decodedData, $transport->getData()));

        return $this->_paymentSpecificInformation;
    }

    public function getPxPayUrl()
    {
        $data = $this->getInfo()->getAdditionalInformation();
        if (array_key_exists("PxPayHPPUrl", $data)) {
            return $data["PxPayHPPUrl"];
        }
        return null;
    }
}
