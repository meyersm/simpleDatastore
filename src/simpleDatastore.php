<?php
/*
 * Michael Meyers
 * A Simple class to store data on the local filesystem without any additional php libraries
 */
namespace meyersm;

class simpleDatastore implements \ArrayAccess{


    public $debug_mode = false;
    public $error_mode = 0;
    //Throw Exceptions on fatal error
    static $ERROR_MODE_EXCEPTION = 0;
    //Die on fatal error
    static $ERROR_MODE_DIE = 1;
    //Fail Silently, leave datastore Null
    static $ERROR_MODE_SILENT = 2;

    protected $format;
    protected $availible_formats = array('json','serial');
    protected $datastoreDirectoryPath = null;
    protected $datastoreDirectoryName = 'datastore';
    //TODO: allow full path to be provided for datastore folder
    protected $activeLock;
    protected $timeBetweenLockAttempts = 1;
    protected $lockAttempts = 20;
    protected $lockFileHandler;

    protected $readOnly;
    protected $currentDatastore;
    protected $datastoreObject;

    /**
     * @param null $datastore Datastore name, null to delay file open until later
     * @param bool $readOnly TRUE to open in read only mode, works on locked files
     * @param bool $useSerialize Use php serialization instead of converting to JSON objects
     * @param null $datastoreDirectory Directory to access/store datastores, leave as null for default
     */
    public function __construct($datastore=null,$readOnly=false,$useSerialize=false,$datastoreDirectory=null)
    {
        $this->readOnly = $readOnly;
        if ($datastoreDirectory != null)
            $this->datastoreDirectoryName = $datastoreDirectory;
        $this->datastoreDirectoryPath = realpath(dirname(__FILE__)) . '/'.$this->datastoreDirectoryName.'/';
        if (!$this->initDirectory())
            $this->throwError("Could not create or access datastore directory");
        if ($useSerialize)
            $this->format = $this->availible_formats[1];
        else
            $this->format = $this->availible_formats[0];

        if ($datastore != null)
            $this->openDatastore($datastore);
        return $this;
    }

    public function __destruct()
    {
        if ($this->activeLock)
            $this->unlockFile();

    }

    /**
     * @param int $secondsBetweenLockAttempts Seconds to wait between lock attempts
     * @param int $lockAttempts Number of tries to try and lock datastore
     */
    public function setLockConfig($secondsBetweenLockAttempts=1,$lockAttempts=20)
    {
        $this->timeBetweenLockAttempts = $secondsBetweenLockAttempts;
        $this->lockAttempts = $lockAttempts;
    }

    /**
     * @return array
     */
    public function getLockConfig()
    {
        return array('secondsBetweenLockAttempts'=>$this->timeBetweenLockAttempts,'lockAttempts'=>$this->lockAttempts);
    }

    /**
     * @param String $datastore Datastore name
     * @return bool TRUE if open was successful
     */
    public function open($datastore)
    {
        if ($this->activeLock)
            $this->unlockFile();
        return $this->openDatastore($datastore);
    }

    /**
     * @param bool $releaseLock Set to true to release file lock after save (same as calling save then close)
     * @return bool|int number of characters written or FALSE for failure
     */
    public function save($releaseLock=false)
    {
        $ret = false;
        if ($this->readOnly)
            $this->throwError("Cannot write to a datastore opened in read only mode");
        else
            $ret = $this->closeDatastore();
        if ($releaseLock)
            $this->unlockFile();
        return $ret;
    }

    /**
     * close current datastore and release file lock
     */
    public function close()
    {
        if ($this->activeLock)
            $this->unlockFile();
        $this->currentDatastore = null;
        $this->datastoreObject = array();
    }

    /**
     * Delete datastore, this will remove the file and is not recoverable
     */
    public function destroy()
    {
        if ($this->activeLock)
            $this->unlockFile();
        $this->datastoreObject = array();
        if ($this->currentDatastore == null)
            return;
        if (is_file($this->getDataFile()))
            unlink($this->getDataFile());
        if (is_file($this->getLockFile()))
            unlink($this->getLockFile());
    }

    //Array Access Interface
    public function offsetSet($offset,$value)
    {
        if (is_null($offset)) {
            $this->datastoreObject[] = $value;
        } else {
            $this->datastoreObject[$offset] = $value;
        }
        return $this;
    }
    public function offsetExists($offset)
    {
        return isset($this->datastoreObject[$offset]);
    }
    public function offsetUnset($offset)
    {
        unset($this->datastoreObject[$offset]);
    }
    public function offsetGet($offset)
    {
        return isset($this->datastoreObject[$offset]) ? $this->datastoreObject[$offset] : null;
    }


    //PHP Magic functions
    public function __get($name)
    {
        if (isset($this->datastoreObject[$name]))
            return $this->datastoreObject[$name];
        else
            return null;
    }

    public function __set($name,$value)
    {
        return $this->datastoreObject[$name] = $value;
    }

    public function __isset($name)
    {
        return isset($this->datastoreObject[$name]);
    }

    public function __toString()
    {
        return print_r($this->datastoreObject,true);
    }

    public function __sleep()
    {
        if ($this->activeLock)
            $this->unlockFile();
        return array('timeBetweenLockAttempts','debug_mode','error_mode','format',
            'datastoreDirectoryPath','datastoreDirectoryName','activeLock','lockAttempts',
            'lockFileHandler','readOnly','currentDatastore','datastoreObject');
    }

    public function __wakeup()
    {
        if ($this->currentDatastore != null)
            $this->open($this->currentDatastore);
    }

    /**************************** Internal functions *********************/


    //Data Store Access functions
    /**
     * @return string
     */
    protected function getDataFile()
    {
        return $this->datastoreDirectoryPath . $this->currentDatastore . '.' . $this->format;
    }

    /**
     * @return string
     */
    protected function getLockFile()
    {
        return $this->datastoreDirectoryPath . $this->currentDatastore . '.lock';
    }

    /**
     * @param string $datastore Datastore name
     * @return bool TRUE if datastore was opened
     */
    protected function openDatastore($datastore)
    {
        $this->currentDatastore = $datastore;
        if (!$this->readOnly)
        {
            if (!$this->lockFile())
                return false;
        }

        if (!file_exists($this->getDataFile()))
        {
            //new file will be created during closeDatastore
            $this->datastoreObject = array();
        }
        else
        {
            $data = file_get_contents($this->getDataFile());
            if ($data === false)
            {
                $this->throwError("Could not open datastore file");
                return false;
            }
            if ($this->format == $this->availible_formats[0])
                $this->datastoreObject = json_decode($data,true);
            else if ($this->format == $this->availible_formats[1])
                $this->datastoreObject = unserialize($data);
        }
        return true;
    }

    /**
     * @return bool|int file_put_contents response, chars written or failure
     */
    protected function closeDatastore()
    {
        if (!$this->activeLock)
        {
            $this->throwError("Do not have an active lock cannot overwrite datastore");
            return false;
        }
        $data = null;
        if ($this->format == $this->availible_formats[0])
            $data = json_encode($this->datastoreObject);
        else if ($this->format == $this->availible_formats[1])
            $data = serialize($this->datastoreObject);
        $response = file_put_contents($this->getDataFile(),$data);
        if ($response === false)
            $this->throwError("Could not write to datastore file");
        return $response;
    }

    //Lock File functions
    /**
     * @return bool
     */
    protected function lockFile()
    {
        $lockfile = $this->getLockFile();
        $this->lockFileHandler = fopen($lockfile,'w+');
        $attempt = 0;
        $locked = false;
        while ($attempt < $this->lockAttempts)
        {
            if(flock($this->lockFileHandler, LOCK_EX | LOCK_NB))
            {
                $this->log("Locked $lockfile after $attempt attempts");
                $locked = true;
                $attempt = PHP_INT_MAX;
            }
            else
            {
                sleep($this->timeBetweenLockAttempts);
                $attempt++;
            }

        }
        if (!$locked)
            $this->throwError("Could not lock $lockfile");
        else
            $this->activeLock = true;
        return $this->activeLock;
    }

    /**
     * @return bool
     */
    protected function unlockFile()
    {
        if (!$this->activeLock)
            return true;
        if (!is_resource($this->lockFileHandler))
            return true;//The file handler was destroyed or dereferenced
        if(!flock($this->lockFileHandler, LOCK_UN))
            $this->throwError("File unlock failed");
        else
            $this->log("Unlocked file for {$this->currentDatastore}");
        $this->activeLock = false;
        return true;
    }

    //Folder Init
    /**
     * @return bool
     */
    protected function initDirectory()
    {
        if (!file_exists($this->datastoreDirectoryPath))
            return mkdir($this->datastoreDirectoryPath, 0777, true);
        return true;
    }

    //Logging
    /**
     * @param SplString $string
     */
    protected function log($string)
    {
        if ($this->debug_mode)
            error_log('[simpleDatastore]['.__FILE__.'] '.$string);
    }

    //Errors
    /**
     * @param $string
     * @throws ErrorException
     */
    protected function throwError($string)
    {
        $this->log("Error thrown: $string");
        if ($this->error_mode == self::$ERROR_MODE_EXCEPTION)
            throw new ErrorException($string);
        else if ($this->error_mode == self::$ERROR_MODE_DIE)
            die($string);
    }


}