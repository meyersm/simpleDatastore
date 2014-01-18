simpleDatastore
===============

A PHP library to store objects between sessions using only the local file system.

Objects can be stored in json or serialized formats and be opened in read only mode or a read/write mode that locks the datastore to prevent overwriting or race conditions.

Good uses for simpleDatastore
* Need to preserve data between scripts/sessions and a database is overkill or not feasable
* Need to share data with another non-php script/code and json is the most convienient
* Fallback to preserve data incase a database connection failed.

This library should NOT be used as a replacement for a database in any application that has high traffic and many concurrent writes, while the files are locked while writing, every other thread/script that wants to write will have to wait for the lock causing slowdowns. 

Usage
-------

Creating a new Datastore:
```php
$datastore = new simpleDatastore("datastore-name");
```
Then you can start adding data, and save to the new datastore
```php
$datastore->fish_hooks = 50;
$datastore->fish_names = array("bob","archibold","thomas");
$datastore->enable_fish= true;
$datastore['array_access'] = "yup";
$datastore[] = "new array entry";
$datastore->save();
```
The datastore still has a write lock on the file until the file is closed, at that point another script can open and read the data
```php
$datastore->close();
//You can also call $datastore->save(true) to save then close in 1 line
$NEWdatastore = new simpleDatastore("datastore-name");
print $NEWdatastore->fish_hooks; //50
print $NEWdatastore['fish_names'][0] //bob
```

When you try to open a datastore that already has a lock on it, the library will re-try for a configurable amount of time then error out.
If you want to access a datastore while its locked, you can open it in read only mode
```php
$datastore = new simpleDatastore("myDatastore",true);
```

Working with php classes that would be better stored as serialized objects instead of json? Set the serialize flag to true when opening a datastore
```php
$datastore = new simpleDatastore("myDatastore",false,true);
$datastore->complexClass = $CC;
$datastore->otherDatastore = $someOtherDatastore //You can even serialize other simpleDatastore objects if you like. 
```
By default the library will store all datastores and thier lock files in a sub directory called "datastore". It also throws exceptions in case of an error, if you would like to change either of those defaults you can.
```php
$datastore = new simpleDatastore("teststore",false,false,"anotherDirectory"); //Different directory for datastore files
$teamB = new simpleDatastore(); //If you are going to change the error mode, you may want to wait to load the file instead of doing so at instantiation

$teamB->error_mode = simpleDatastore::$ERROR_MODE_SILENT; //Fails silently, will leave datastore null if error on read
$teamB->error_mode = simpleDatastore::$ERROR_MODE_DIE; //Dies on fatal error, ending script execution
$teamB->error_mode = simpleDatastore::$ERROR_MODE_EXCEPTION; //Default, throws exceptions on fatal errors
$teamB->open("teststore");
```
To change the way the library handles a locked datastore file, use the setLockConfig function. Again if doing this, delay opening the datastore file like in the example above
```php
    /**
     * @param int $secondsBetweenLockAttempts Seconds to wait between lock attempts
     * @param int $lockAttempts Number of tries to try and lock datastore
     */
    public function setLockConfig($secondsBetweenLockAttempts=1,$lockAttempts=20)
```

For more examples on how to use this library, check out the unit tests 

[Unit tests](tests/simpleDatastoreTest.php)

