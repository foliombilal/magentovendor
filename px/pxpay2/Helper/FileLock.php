<?php

namespace PaymentExpress\PxPay2\Helper;

class FileLock
{
    private $_fileName;
    private $_fileHandle;

    public function __construct($lockName, $lockPath = null)
    {
        $lockPath = $lockPath ?: sys_get_temp_dir();

        if (!is_dir($lockPath)) {
            $fileSystem = new \Symfony\Component\Filesystem\Filesystem();
            $fileSystem->mkdir($lockPath);
        }

        if (!is_writable($lockPath)) {
            throw new \Exception(sprintf('The directory "%s" is not writable.', $lockPath));
        }

        $this->_fileName = sprintf('%s/px.%s.%s.lock', $lockPath, preg_replace('/[^a-z0-9\._-]+/i', '-', $lockName), hash('sha256', $lockName));
    }

    public function tryLock($isBlocking = false)
    {
        if ($this->_fileHandle) {
            return true;
        }

        $error = null;

        set_error_handler(function ($errno, $msg) use (&$error) {
            $error = $msg;
        });

        $this->_fileHandle = fopen($this->_fileName, 'r');
        if (!$this->_fileHandle) {
            $this->_fileHandle = fopen($this->_fileName, 'x');
            if ($this->_fileHandle) {
                chmod($this->_fileHandle, 0444);
            } else {
                $this->_fileHandle = fopen($this->_fileName, 'r');
                if (!$this->_fileHandle) {
                    usleep(100);
                    $this->_fileHandle = fopen($this->_fileName, 'r');
                }
            }
        }
        restore_error_handler();

        if (!$this->_fileHandle) {
            throw new \Exception($error);
        }

        // On Windows, even if PHP doc says the contrary, LOCK_NB works, see https://bugs.php.net/54129
        if (!flock($this->_fileHandle, LOCK_EX | ($isBlocking ? 0 : LOCK_NB))) {
            fclose($this->_fileHandle);
            $this->_fileHandle = null;

            return false;
        }

        return true;
    }

    public function release()
    {
        if ($this->_fileHandle) {
            flock($this->_fileHandle, LOCK_UN | LOCK_NB);
            fclose($this->_fileHandle);
            $this->_fileHandle = null;
        }
    }
}
