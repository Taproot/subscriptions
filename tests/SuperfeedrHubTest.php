<?php

namespace Taproot\Subscriptions;

use PHPUnit_Framework_TestCase;

class SuperfeedrHubTest extends PHPUnit_Framework_TestCase {
	public function testCanCreateHub() {
		$hub = new SuperfeedrHub('username', 'token');
	}
}
