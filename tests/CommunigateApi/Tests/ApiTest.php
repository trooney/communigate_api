<?php
namespace CommunigateApi\Tests;

use CommunigateApi\API;

class ApiTest extends \PHPUnit_Framework_TestCase {

	/**
	 * @var array ComminigateApi Object Configuration
	 */
	private $config;
	/**
	 * @var \CommunigateApi\API
	 */
	private $api;

	/**
	 * @var string Domain to test against
	 */
	private $domain = 'testdomain.bm';

	/**
	 * @var string Place-holder for generated account name
	 */
	private $account;

	public function setUp() {
		$this->api = new Api($this->getConfig());
		$this->account = 'unit_test_account_' . uniqid();
		$this->password = 'password_' . uniqid();

		$this->api->create_account($this->domain, $this->account, $this->password);
	}

	public function tearDown() {
		$this->api->delete_account($this->domain, $this->account);
	}

	private function getConfig() {
		return array(
			'host' => $GLOBALS['cgcadmin_host'],
			'login' => $GLOBALS['cgcadmin_login'],
			'password' => $GLOBALS['cgcadmin_password'],
			'port' => $GLOBALS['cgcadmin_port'],
		);
	}

	/**
	 * @expectedException CommunigateApi\ApiException
	 */
	public function test_connect_failed() {
		$api = new Api(array(
			'host' => '127.0.0.1',
			'login' => 'dev',
			'password' => 'null'
		));
		$api->connect();
	}

	public function test_connect() {
		$api = new Api($this->getConfig());
		$this->assertTrue($api->connect());
	}

	public function test_get_domains() {
		$this->assertNotEmpty($this->api->get_domains());
	}

	public function test_get_accounts() {
		$this->assertNotEmpty($this->api->get_accounts('testdomain.bm'));
	}

	public function test_create_and_delete_account() {

		$domain = 'testdomain.bm';
		$account = 'unit_test_account_' . uniqid();
		$password = 'password_' . uniqid();

		$result = $this->api->create_account(
			$this->domain,
			$account,
			$password
		);
		$this->assertTrue($result);

		$result = $this->api->delete_account(
			$domain,
			$account
		);
		$this->assertTrue($result);
	}

	public function test_reset_password() {
		$result = $this->api->reset_password(
			$this->domain,
			$this->account,
			$this->password
		);
		$this->assertTrue($result);
	}

	public function test_rename_account() {
		$domain = 'testdomain.bm';
		$account = 'unit_test_account_' . uniqid();
		$new_account = 'unit_test_account_' . uniqid();
		$password = 'password_' . uniqid();

		$result = $this->api->create_account(
			$this->domain,
			$account,
			$password
		);
		$this->assertTrue($result);

		$this->api->rename_account(
			$domain,
			$account,
			$new_account
		);

		$this->api->delete_account(
			$domain,
			$new_account
		);
	}

	public function test_vacation_message() {
		$result = $this->api->get_account_vacation_message(
			$this->domain,
			$this->account
		);
		$this->assertNull($result);

		$result = $this->api->set_account_vacation_message(
			$this->domain,
			$this->account,
			'Away message'
		);
		$this->assertTrue($result);

		$expected = 'Away message';
		$result = $this->api->get_account_vacation_message(
			$this->domain,
			$this->account
		);
		$this->assertEquals($expected, $result);

		$result = $this->api->clear_account_vacation_message(
			$this->domain,
			$this->account
		);
		$this->assertTrue($result);

		$expected = null;
		$result = $this->api->get_account_vacation_message(
			$this->domain,
			$this->account
		);
		$this->assertEquals($expected, $result);

	}

	public function test_email_redirect() {
		$result = $this->api->get_account_email_redirect(
			$this->domain,
			$this->account
		);
		$this->assertNull($result);

		$result = $this->api->set_account_email_redirect(
			$this->domain,
			$this->account,
			'dev@null.com'
		);
		$this->assertTrue($result);

		$expected = 'dev@null.com';
		$result = $this->api->get_account_email_redirect(
			$this->domain,
			$this->account
		);
		$this->assertEquals($expected, $result);

		$result = $this->api->clear_account_email_redirect(
			$this->domain,
			$this->account
		);
		$this->assertTrue($result);

		$expected = null;
		$result = $this->api->get_account_email_redirect(
			$this->domain,
			$this->account
		);
		$this->assertEquals($expected, $result);

	}


	public function test_storage() {
		$expected = array('max' => 50, 'used' => 0);
		$result = $this->api->get_account_storage(
			$this->domain,
			$this->account
		);
		$this->assertEquals($expected, $result);

		$result = $this->api->set_account_storage(
			$this->domain,
			$this->account,
			'40'
		);
		$this->assertTrue($result);

		$expected = array('max' => 40, 'used' => 0);
		$result = $this->api->get_account_storage(
			$this->domain,
			$this->account
		);
		$this->assertEquals($expected, $result);

	}


}

?>