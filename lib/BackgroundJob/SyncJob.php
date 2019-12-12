<?php declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Mail\BackgroundJob;

use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\SyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\ILogger;

class SyncJob extends TimedJob {

	/** @var AccountService */
	private $accountService;
	/** @var SyncService */
	private $syncService;
	/** @var ILogger */
	private $logger;
	/** @var IJobList */
	private $jobList;

	public function __construct(ITimeFactory $time,
								AccountService $accountService,
								SyncService $syncService,
								ILogger $logger,
								IJobList $jobList) {
		parent::__construct($time);

		$this->accountService = $accountService;
		$this->syncService = $syncService;
		$this->logger = $logger;

		$this->setInterval(3600);
		$this->jobList = $jobList;
	}

	protected function run($argument) {
		$accountId = (int)$argument['accountId'];

		try {
			$account = $this->accountService->findById($accountId);
		} catch (DoesNotExistException $e) {
			$this->logger->debug('Could not find account <' . $accountId . '> removing from jobs');
			$this->jobList->remove(self::class, $argument);
			return;
		}

		try {
			$this->syncService->syncAccount($account);
		} catch (\Exception $e) {
			$this->logger->logException($e);
		}
	}

}
