<?php namespace Comodojo\Extender\Tests\Task;

use \Comodojo\Extender\Tests\Helpers\MockTask;
use \Comodojo\Extender\Tests\Helpers\AbstractTestCase;
use \Comodojo\Extender\Task\Table;
use \Comodojo\Extender\Task\Manager;
use \Comodojo\Extender\Task\Request;
use \Comodojo\Extender\Task\TaskParameters;
use \Comodojo\Extender\Orm\Entities\Worklog;

class ManagerTest extends AbstractTestCase {

    protected $table;

    protected function setUp() {

        $this->table = new Table(self::$configuration, self::$logger, self::$events);
        $this->table->add('test', '\Comodojo\Extender\Tests\Helpers\MockTask', 'mocktask');

    }

    public function testSimpleExecution() {

        $manager = $this->createManager();

        $manager->add(new Request('runnertest1', 'test'));
        $manager->add(new Request('runnertest2', 'test'));
        $manager->add(new Request('runnertest3', 'test'));
        $manager->add(new Request('runnertest4', 'test'));
        $manager->add(new Request('runnertest5', 'test'));
        $results = $manager->run();

        foreach ($results as $uid => $result) {
            $this->assertInstanceOf('\Comodojo\Extender\Task\Result', $result);
            $this->assertEquals($uid, $result->uid);
            $this->assertEquals(42, $result->result);
            $this->assertContains('runnertest', $result->name);
            $this->assertEquals(Worklog::STATUS_FINISHED, $result->success);
        }

    }

    public function testChainExecution() {

        $manager = $this->createManager();

        $manager->add(
            Request::create('runnertest1', 'test')->onDone(
                Request::create('runnertest1.1', 'test')
            )
        );

        $manager->add(
            Request::create('runnertest2', 'test')->onFail(
                Request::create('runnertest2.1', 'test')
            )
        );

        $manager->add(
            Request::create('runnertest3', 'test')->pipe(
                Request::create('runnertest3.1', 'test')->pipe(
                    Request::create('runnertest3.3', 'test')->pipe(
                        Request::create('runnertest3.4', 'test')
                    )
                )
            )
        );

        $results = $manager->run();

        foreach ($results as $uid => $result) {
            $this->assertInstanceOf('\Comodojo\Extender\Task\Result', $result);
            $this->assertEquals($uid, $result->uid);
            $this->assertEquals(42, $result->result);
            $this->assertContains('runnertest', $result->name);
            $this->assertEquals(Worklog::STATUS_FINISHED, $result->success);
        }

    }

    protected function createManager() {

        return new Manager(
            "manager-test",
            self::$configuration,
            self::$logger,
            $this->table,
            self::$events,
            self::$em
        );

    }

}
