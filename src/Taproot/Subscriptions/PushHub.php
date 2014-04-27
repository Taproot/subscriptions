<?php

namespace Taproot\Subscriptions;

use Guzzle;

// TODO: add secret handling.
class PushHub {
	protected $url;
	protected $client;
	
	public function __construct($url, $client = null) {
		$this->url = $url;
		if ($client === null) {
			$client = new Guzzle\Http\Client();
		}
		$this->client = $client;
	}
	
	public function getUrl() {
		return $this->url;
	}
	
	public function subscribe($url, $callback) {
		try {
			$response = $this->client->post($this->url)->addPostFields([
				'hub.mode' => 'subscribe',
				'hub.topic' => $url,
				'hub.callback' => $callback
			])->send();
			return true;
		} catch (Guzzle\Common\Exception\GuzzleException $e) {
			return $e;
		}
	}
	
	public function unsubscribe($url, $callback) {
		try {
			$response = $this->client->post($this->url)->addPostFields([
				'hub.mode' => 'unsubscribe',
				'hub.topic' => $url,
				'hub.callback' => $callback
			])->send();
			return true;
		} catch (Guzzle\Common\Exception\GuzzleException $e) {
			return $e;
		}
	}
	
	public function __toString() {
		return "Hub @ {$this->url}";
	}
}
