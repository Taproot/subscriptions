<?php

namespace Taproot\Subscriptions;

class SuperfeedrHub extends PushHub {
	protected $username;
	protected $token;
	
	public function __construct($username, $token, $client = null) {
		parent::__construct('https://push.superfeedr.com');
		$this->username = $username;
		$this->token = $token;
		
		if ($client === null) {
			$client = new Guzzle\Http\Client();
		}
		$client->getConfig()->setPath('request.options/auth', [$username, $token]);
		$this->client = $client;
	}
}
