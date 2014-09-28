<?php

namespace Taproot\Subscriptions;

use PDO;
use Exception;
use DateTime;
use DateInterval;
use InvalidArgumentException;


/**
 * PDO Subscription Storage
 * 
 * Implements a basic Subscription creation/retrieval interface using PDO for persistance.
 * 
 * @todo prepare each query used as an instance variable rather than redefining inconsistently.
 * @todo document table structures required for this to work, maybe check for them and create/upgrade on creation
 */
class PdoSubscriptionStorage implements SubscriptionStorage {
	const VERSION = '0.0.1';
	
	protected $db;
	protected $prefix;
	
	public function __construct(PDO $pdo, $tablePrefix = '') {
		$this->db = $pdo;
		$this->prefix = $tablePrefix;
	}
	
	public function migrate() {
		return migratePdoSubscriptionStorageTables($this->db, $this->prefix);
	}
	
	public function getSubscriptions() {
		return $this->db->query("SELECT * FROM {$this->prefix}subscriptions;")->fetchAll();
	}

	public function getSubscriptionsForHub($hub) {
		if (!is_string($hub)) {
			if ($hub instanceof PushHub) {
				$hub = $hub->getUrl();
			} else {
				throw new InvalidArgumentException("The '\$hub' argument must be a string or an instance of Taproot\\Subscriptions\\PushHub.");
			}
		}

		$subscriptions = $this->db->prepare("SELECT * FROM {$this->prefix}subscriptions WHERE hub = :hub");
		$subscriptions->execute(['hub' => $hub]);
		return $subscriptions->fetchAll();
	}

	public function createSubscription($topic, PushHub $hub) {
		$subscription = [
			'topic' => $topic,
			'hub' => $hub->getUrl()
		];
		
		$existingSubscription = $this->db->prepare('SELECT * FROM ' . $this->prefix . 'subscriptions WHERE topic = :topic AND hub = :hub;');
		$existingSubscription->execute($subscription);
		if ($existingSubscription->rowCount() !== 0) {
			$subscription = $existingSubscription->fetch();
			if ($subscription['mode'] !== 'subscribe') {
				if ($this->db->prepare("UPDATE {$this->prefix}subscriptions SET mode='subscribe' WHERE id = :id;")->execute($subscription) === false) {
					return [null, new Exception('Failed updating subscription: ' . print_r($this->db->errorInfo(), true))];
				}
			}
		} else {
			// Create a sufficiently random ID for the subscription.
			// Not relying on database autoincrement as this ID is used in public endpoints. In the case of unsigned ping requests
			// this provides minimal security by obscurity.
			$subscription['id'] = uniqid(time(), true);
			$insertStatement = $this->db->prepare('INSERT INTO ' . $this->prefix . 'subscriptions (id, topic, hub) VALUES (:id, :topic, :hub);');
			if ($insertStatement->execute($subscription) === false) {
				return [null, new Exception('Failed saving subscription: ' . print_r($insertStatement->errorInfo(), true))];
			}
			$existingSubscription->execute([
				'topic' => $subscription['topic'],
				'hub' => $subscription['hub']
			]);
			$subscription = $existingSubscription->fetch();
			if ($subscription === false) {
				return [null, new Exception('Failed retrieving subscription: ' . print_r($existingSubscription->errorInfo(), true))];
			}
		}
		
		return [$subscription, null];
	}
	
	public function getSubscription($id) {
		return $this->db->query("SELECT * FROM {$this->prefix}subscriptions WHERE id = {$this->db->quote($id)};")->fetch();
	}
	
	public function getPingsForSubscription($id, $limit=20, $offset=0) {
		return $this->db->query("SELECT * FROM {$this->prefix}pings WHERE subscription = {$this->db->quote($id)} ORDER BY datetime DESC LIMIT {$limit} OFFSET {$offset};")->fetchAll();
	}
	
	public function subscriptionIntentVerified($id, $leaseSeconds = null) {
		if ($leaseSeconds !== null) {
			$expiryDate = new DateTime();
			$expiryDateISO8601 = $expiryDate->add(new DateInterval("PT{$leaseSeconds}S"))->format(DateTime::W3C);
		} else {
			$expiryDateISO8601 = null;
		}

		$this->db->exec("UPDATE {$this->prefix}subscriptions SET intent_verified = 1, expires = {$this->db->quote($expiryDateISO8601)} WHERE id = {$this->db->quote($id)};");
	}
	
	public function getLatestPingForSubscription($id) {
		return $this->db->query("SELECT * FROM {$this->prefix}pings WHERE subscription = {$this->db->quote($id)} ORDER BY datetime DESC LIMIT 1;")->fetch();
	}
	
	public function createPing(array $ping) {
		// Create unique ID for this ping without depending on DB-specific autoincrementation.
		$ping['id'] = "{$ping['subscription']}." . time();
		$insertPing = $this->db->prepare('INSERT INTO ' . $this->prefix . 'pings (id, subscription, content_type, content) VALUES (:id, :subscription, :content_type, :content);');
		$insertPing->execute($ping);
	}
	
	public function getPing($subscriptionId, $timestamp) {
		$fetchPing = $this->db->prepare("SELECT * FROM {$this->prefix}pings WHERE subscription = :subscription AND datetime = :timestamp;");
		$fetchPing->execute(['subscription' => $subscriptionId, 'timestamp' => $timestamp]);
		return $fetchPing->fetch();
	}

	public function getExpiringSubscriptions() {
		// Renew all subscriptions which expire in a day or less.
		if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
			$fetchExpiringSubscriptions = $this->db->prepare("SELECT * FROM {$this->prefix}subscriptions WHERE ABS((EXTRACT(EPOCH FROM current_timestamp) - EXTRACT(EPOCH FROM expires))) / 3600 <= 86400");
		} else {
			$fetchExpiringSubscriptions = $this->db->prepare("SELECT * FROM {$this->prefix}subscriptions WHERE ABS(TIMESTAMPDIFF(HOUR, current_timestamp, expires)) <= 12");
		}

		return $fetchExpiringSubscriptions->fetchAll();
	}
}


// In the future, if changes are made, detect the version change and migrate accordingly.
function migratePdoSubscriptionStorageTables(PDO $pdo, $prefix='') {
	$boolColumnDefinition = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) == 'pgsql' ? 'int' : 'tinyint(1)';
	$result = $pdo->exec(<<<EOT
CREATE TABLE IF NOT EXISTS {$prefix}config (
	key varchar(200) NOT NULL,
	value varchar(10000) NOT NULL,
	PRIMARY KEY (key)
);

CREATE TABLE IF NOT EXISTS {$prefix}subscriptions (
	id varchar(100) NOT NULL,
	created timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
	last_updated timestamp NULL DEFAULT NULL,
	last_pinged timestamp NULL DEFAULT NULL,
	expires timestamp NULL DEFAULT NULL,
	hub varchar(500) NOT NULL,
	mode varchar(100) NOT NULL DEFAULT 'subscribe',
	intent_verified {$boolColumnDefinition} NOT NULL DEFAULT '0',
	topic varchar(500) NOT NULL,
	PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS {$prefix}pings (
	id varchar(500) NOT NULL,
	subscription varchar(200) NOT NULL,
	datetime timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
	content_type varchar(100) NOT NULL,
	content text NOT NULL,
	PRIMARY KEY (id)
);
EOT
	);
	
	if ($result === false) {
		return false;
	}
	
	$currentVersion = PdoSubscriptionStorage::VERSION;
	try {
		$result = $pdo->exec("INSERT INTO {$prefix}config (key, value) VALUES ('version', {$pdo->quote($currentVersion)});");
	} catch (Exception $e) {}
	
	return true;
}
