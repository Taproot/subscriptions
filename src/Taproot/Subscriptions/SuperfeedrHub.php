<?php

namespace Taproot\Subscriptions;

use Guzzle;

class SuperfeedrHub extends PushHub {
	protected $username;
	protected $token;

	/** @var null|string The fragment which will be applied to URLs, or null */
	public $fragment = '.h-entry';
	
	public function __construct($username, $token, $client = null, $fragment = null) {
		parent::__construct('https://push.superfeedr.com');
		$this->username = $username;
		$this->token = $token;
		
		if ($client === null) {
			$client = new Guzzle\Http\Client();
		}
		$client->getConfig()->setPath('request.options/auth', [$username, $token]);
		$this->client = $client;

		if ($fragment !== null) {
			$this->fragment = $fragment;
		}
	}

	public function subscribe($url, $callback) {
		if (parse_url($url, PHP_URL_FRAGMENT) === null and $this->fragment !== null) {
			$url = rtrim($url, '#');
			$url = "{$url}#{$this->fragment}";
		}

		return parent::subscribe($url, $callback);
	}
}
