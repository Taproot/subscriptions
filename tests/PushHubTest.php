<?php

namespace Taproot\Subscriptions;

use PHPUnit_Framework_TestCase;

class PushHubTest extends PHPUnit_Framework_TestCase {
	public function testCanCreateHub() {
		$url = 'http://pubsubhubbub.example.com';
		$hub = new PushHub($url);
		$this->assertEquals($url, $hub->getUrl());
	}
}
