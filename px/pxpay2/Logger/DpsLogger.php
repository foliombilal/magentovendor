<?php
namespace PaymentExpress\PxPay2\Logger;

use \Monolog\Logger;

global $pxLoggerRequestId;

// Refer to vendor\monolog\monolog\src\Monolog\Logger.php
// Log to separate file
class DpsLogger extends \Monolog\Logger
{

    public function __construct($name, array $handlers = [], array $processors = [])
    {
        global $pxLoggerRequestId;

        parent::__construct($name, $handlers, $processors);
    
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $productMetadata = $objectManager->get('\Magento\Framework\App\ProductMetadataInterface');
            $version = $productMetadata->getVersion();
            if (!isset($pxLoggerRequestId)) {
                $pxLoggerRequestId = uniqid();
            }
                
            $requestId = $pxLoggerRequestId;
            $this->pushProcessor(function ($record) use ($version, $requestId) {
                $record['extra']['magentoVersion'] = $version;
                $record['extra']['requestId'] = $requestId;
                return $record;
            });
        } catch (\Exception $e) {
             // print 'Caught exception: ',  $e->getMessage(), "\n";
        }
    }
}
