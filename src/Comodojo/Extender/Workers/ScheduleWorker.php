<?php namespace Comodojo\Extender\Workers;

use \Comodojo\Daemon\Worker\AbstractWorker;
use \Comodojo\Extender\Task\Manager as TaskManager;
use \Comodojo\Extender\Task\Locker;
use \Comodojo\Extender\Schedule\Manager as ScheduleManager;
use \Comodojo\Foundation\Base\ConfigurationTrait;
use \Comodojo\Extender\Traits\TasksTableTrait;
use \Comodojo\Extender\Traits\WorkerTrait;
use \Comodojo\Foundation\Logging\LoggerTrait;
use \Comodojo\Foundation\Events\EventsTrait;

class ScheduleWorker extends AbstractWorker {

    use ConfigurationTrait;
    use LoggerTrait;
    use EventsTrait;
    use TasksTableTrait;
    use WorkerTrait;

    protected $locker;

    protected $wakeup_time = 0;

    public function spinup() {

        $configuration = $this->getConfiguration();

        $base_path = $configuration->get('base-path');
        $lock_path = $configuration->get('run-path');
        $lock_file = "$base_path/$lock_path/schedule.worker.lock";

        $this->locker = new Locker($lock_file);
        $this->locker->lock([]);

        $this->getEvents()->subscribe('daemon.worker.refresh', '\Comodojo\Extender\Listeners\RefreshScheduler');

    }

    public function loop() {

        if ( $this->wakeup_time > time() ) {
            $this->logger->debug('Still in sleep time, next planned wakeup is '.date('r', $this->wakeup_time));
            return;
        }

        $schedule_manager = new ScheduleManager(
            $this->getConfiguration(),
            $this->getLogger(),
            $this->getEvents()
        );

        $task_manager = new TaskManager(
            $this->locker,
            $this->getConfiguration(),
            $this->getLogger(),
            $this->getTasksTable(),
            $this->getEvents()
        );

        $jobs = $schedule_manager->getAll(true);

        if ( empty($jobs) ) {

            $this->logger->debug('Nothing to do right now, sleeping... zzZZzZzZzz');

        } else {

            $this->logger->debug(count($jobs)." jobs will be executed");

            $requests = $this->jobsToRequests($jobs);

            $results = $task_manager->addBulk($requests)->run();

            $schedule_manager->updateFromResults($results);

        }

        $this->wakeup_time = $schedule_manager->getNextCycleTimestamp();

        unset($task_manager);
        unset($schedule_manager);

        $this->locker->lock([]);

    }

    public function spindown() {

        $this->locker->release();

    }

    public function refreshPlans() {

        $this->wakeup_time = 0;

    }

}
