<?php

namespace Taproot\Subscriptions;

use PHPUnit_Framework_TestCase;
use PDO;

class PdoSubscriptionStorageTest extends PHPUnit_Framework_TestCase {
	public function testMigrateCreatesTables() {
		$pdo = new PDO('sqlite::memory:');
		$subscriptionStorage = new PdoSubscriptionStorage($pdo);
		$success = $subscriptionStorage->migrate();
		
		$this->assertTrue($success);
		
		$result = $pdo->query('SELECT * FROM config WHERE key=' . $pdo->quote('version') . ';');
		$this->assertTrue($result !== false);
		
		$row = $result->fetch();
		$this->assertTrue($row !== false);
		
		$this->assertEquals(PdoSubscriptionStorage::VERSION, $row['value']);
	}
}
