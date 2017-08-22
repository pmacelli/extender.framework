<?php namespace Comodojo\Extender;

use \Comodojo\Extender\Workers\ScheduleWorker;
use \Comodojo\Extender\Workers\QueueWorker;
use \Comodojo\Extender\Task\Table as TasksTable;
use \Comodojo\Extender\Task\Request;
use \Comodojo\Extender\Task\TaskParameters;
use \Comodojo\Extender\Traits\ConfigurationTrait;
use \Comodojo\Extender\Traits\TasksTableTrait;
use \Comodojo\Extender\Traits\EntityManagerTrait;
use \Comodojo\Extender\Components\Database;
use \Comodojo\Extender\Queue\Manager as QueueManager;
use \Comodojo\Extender\Schedule\Manager as ScheduleManager;
use \Comodojo\Extender\Orm\Entities\Schedule;
use \Comodojo\Extender\Traits\CacheTrait;
use \Comodojo\Daemon\Daemon as AbstractDaemon;
use \Comodojo\Foundation\Logging\LoggerTrait;
use \Comodojo\Foundation\Events\EventsTrait;
use \Comodojo\Foundation\Events\Manager as EventsManager;
use \Comodojo\Foundation\Base\Configuration;
use \Comodojo\Foundation\Logging\Manager as LogManager;
use \Comodojo\Foundation\Utils\ArrayOps;
use \Comodojo\SimpleCache\Manager as SimpleCacheManager;
use \Doctrine\ORM\EntityManager;
use \Psr\Log\LoggerInterface;

class ExtenderDaemon extends AbstractDaemon {

    use ConfigurationTrait;
    use EventsTrait;
    use LoggerTrait;
    use CacheTrait;
    use TasksTableTrait;
    use EntityManagerTrait;

    protected static $default_properties = array(
        'pidfile' => '',
        'socketfile' => '',
        'socketbuffer' => 8192,
        'sockettimeout' => 15,
        'niceness' => 0,
        'arguments' => '\\Comodojo\\Daemon\\Console\\DaemonArguments',
        'description' => 'Extender Daemon'
    );

    public function __construct(
        array $configuration,
        array $tasks,
        EventsManager $events = null,
        SimpleCacheManager $cache = null,
        LoggerInterface $logger = null
    ) {

        $this->configuration = new Configuration(self::$default_properties);
        $this->configuration->merge($configuration);

        $run_path = $this->getRunPath();

        if ( empty($this->configuration->get('socketfile')) ) {
            $this->configuration->set('socketfile', "unix://$run_path/extender.sock");
        }

        if ( empty($this->configuration->get('pidfile')) ) {
            $this->configuration->set('pidfile', "$run_path/extender.pid");
        }

        $logger = is_null($logger) ? LogManager::createFromConfiguration($this->configuration)->getLogger() : $logger;
        $events = is_null($events) ? EventsManager::create($logger) : $events;

        parent::__construct(ArrayOps::replaceStrict(self::$default_properties, $this->configuration->get()), $logger, $events);

        $table = new TasksTable($this->configuration, $this->getLogger(), $this->getEvents());
        $table->addBulk($tasks);
        $this->setTasksTable($table);

        $this->setCache(is_null($cache) ? SimpleCacheManager::createFromConfiguration($this->configuration, $this->logger) : $cache);

        $this->setEntityManager(Database::init($this->configuration)->getEntityManager());

    }

    public function setup() {

        $this->installWorkers();

        $this->pushQueueCommands();
        $this->pushScheduleCommands();

    }

    protected function installWorkers() {

        // add workers
        $manager = $this->getWorkers();

        $schedule_worker = new ScheduleWorker("scheduler");
        $schedule_worker
            ->setConfiguration($this->getConfiguration())
            ->setLogger($this->getLogger())
            ->setEvents($this->getEvents())
            ->setTasksTable($this->getTasksTable())
            ->setEntityManager($this->getEntityManager());

        $queue_worker = new QueueWorker("queue");
        $queue_worker
            ->setConfiguration($this->getConfiguration())
            ->setLogger($this->getLogger())
            ->setEvents($this->getEvents())
            ->setTasksTable($this->getTasksTable())
            ->setEntityManager($this->getEntityManager());

        $manager
            ->install($schedule_worker, 1, true)
            ->install($queue_worker, 1, true);

    }

    protected function pushQueueCommands() {

        $this->getSocket()->getCommands()
            ->add('queue:add', function(Request $request, $daemon) {
                $manager = new QueueManager(
                    $this->getConfiguration(),
                    $this->getLogger(),
                    $this->getEvents(),
                    $this->getEntityManager()
                );

                return $manager->add($name, $request);
            })
            ->add('queue:addBulk', function(array $requests, $daemon) {

                $manager = new QueueManager(
                    $this->getConfiguration(),
                    $this->getLogger(),
                    $this->getEvents(),
                    $this->getEntityManager()
                );

                return $manager->addBulk($requests);

            });

    }

    protected function pushScheduleCommands() {

        $this->getSocket()->getCommands()
            ->add('scheduler:refresh', function($data, $daemon) {

                return $this->getWorkers()->get("scheduler")->getOutputChannel()->send('refresh');

            })
            ->add('scheduler:add', function(Schedule $data, $daemon) {

                $manager = new ScheduleManager(
                    $this->getConfiguration(),
                    $this->getLogger(),
                    $this->getEvents(),
                    $this->getEntityManager()
                );

                $id = $manager->add($data);

                $this->getWorkers()->get("scheduler")->getOutputChannel()->send('refresh');

                return $id;

            })
            ->add('scheduler:get', function($id, $daemon) {

                $manager = new ScheduleManager(
                    $this->getConfiguration(),
                    $this->getLogger(),
                    $this->getEvents(),
                    $this->getEntityManager()
                );

                return $manager->get($id);

            })
            ->add('scheduler:getByName', function($name, $daemon) {

                $manager = new ScheduleManager(
                    $this->getConfiguration(),
                    $this->getLogger(),
                    $this->getEvents(),
                    $this->getEntityManager()
                );

                return $manager->getByName($name);

            })
            ->add('scheduler:edit', function(Schedule $data, $daemon) {

                $manager = new ScheduleManager(
                    $this->getConfiguration(),
                    $this->getLogger(),
                    $this->getEvents(),
                    $this->getEntityManager()
                );

                $edit = $manager->edit($data);

                $this->getWorkers()->get("scheduler")->getOutputChannel()->send('refresh');

                return $edit;

            })
            ->add('scheduler:enable', function($name, $daemon) {

                $manager = new ScheduleManager(
                    $this->getConfiguration(),
                    $this->getLogger(),
                    $this->getEvents(),
                    $this->getEntityManager()
                );

                $edit = $manager->enable($name);

                $this->getWorkers()->get("scheduler")->getOutputChannel()->send('refresh');

                return $edit;

            })
            ->add('scheduler:edit', function($name, $daemon) {

                $manager = new ScheduleManager(
                    $this->getConfiguration(),
                    $this->getLogger(),
                    $this->getEvents(),
                    $this->getEntityManager()
                );

                $edit = $manager->disable($name);

                $this->getWorkers()->get("scheduler")->getOutputChannel()->send('refresh');

                return $edit;

            });

    }

    private function getRunPath() {
        return $this->configuration->get('base-path')."/".$this->configuration->get('run-path');
    }

}
