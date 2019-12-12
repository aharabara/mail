<?php declare(strict_types=1);

/**
 * @copyright 2019 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2019 Christoph Wurst <christoph@winzerhof-wurst.at>
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
 */

namespace OCA\Mail\Service;

use Horde_Imap_Client;
use Horde_Imap_Client_Exception;
use Horde_Imap_Client_Exception_Sync;
use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\MailboxMapper;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\Db\MessageMapper as DatabaseMessageMapper;
use OCA\Mail\Exception\ConcurrentSyncException;
use OCA\Mail\Exception\MailboxNotCachedException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\IMAP\MessageMapper as ImapMessageMapper;
use OCA\Mail\IMAP\Sync\Request;
use OCA\Mail\IMAP\Sync\Response;
use OCA\Mail\IMAP\Sync\Synchronizer;
use OCA\mail\lib\Exception\UidValidityChangedException;
use OCA\Mail\Model\IMAPMessage;
use OCA\Mail\Support\PerformanceLogger;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ILogger;
use Throwable;
use function array_chunk;
use function array_map;

class SyncService {

	/** @var DatabaseMessageMapper */
	private $dbMapper;

	/** @var IMAPClientFactory */
	private $clientFactory;

	/** @var ImapMessageMapper */
	private $imapMapper;

	/** @var MailboxMapper */
	private $mailboxMapper;

	/** @var DatabaseMessageMapper */
	private $messageMapper;

	/** @var Synchronizer */
	private $synchronizer;

	/** @var PerformanceLogger */
	private $performanceLogger;

	/** @var ILogger */
	private $logger;

	public function __construct(DatabaseMessageMapper $dbMapper,
								IMAPClientFactory $clientFactory,
								ImapMessageMapper $imapMapper,
								MailboxMapper $mailboxMapper,
								MessageMapper $messageMapper,
								Synchronizer $synchronizer,
								PerformanceLogger $performanceLogger,
								ILogger $logger) {
		$this->dbMapper = $dbMapper;
		$this->clientFactory = $clientFactory;
		$this->imapMapper = $imapMapper;
		$this->mailboxMapper = $mailboxMapper;
		$this->messageMapper = $messageMapper;
		$this->synchronizer = $synchronizer;
		$this->performanceLogger = $performanceLogger;
		$this->logger = $logger;
	}

	/**
	 * @throws ServiceException
	 */
	public function syncAccount(Account $account,
								bool $force = false,
								int $criteria = Horde_Imap_Client::SYNC_NEWMSGSUIDS | Horde_Imap_Client::SYNC_FLAGSUIDS | Horde_Imap_Client::SYNC_VANISHEDUIDS): void {
		foreach ($this->mailboxMapper->findAll($account) as $mailbox) {
			$this->sync(
				$account,
				$mailbox,
				$criteria,
				null,
				$force
			);
		}
	}

	/**
	 * @param int[] $knownUids
	 * @throws ServiceException
	 */
	public function syncMailbox(Account $account,
								string $mailbox,
								int $criteria = Horde_Imap_Client::SYNC_NEWMSGSUIDS | Horde_Imap_Client::SYNC_FLAGSUIDS | Horde_Imap_Client::SYNC_VANISHEDUIDS,
								array $knownUids = null,
								bool $partialOnly = true): Response {
		try {
			$mb = $this->mailboxMapper->find($account, $mailbox);

			if ($partialOnly && $mb->getSyncNewToken() === null) {
				throw new MailboxNotCachedException();
			}

			return $this->sync(
				$account,
				$mb,
				$criteria,
				$knownUids
			);
		} catch (DoesNotExistException $e) {
			throw new ServiceException('Mailbox to sync does not exist in the database', 0, $e);
		}
	}

	/**
	 * @throws ServiceException
	 */
	public function ensurePopulated(Account $account, string $mailbox): void {
		try {
			$mb = $this->mailboxMapper->find($account, $mailbox);
		} catch (DoesNotExistException $e) {
			throw new ServiceException('Mailbox does not exist', 0 , $e);
		}

		if ($mb->getSyncNewToken() !== null) {
			return;
		}

		try {
			$this->mailboxMapper->lockForNewSync($mb);
			$this->mailboxMapper->lockForChangeSync($mb);
			$this->mailboxMapper->lockForVanishedSync($mb);

			$this->runInitialSync($account, $mb);
		} catch (ConcurrentSyncException $e) {
			// Fine, then we don't have to do it
		} finally {
			$this->mailboxMapper->unlockFromNewSync($mb);
			$this->mailboxMapper->unlockFromChangedSync($mb);
			$this->mailboxMapper->unlockFromVanishedSync($mb);
		}
	}

	/**
	 * @param int[] $knownUids
	 * @throws ServiceException
	 */
	private function sync(Account $account,
						  Mailbox $mailbox,
						  int $criteria,
						  array $knownUids = null,
						  bool $force = false): Response {
		if ($mailbox->getSelectable() === false) {
			return new Response();
		}

		try {
			if ($criteria & Horde_Imap_Client::SYNC_NEWMSGSUIDS) {
				$this->mailboxMapper->lockForNewSync($mailbox);
			}
			if ($criteria & Horde_Imap_Client::SYNC_FLAGSUIDS) {
				$this->mailboxMapper->lockForChangeSync($mailbox);
			}
			if ($criteria & Horde_Imap_Client::SYNC_VANISHEDUIDS) {
				$this->mailboxMapper->lockForVanishedSync($mailbox);
			}
		} catch (ConcurrentSyncException $e) {
			throw new ServiceException('Another sync is in progress for ' . $mailbox->getId(), 0, $e);
		}

		try {
			if ($force || $mailbox->getSyncNewToken() === null) {
				$response = $this->runInitialSync($account, $mailbox);
			} else {
				$response = $this->runPartialSync($account, $mailbox, $criteria, $knownUids);
			}
		} catch (Throwable $e) {
			throw new ServiceException('Sync failed', 0, $e);
		} finally {
			if ($criteria & Horde_Imap_Client::SYNC_VANISHEDUIDS) {
				$this->mailboxMapper->unlockFromVanishedSync($mailbox);
			}
			if ($criteria & Horde_Imap_Client::SYNC_FLAGSUIDS) {
				$this->mailboxMapper->unlockFromChangedSync($mailbox);
			}
			if ($criteria & Horde_Imap_Client::SYNC_NEWMSGSUIDS) {
				$this->mailboxMapper->unlockFromNewSync($mailbox);
			}
		}

		return $response;
	}

	/**
	 * @throws ServiceException
	 */
	private function runInitialSync(Account $account, Mailbox $mailbox): Response {
		$perf = $this->performanceLogger->start('Initial sync ' . $account->getId() . ':' . $mailbox->getName());

		$client = $this->clientFactory->getClient($account);
		try {
			$imapMessages = $this->imapMapper->findAll($client, $mailbox);
			$perf->step('fetch all messages from IMAP');
		} catch (Horde_Imap_Client_Exception $e) {
			throw new ServiceException('Can not get messages from mailbox ' . $mailbox->getName() . ': ' . $e->getMessage(), 0, $e);
		}

		// The sync token could be reset by a migration, hence there could be existing data
		$this->dbMapper->deleteAll($mailbox);
		$perf->step('delete existing messages');

		foreach (array_chunk($imapMessages, 500) as $chunk) {
			$this->dbMapper->insertBulk(...array_map(function (IMAPMessage $imapMessage) use ($mailbox) {
				return $imapMessage->toDbMessage($mailbox->getId());
			}, $chunk));
		}
		$perf->step('persist messages in database');

		$mailbox->setSyncNewToken($client->getSyncToken($mailbox->getMailbox()));
		$mailbox->setSyncChangedToken($client->getSyncToken($mailbox->getMailbox()));
		$mailbox->setSyncVanishedToken($client->getSyncToken($mailbox->getMailbox()));
		$this->mailboxMapper->update($mailbox);

		$perf->end();

		// Not returning *all* new messages here as this could exhaust the memory
		return new Response();
	}

	/**
	 * @param int[] $knownUids
	 *
	 * @throws ServiceException
	 */
	private function runPartialSync(Account $account,
									Mailbox $mailbox,
									int $criteria,
									array $knownUids = null): Response {
		$perf = $this->performanceLogger->start('partial sync ' . $account->getId() . ':' . $mailbox->getName());

		$client = $this->clientFactory->getClient($account);
		$uids = $knownUids ?? $this->dbMapper->findAllUids($mailbox);
		$perf->step('get all known UIDs');

		$response = new Response();
		if ($criteria & Horde_Imap_Client::SYNC_NEWMSGSUIDS) {
			try {
				$response = $response->merge($this->synchronizer->sync(
					$client,
					new Request(
						$mailbox->getMailbox(),
						$mailbox->getSyncNewToken(),
						$uids
					),
					Horde_Imap_Client::SYNC_NEWMSGSUIDS
				));
			} catch (UidValidityChangedException $e) {
				$this->logger->warning('Mailbox UID validity changed. Performing full sync.');

				return $this->runInitialSync($account, $mailbox);
			}
			$perf->step('get new messages via Horde');

			foreach (array_chunk($response->getNewMessages(), 500) as $chunk) {
				$this->dbMapper->insertBulk(...array_map(function (IMAPMessage $imapMessage) use ($mailbox) {
					return $imapMessage->toDbMessage($mailbox->getId());
				}, $chunk));
			}
			$perf->step('persist new messages');

			$mailbox->setSyncNewToken($client->getSyncToken($mailbox->getMailbox()));
		}
		if ($criteria & Horde_Imap_Client::SYNC_FLAGSUIDS) {
			$response = $response->merge($this->synchronizer->sync(
				$client,
				new Request(
					$mailbox->getMailbox(),
					$mailbox->getSyncChangedToken(),
					$uids
				),
				Horde_Imap_Client::SYNC_FLAGSUIDS
			));
			$perf->step('get changed messages via Horde');

			foreach (array_chunk($response->getChangedMessages(), 500) as $chunk) {
				$this->dbMapper->updateBulk(...array_map(function (IMAPMessage $imapMessage) use ($mailbox) {
					return $imapMessage->toDbMessage($mailbox->getId());
				}, $chunk));
			}
			$perf->step('persist changed messages');

			// If a list of UIDs was *provided* (as opposed to loaded from the DB,
			// we can not assume that all changes were detected, hence this is kinda
			// a silent sync and we don't update the change token until the next full
			// mailbox sync
			if ($knownUids === null) {
				$mailbox->setSyncChangedToken($client->getSyncToken($mailbox->getMailbox()));
			}
		}
		if ($criteria & Horde_Imap_Client::SYNC_VANISHEDUIDS) {
			$response = $response->merge($this->synchronizer->sync(
				$client,
				new Request(
					$mailbox->getMailbox(),
					$mailbox->getSyncVanishedToken(),
					$uids
				),
				Horde_Imap_Client::SYNC_VANISHEDUIDS
			));
			$perf->step('get vanished messages via Horde');

			foreach (array_chunk($response->getVanishedMessageUids(), 500) as $chunk) {
				$this->dbMapper->deleteByUid($mailbox, ...$chunk);
			}
			$perf->step('persist new messages');

			$mailbox->setSyncVanishedToken($client->getSyncToken($mailbox->getMailbox()));
		}
		$this->mailboxMapper->update($mailbox);

		$response = $response->merge(
			$this->getDatabaseSyncChanges($mailbox, $uids)
		);

		$perf->end();

		return $response;
	}

	private function getDatabaseSyncChanges(Mailbox $mailbox, array $uids): Response {
		if (empty($uids)) {
			return new Response();
		}

		sort($uids, SORT_NUMERIC);
		$last = end($uids);

		$new = $this->messageMapper->findNew($mailbox, $last);
		// TODO: $changed = $this->messageMapper->findChanged($mailbox, $uids);
		$changed = $this->messageMapper->findByUids($mailbox, $uids);
		$old = array_map(function (Message $msg) {
			return $msg->getUid();
		}, $this->messageMapper->findByUids($mailbox, $uids));
		$vanished = array_filter($uids, function (int $uid) use ($old) {
			return !in_array($uid, $old, true);
		});

		return new Response($new, $changed, $vanished);
	}

}
