<?php

namespace Taproot\Subscriptions;

/**
 * Inert Hub
 *
 * No-op hub implementation to use
 */
class InertHub extends PushHub {
	public function __construct() {

	}

	public function subscribe($url, $callback) {
		return true;
	}

	public function unsubscribe($url, $callback) {
		return true;
	}

	public function getUrl() {
		return 'inert-hub';
	}

	public function __toString() {
		return 'Inert Hub';
	}
}
