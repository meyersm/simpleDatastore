<?php
require ('../simpleDatastore.php');

class simpleDatastoreTest extends PHPUnit_Framework_TestCase
{

    public function testCreateNewDatastore()
    {
        $datastore = new simpleDatastore("teststore");
        $datastore->lobsters = array("bob","archibold","thomas");
        $datastore->lobster_max_power = 50;
        $ret = $datastore->save();
        $datastore->close();
        $this->assertGreaterThan(0,$ret);
    }

    /**
     * @depends testCreateNewDatastore
     */
    public function testLoadDatastore()
    {
        $datastore = new simpleDatastore("teststore");
        $this->assertEquals($datastore->lobster_max_power,50);
    }

    /**
     * @depends testLoadDatastore
     */
    public function testAdvancedFunctionality()
    {
        $datastore = new simpleDatastore("teststore");
        $datastore['lobster_king'] = array("name"=>'Lobstonia the cruel',"lobster_power"=>50,"age"=>34,"powers"=>"Claw attack");
        $datastore[] = "welcome";
        $datastore->save();
        $datastore->close();
        $newdata = new simpleDatastore("teststore");
        $this->assertEquals($newdata->lobster_king['lobster_power'],50);
        $this->assertEquals($newdata['lobster_king']['powers'],'Claw attack');
        $this->assertTrue(strpos($newdata,'welcome') !== false);
        print($newdata);
        $newdata->destroy();

    }

    public function testFileLocking()
    {
        $datastore = new simpleDatastore("popular");
        $datastore->value = 1;
        $datastore->save(true);
        unset($datastore);

        $teamA = new simpleDatastore();
        $teamA->timeBetweenLockAttempts = 0.1;
        $teamA->debug_mode = true;
        $teamB = new simpleDatastore();
        $teamB->timeBetweenLockAttempts = 0.1;
        $teamB->debug_mode = true;
        $teamB->error_mode = simpleDatastore::$ERROR_MODE_SILENT;

        $teamA->open("popular");
        $teamA->value = 2;
        $teamA->save();

        $teamB->open("popular");
        $teamB->value = 3;

        $teamA->value = 900;
        $teamA->save(true);
        $teamB->save(true);
        unset($teamA);
        unset($teamB);
        $datastore = new simpleDatastore("popular");
        $this->assertEquals($datastore->value,900);
        $datastore->destroy();
    }

    public function testReadOnly()
    {
        $datastore = new simpleDatastore("popular");
        $datastore->value = 1;
        $datastore->save(true);
        unset($datastore);

        $teamA = new simpleDatastore();
        $teamA->timeBetweenLockAttempts = 0.1;
        $teamA->debug_mode = true;
        $teamB = new simpleDatastore(null,true);
        $teamB->timeBetweenLockAttempts = 0.1;
        $teamB->debug_mode = true;
        $teamB->error_mode = simpleDatastore::$ERROR_MODE_SILENT;

        $teamA->open("popular");
        $teamA->value = 2;
        $teamA->save();

        $teamB->open("popular");
        $this->assertEquals($teamA->value,$teamB->value);
        $teamB->value = 3;

        $teamA->value = 900;
        $teamA->save();
        $teamB->open('popular');
        $this->assertEquals($teamA->value,$teamB->value);
        unset($teamB);
        $teamA->destroy();
    }


}
