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

namespace OCA\Mail\Db;

use OCA\Mail\Account;
use OCA\Mail\Exception\ConcurrentSyncException;
use OCA\Mail\Exception\ServiceException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;

class MailboxMapper extends QBMapper {

	/** @var ITimeFactory */
	private $timeFactory;

	public function __construct(IDBConnection $db,
								ITimeFactory $timeFactory) {
		parent::__construct($db, 'mail_mailboxes');
		$this->timeFactory = $timeFactory;
	}

	/**
	 * @param Account $account
	 *
	 * @return Mailbox[]
	 */
	public function findAll(Account $account): array {
		$qb = $this->db->getQueryBuilder();

		$select = $qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('account_id', $qb->createNamedParameter($account->getId())));

		return $this->findEntities($select);
	}

	/**
	 * @throws DoesNotExistException
	 * @throws ServiceException
	 */
	public function find(Account $account, string $name): Mailbox {
		$qb = $this->db->getQueryBuilder();

		$select = $qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq('account_id', $qb->createNamedParameter($account->getId())),
				$qb->expr()->eq('name', $qb->createNamedParameter($name))
			);

		try {
			return $this->findEntity($select);
		} catch (MultipleObjectsReturnedException $e) {
			// Not possible due to DB constraints
			throw new ServiceException("The impossible has happened", 42, $e);
		}
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findSpecial(Account $account, string $specialUse): Mailbox {
		$mailboxes = $this->findAll($account);

		// First, let's try to detect by special use attribute
		foreach ($mailboxes as $mailbox) {
			$specialUses = json_decode($mailbox->getSpecialUse(), true) ?? [];
			if (in_array($specialUse, $specialUses, true)) {
				return $mailbox;
			}
		}

		// No luck so far, let's do another round and just guess
		foreach ($mailboxes as $mailbox) {
			// TODO: also check localized name
			if (strtolower($mailbox->getName()) === strtolower($specialUse)) {
				return $mailbox;
			}
		}

		// Give up
		throw new DoesNotExistException("Special mailbox $specialUse does not exist");
	}

	/**
	 * @throws ConcurrentSyncException
	 */
	private function lockForSync(Mailbox $mailbox, string $attr, ?int $lock): int {
		$now = $this->timeFactory->getTime();

		if ($lock !== null
			&& $lock > ($now - 5 * 60)) {
			// Another process is syncing
			throw new ConcurrentSyncException($mailbox->getId() . ' is already being synced');
		}

		$query = $this->db->getQueryBuilder();
		$query->update($this->getTableName())
			->set($attr, $query->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where(
				$query->expr()->eq('id', $query->createNamedParameter($mailbox->getId(), IQueryBuilder::PARAM_INT)),
				$this->eqOrNull($query, $attr, $lock, IQueryBuilder::PARAM_INT)
			);
		if ($query->execute() === 0) {
			// Another process just started syncing

			throw new ConcurrentSyncException();
		}

		return $now;
	}

	/**
	 * @throws ConcurrentSyncException
	 */
	public function lockForNewSync(Mailbox $mailbox): void {
		$mailbox->setSyncNewLock(
			$this->lockForSync($mailbox, 'sync_new_lock', $mailbox->getSyncNewLock())
		);
	}

	/**
	 * @throws ConcurrentSyncException
	 */
	public function lockForChangeSync(Mailbox $mailbox): void {
		$mailbox->setSyncChangedLock(
			$this->lockForSync($mailbox, 'sync_changed_lock', $mailbox->getSyncChangedLock())
		);
	}

	/**
	 * @throws ConcurrentSyncException
	 */
	public function lockForVanishedSync(Mailbox $mailbox): void {
		$mailbox->setSyncVanishedLock(
			$this->lockForSync($mailbox, 'sync_vanished_lock', $mailbox->getSyncVanishedLock())
		);
	}

	/**
	 * @param Mailbox $mailbox
	 * @param IQueryBuilder $query
	 *
	 * @return string
	 */
	private function eqOrNull(IQueryBuilder $query, string $column, $value, int $type): string {
		if ($value === null) {
			return $query->expr()->isNull($column);
		}
		return $query->expr()->eq($column, $query->createNamedParameter($value, $type));
	}

	public function unlockFromNewSync(Mailbox $mailbox): void {
		$mailbox->setSyncNewLock(null);

		$this->update($mailbox);
	}

	public function unlockFromChangedSync(Mailbox $mailbox): void {
		$mailbox->setSyncChangedLock(null);

		$this->update($mailbox);
	}

	public function unlockFromVanishedSync(Mailbox $mailbox): void {
		$mailbox->setSyncVanishedLock(null);

		$this->update($mailbox);
	}

}
