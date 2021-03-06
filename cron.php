<?php
/* +*******************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.0
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 * ****************************************************************************** */

/**
 * Start the cron services configured.
 */
chdir(__DIR__);
include_once __DIR__ . '/include/main/WebUI.php';
\App\Config::$requestMode = 'Cron';
\App\Utils\ConfReport::$sapi = 'cron';
$cronLogPath = ROOT_DIRECTORY . '/cache/logs/cron/';
if (!file_exists($cronLogPath)) {
	mkdir($cronLogPath);
}
$cronLogPath .= date('Ymd_Hi') . '.log';
$deleteCronFile = true;
file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - File start' . PHP_EOL);
file_put_contents(ROOT_DIRECTORY . '/user_privileges/cron.php', '<?php return ' . App\Utils::varExport(array_merge(\App\Utils\ConfReport::getAll(), ['last_start' => time()])) . ';');

App\Session::init();
\App\Session::set('last_activity', microtime(true));
$authenticatedUserId = App\Session::get('authenticated_user_id');
$appUniqueKey = App\Session::get('app_unique_key');
$user = (!empty($authenticatedUserId) && !empty($appUniqueKey) && $appUniqueKey === AppConfig::main('application_unique_key'));
$response = '';
file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - SAPI: ' . PHP_SAPI . PHP_EOL, FILE_APPEND);
file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - User: ' . Users::getActiveAdminId() . PHP_EOL, FILE_APPEND);
if (PHP_SAPI === 'cli' || $user || AppConfig::main('application_unique_key') === \App\Request::_get('app_key')) {
	$cronTasks = false;
	file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - Cron start: ' . PHP_EOL, FILE_APPEND);
	vtlib\Cron::setCronAction(true);
	if (\App\Request::_has('service')) {
		// Run specific service
		$cronTasks = [vtlib\Cron::getInstance(\App\Request::_get('service'))];
	} else {
		// Run all service
		$cronTasks = vtlib\Cron::listAllActiveInstances();
	}
	$cronStart = microtime(true);
	//set global current user permissions
	App\User::setCurrentUserId(Users::getActiveAdminId());
	if ($user) {
		$response .= '<pre>';
	}
	$response .= sprintf('---------------  %s | Start CRON  ----------', date('Y-m-d H:i:s')) . PHP_EOL;
	foreach ($cronTasks as $cronTask) {
		try {
			file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - Task start: ' . $cronTask->getName() . PHP_EOL, FILE_APPEND);
			\App\Log::trace($cronTask->getName() . ' - Start', 'Cron');
			// Timeout could happen if intermediate cron-tasks fails
			// and affect the next task. Which need to be handled in this cycle.
			if ($cronTask->hadTimeout()) {
				$response .= sprintf('%s | %s - Cron task had timedout as it was not completed last time it run' . PHP_EOL, date('Y-m-d H:i:s'), $cronTask->getName());
				file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - Cron task had timedout as it was not completed last time it run' . PHP_EOL, FILE_APPEND);
				if (AppConfig::main('unblockedTimeoutCronTasks')) {
					$cronTask->unlockTask();
				}
			}
			// Not ready to run yet?
			if ($cronTask->isRunning()) {
				\App\Log::trace($cronTask->getName() . ' - Task omitted, it has not been finished during the last scanning', 'Cron');
				file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - Task omitted, it has not been finished during the last scanning' . PHP_EOL, FILE_APPEND);
				$response .= sprintf('%s | %s - Task omitted, it has not been finished during the last scanning' . PHP_EOL, date('Y-m-d H:i:s'), $cronTask->getName());
				continue;
			}
			// Not ready to run yet?
			if (!$cronTask->isRunnable()) {
				file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - Not ready to run as the time to run again is not completed' . PHP_EOL, FILE_APPEND);
				\App\Log::trace($cronTask->getName() . ' - Not ready to run as the time to run again is not completed', 'Cron');
				$response .= sprintf('%s | %s - Not ready to run as the time to run again is not completed' . PHP_EOL, date('Y-m-d H:i:s'), $cronTask->getName());
				continue;
			}
			// Mark the status - running
			$cronTask->markRunning();
			$response .= sprintf('%s | %s - Start task' . PHP_EOL, date('Y-m-d H:i:s'), $cronTask->getName());
			$startTaskTime = microtime(true);

			ob_start();
			vtlib\Deprecated::checkFileAccess($cronTask->getHandlerFile());
			require_once $cronTask->getHandlerFile();
			$taskResponse = ob_get_contents();
			ob_end_clean();

			$taskTime = round(microtime(true) - $startTaskTime, 2);
			if ($taskResponse !== '') {
				file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - The task returned a message: ' . PHP_EOL . $taskResponse . PHP_EOL, FILE_APPEND);
				\App\Log::warning($cronTask->getName() . ' - The task returned a message:' . PHP_EOL . $taskResponse, 'Cron');
				$response .= 'Task response:' . PHP_EOL . $taskResponse . PHP_EOL;
				$deleteCronFile = false;
			}
			// Mark the status - finished
			$cronTask->markFinished();
			$response .= sprintf('%s | %s - End task (%s s)', date('Y-m-d H:i:s'), $cronTask->getName(), $taskTime) . PHP_EOL;
			\App\Log::trace($cronTask->getName() . ' - End', 'Cron');
			file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - End task, time: ' . PHP_EOL . $taskTime . PHP_EOL, FILE_APPEND);
		} catch (Throwable $e) {
			$deleteCronFile = false;
			file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - Cron task execution throwed exception: ' . PHP_EOL . $response . PHP_EOL . $e->__toString() . PHP_EOL, FILE_APPEND);
			echo $response;
			echo sprintf('%s | ERROR: %s - Cron task execution throwed exception.', date('Y-m-d H:i:s'), $cronTask->getName()) . PHP_EOL;
			echo $e->getMessage() . PHP_EOL;
			echo $e->getTraceAsString() . PHP_EOL;
			if (AppConfig::main('systemMode') === 'test') {
				throw $e;
			}
		}
	}
	file_put_contents($cronLogPath, date('Y-m-d H:i:s') . ' - End CRON' . PHP_EOL, FILE_APPEND);
	$response .= sprintf('===============  %s (' . round(microtime(true) - $cronStart, 2) . ') | End CRON  ==========', date('Y-m-d H:i:s')) . PHP_EOL;
	echo $response;
}
if ($deleteCronFile) {
	unlink($cronLogPath);
} else {
	file_put_contents($cronLogPath, PHP_EOL . '------------------------------------' . PHP_EOL . \App\Log::getlastLogs() . PHP_EOL, FILE_APPEND);
}
