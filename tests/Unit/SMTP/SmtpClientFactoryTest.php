<?php

/**
 * @copyright 2017 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2017 Christoph Wurst <christoph@winzerhof-wurst.at>
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

namespace OCA\Mail\Tests\Unit\Smtp;

use Horde_Mail_Transport_Mail;
use Horde_Mail_Transport_Smtphorde;
use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\SMTP\SmtpClientFactory;
use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Support\HostNameFactory;
use OCP\IConfig;
use OCP\Security\ICrypto;
use PHPUnit_Framework_MockObject_MockObject;

class SmtpClientFactoryTest extends TestCase {

	/** @var IConfig|PHPUnit_Framework_MockObject_MockObject */
	private $config;

	/** @var ICrypto|PHPUnit_Framework_MockObject_MockObject */
	private $crypto;

	/** @var HostNameFactory|PHPUnit_Framework_MockObject_MockObject */
	private $hostNameFactory;

	/** @var SmtpClientFactory */
	private $factory;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->hostNameFactory = $this->createMock(HostNameFactory::class);

		$this->factory = new SmtpClientFactory($this->config, $this->crypto, $this->hostNameFactory);
	}

	public function testPhpMailTransport() {
		$account = $this->createMock(Account::class);
		$this->config->expects($this->once())
			->method('getSystemValue')
			->with('app.mail.transport', 'smtp')
			->willReturn('php-mail');

		$transport = $this->factory->create($account);

		$this->assertNotNull($transport);
		$this->assertInstanceOf(Horde_Mail_Transport_Mail::class, $transport);
	}

	public function testSmtpTransport() {
		$mailAccount = new MailAccount([
			'smtpHost' => 'smtp.domain.tld',
			'smtpPort' => 25,
			'smtpSslMode' => 'none',
			'smtpUser' => 'user@domain.tld',
			'smtpPassword' => 'obenc',
		]);
		$account = new Account($mailAccount);
		$this->config->expects($this->at(0))
			->method('getSystemValue')
			->with('app.mail.transport', 'smtp')
			->willReturn('smtp');
		$this->config->expects($this->at(2))
			->method('getSystemValue')
			->with('debug', false)
			->willReturn(false);
		$this->crypto->expects($this->once())
			->method('decrypt')
			->with('obenc')
			->willReturn('pass123');
		$this->config->expects($this->at(1))
			->method('getSystemValue')
			->with('app.mail.smtp.timeout', 5)
			->willReturn(2);
		$this->hostNameFactory->expects($this->once())
			->method('getHostName')
			->willReturn('cloud.example.com');
		$expected = new Horde_Mail_Transport_Smtphorde([
			'host' => 'smtp.domain.tld',
			'password' => 'pass123',
			'port' => 25,
			'username' => 'user@domain.tld',
			'secure' => false,
			'timeout' => 2,
			'localhost' => 'cloud.example.com',
		]);

		$transport = $this->factory->create($account);

		$this->assertNotNull($transport);
		$this->assertInstanceOf(Horde_Mail_Transport_Smtphorde::class, $transport);
		$this->assertEquals($expected, $transport);
	}

}
