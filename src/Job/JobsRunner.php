<?php namespace Comodojo\Extender\Job;

use \Exception;

class JobsRunner {

	private $jobs = array();

	private $logger = null;

	private $multithread = false;

	private $completed_processes = array();

	private $running_processes = array();

	private $forked_processes = array();

	private $max_result_bytes_in_multithread = null;

	private $max_childs_runtime = null;

	private $ipc_array = array();

	final public function __construct($logger, $max_result_bytes_in_multithread, $max_childs_runtime) {

		$this->logger = $logger;

		$this->max_result_bytes_in_multithread = $max_result_bytes_in_multithread;

		$this->max_childs_runtime = $max_childs_runtime;

	}

	final public function addJob(\Comodojo\Extender\Job\Job $job) {

		$uid = self::getJobUid();

		try {

			$target = $job->getTarget();

			$class = $job->getClass();

			if ( class_exists("\\Comodojo\\Extender\\Task\\".$class) === false ) {

				if ( !file_exists($target) ) throw new Exception("Task file does not exists");

				if ( (include($target)) === false ) throw new Exception("Task file cannot be included");

			}

			$this->jobs[$uid] = array(
				"name"		=>	$job->getName(),
				"id"		=>	$job->getId(),
				"parameters"=>	$job->getParameters(),
				"task"		=>	$job->getTask(),
				"target"	=>  $target,
				"class"		=>	$class
			);

		}
		catch (Exception $e) {

			$this->logger->error('Error including job',array(
				"JOBUID"=> $uid,
				"ERROR"	=> $e->getMessage(),
				"ERRID"	=> $e->getCode()
			));

			return false;

		}

		return $uid;

	}

	public function run() {

		foreach ($this->jobs as $jobUid => $job) {
			
			if ( $this->multithread AND sizeof($this->jobs) > 1 ) {

				$status = $this->runMultithread($jobUid);

				if ( !is_null($status["pid"]) ) {

					$this->running_processes[$status["pid"]] = array($status["name"], $status["uid"], $status["timestamp"], $status["id"]);

					array_push($this->forked_processes, $status["pid"]);

				}

			} else {

				$status = $this->runSinglethread($jobUid);

				array_push($this->completed_processes, $status);

			}

		}

		if ( $this->multithread ) $this->logger->info("Extender forked ".sizeof($forked)." process(es) in the running queue", $this->forked);

		$exec_time = microtime(true);

		while( !empty($this->running_processes) ) {

			foreach($this->running_processes as $pid => $job) {

				//$job[0] is name
				//$job[1] is uid
				//$job[2] is start timestamp
				//$job[3] is job id

				if( !$this->is_running($pid) ) {

					list($reader,$writer) = $this->ipc_array[$job[1]];

					socket_close($writer);
					
					$parent_result = socket_read($reader, $this->max_result_bytes_in_multithread, PHP_BINARY_READ);

					if ( $parent_result === false ) {

						$this->logger->error("socket_read() failed. Reason: ".socket_strerror(socket_last_error($reader)));

						array_push($this->completed_processes,Array(
							null,
							$job[0],//$job_name,
							false,
							$job[2],//$start_timestamp,
							null,
							"socket_read() failed. Reason: ".socket_strerror(socket_last_error($reader)),
							$job[3]
						));

						$status = 'ERROR';

					} else {

						$result = unserialize(rtrim($parent_result));

						socket_close($reader);
						
						array_push($this->completed_processes,Array(
							$pid,
							$job[0],//$job_name,
							$result["success"],
							$job[2],//$start_timestamp,
							$result["timestamp"],
							$result["result"],
							$job[3]
						));

						$status = 'SUCCESS';

					}
					
					unset($this->running_processes[$pid]);

					$this->logger->info("Removed pid ".$pid." from the running queue, job terminated with ".$status);

				} else {

					$current_time = microtime(true);

					if ($current_time > $exec_time + $this->max_childs_run_time) {

						$this->logger->warning("Killing pid ".$pid." due to maximum exec time reached (>".$this->max_childs_run_time.")", array(
							"START_TIME"	=> $exec_time,
							"CURRENT_TIME"	=> $current_time,
							"MAX_RUNTIME"	=> $this->max_childs_run_time
						));

						$kill = $this->kill($pid);

						if ( $kill ) $this->logger->warning("Pid ".$pid." killed");

						else $this->logger->warning("Pid ".$pid." could not be killed");

						list($reader,$writer) = $this->ipc_array[$job[1]];

						socket_close($writer);
						socket_close($reader);
						
						array_push($this->completed_processes,Array(
							$pid,
							$job[0],//$job_name,
							false,
							$job[2],//$start_timestamp,
							$t,
							"Job ".$job[0]." killed due to maximum exec time reached (>".$this->max_childs_run_time.")",
							$job[3]
						));

						unset($this->running_processes[$pid]);

					}

				}

			}

		}

		return $this->completed_processes;

	}

	public function runSinglethread($jobUid) {

		$job = $this->jobs[$jobUid];

		// get job start timestamp
		$start_timestamp = microtime(true);

		$name = $job['name'];

		$id = $job['id'];

		$parameters = $job['parameters'];

		$task = $job['task'];

		$class = $job['class'];

		$task_class = "\\Comodojo\\Extender\\Task\\".$class;

		try {

			// create a task instance

			$thetask = new $task_class($parameters, null, $name, $start_timestamp, false);

			// get the task pid (we are in singlethread mode)

			$pid = $thetask->getPid();

			// run task

			$result = $thetask->start();
		
		}
		catch (Exception $e) {
		
			return array($pid, $name, false, $start_timestamp, null, $e->getMessage(), $id);
		
		}

		return array($pid, $name, $result["success"], $start_timestamp, $result["timestamp"], $result["result"], $id);

	}

	public function runMultithread($jobUid) {

		$job = $this->jobs[$jobUid];

		// get job start timestamp
		$start_timestamp = microtime();

		$name = $job['name'];

		$id = $job['id'];

		$parameters = $job['parameters'];

		$task = $job['task'];

		$class = $job['class'];

		$task_class = "\\Comodojo\\Extender\\Task\\".$class;

		$this->ipc_array[$jobUid] = array();

		// create a comm socket
		$socket = socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $this->ipc_array[$jobUid]);

		if ( $socket === false ) {

			$this->logger->error("No IPC communication, aborting", array(
				"JOBUID"=> $jobUid,
				"ERROR"	=> socket_strerror(socket_last_error()),
				"ERRID"	=> null
			));

			array_push($this->completed_processes, array(
				null,
				$name,
				false,
				$start_timestamp,
				microtime(true),
				'No IPC communication, exiting - '.socket_strerror(socket_last_error()),
				$id
			));

			return array(
				"pid"		=>	null,
				"name"		=>	$name,
				"uid"		=>	$jobUid,
				"timestamp"	=>	$start_timestamp,
				"id"		=>	$id
			);

		}

		list($reader,$writer) = $this->ipc_array[$jobUid];

		$pid = @pcntl_fork();

		if( $pid == -1 ) {

			$this->logger->error("Could not fok job, aborting");

			array_push($this->completed_processes,Array(
				null,
				$name,
				false,
				$start_timestamp,
				microtime(true),
				'Could not fok job',
				$id
			));

		} elseif ($pid) {

			//PARENT will take actions on processes later

		} else {
			
			socket_close($reader);

			$thetask = new $task_class($parameters, null, $name, $start_timestamp, true);

			try{

				$result = $thetask->start();

				$result = serialize(array(
					"success"	=>	$result["success"],
					"result"	=>	$result["result"],
					"timestamp"	=>	$result["timestamp"]
				));

			}
			catch (Exception $e) {

				$result = serialize(Array(
					"success"	=>	false,
					"result"	=>	$e->getMessage(),
					"timestamp"	=>	microtime(true)
				));
				
				if ( socket_write($writer, $result, strlen($result)) === false ) {

					$this->logger->error("socket_write() failed ", array(
						"ERROR"	=> socket_strerror(socket_last_error($writer))
					));

				}

				socket_close($writer);
				
				exit(1);

			}

			if ( socket_write($writer, $result, strlen($result)) === false ) {

				$this->logger->error("socket_write() failed ", array(
					"ERROR"	=> socket_strerror(socket_last_error($writer))
				));

			}

			socket_close($writer);

			exit(0);

		}

	}

	/**
	 * Return true if process is still running, false otherwise
	 * 
	 * @return	bool
	 */
	private final function is_running($pid) {

		return (pcntl_waitpid($pid, $this->status, WNOHANG) === 0);

	}

	private final function kill($pid) {

		if (function_exists("pcntl_signal")) return posix_kill($pid, SIGTERM); //JOB can handle the SIGTERM
		
		else return posix_kill($pid, SIGKILL); //JOB cannot handle the SIGTERM, so terminate it w SIGKILL

	}

	static private function getJobUid() {

		return md5(uniqid(rand(), true), 0);

	}

}
