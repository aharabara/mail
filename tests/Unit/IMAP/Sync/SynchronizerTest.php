<?php declare(strict_types=1);

/**
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * Mail
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\Mail\Tests\Unit\IMAP\Sync;

use ChristophWurst\Nextcloud\Testing\TestCase;
use Horde_Imap_Client_Base;
use Horde_Imap_Client_Data_Sync;
use Horde_Imap_Client_Mailbox;
use OCA\Mail\IMAP\Sync\FavouritesMailboxSync;
use OCA\Mail\IMAP\Sync\Request;
use OCA\Mail\IMAP\Sync\Response;
use OCA\Mail\IMAP\Sync\SimpleMailboxSync;
use OCA\Mail\IMAP\Sync\Synchronizer;
use PHPUnit\Framework\MockObject\MockObject;

class SynchronizerTest extends TestCase {

	/** @var SimpleMailboxSync|MockObject */
	private $simpleSync;

	/** @var FavouritesMailboxSync|MockObject */
	private $favSync;

	/** @var Synchronizer */
	private $synchronizer;

	protected function setUp(): void {
		parent::setUp();

		$this->simpleSync = $this->createMock(SimpleMailboxSync::class);
		$this->favSync = $this->createMock(FavouritesMailboxSync::class);

		$this->synchronizer = new Synchronizer($this->simpleSync, $this->favSync);
	}

	public function syncData() {
		return [
			[false],
			[true],
		];
	}

	/**
	 * @dataProvider syncData
	 */
	public function testSync($flagged) {
		$sync = $flagged ? $this->favSync : $this->simpleSync;

		$imapClient = $this->createMock(Horde_Imap_Client_Base::class);
		$request = $this->createMock(Request::class);
		$request->expects($this->any())
			->method('getMailbox')
			->willReturn('inbox');
		$request->expects($this->once())
			->method('getToken')
			->willReturn('123456');
		$hordeSync = $this->createMock(Horde_Imap_Client_Data_Sync::class);
		$imapClient->expects($this->once())
			->method('sync')
			->with($this->equalTo(new Horde_Imap_Client_Mailbox('inbox')), $this->equalTo('123456'))
			->willReturn($hordeSync);
		$request->expects($this->once())
			->method('isFlaggedMailbox')
			->willReturn($flagged);
		$newMessages = [];
		$changedMessages = [];
		$vanishedMessageUids = [4, 5];
		$sync->expects($this->once())
			->method('getNewMessages')
			->with($imapClient, $request, $hordeSync)
			->willReturn($newMessages);
		$sync->expects($this->once())
			->method('getChangedMessages')
			->with($imapClient, $request, $hordeSync)
			->willReturn($changedMessages);
		$sync->expects($this->once())
			->method('getVanishedMessageUids')
			->with($imapClient, $request, $hordeSync)
			->willReturn($vanishedMessageUids);
		$expected = new Response($newMessages, $changedMessages, $vanishedMessageUids);

		$response = $this->synchronizer->sync($imapClient, $request);

		$this->assertEquals($expected, $response);
	}

}
