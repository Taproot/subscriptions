<?php

namespace Taproot\Subscriptions;

use PDO;
use Exception;

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
	
	public function __construct(PDO $pdo, $tablePrefix = 'shrewdness_') {
		$this->db = $pdo;
		$this->prefix = $tablePrefix;
	}
	
	public function migrate() {
		migratePdoSubscriptionStorageTables($this->db, $this->prefix);
	}
	
	public function getSubscriptions() {
		return $this->db->query("SELECT * FROM {$this->prefix}subscriptions;")->fetchAll();
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
				$this->db->prepare("UPDATE {$this->prefix}subscriptions SET mode='subscribe' WHERE id = :id;")->execute($subscription);
			}
		} else {
			$this->db->prepare('INSERT INTO ' . $this->prefix . 'subscriptions (topic, hub) VALUES (:topic, :hub);')->execute($subscription);
			$existingSubscription->execute($subscription);
			$subscription = $existingSubscription->fetch();
		}
		
		return $subscription;
	}
	
	public function getSubscription($id) {
		return $this->db->query("SELECT * FROM {$this->prefix}subscriptions WHERE id = {$this->db->quote($id)};")->fetch();
	}
	
	public function getPingsForSubscription($id, $limit=20, $offset=0) {
		return $this->db->query("SELECT * FROM {$this->prefix}pings WHERE subscription = {$this->db->quote($id)} ORDER BY datetime DESC LIMIT {$limit} OFFSET {$offset};")->fetchAll();
	}
	
	public function subscriptionIntentVerified($id) {
		$this->db->exec("UPDATE {$this->prefix}subscriptions SET intent_verified = 1 WHERE id = {$this->db->quote($id)};");
	}
	
	public function getLatestPingForSubscription($id) {
		return $this->db->query("SELECT * FROM {$this->prefix}pings WHERE subscription = {$this->db->quote($id)} ORDER BY datetime DESC LIMIT 1;")->fetch();
	}
	
	public function createPing(array $ping) {
		$insertPing = $this->db->prepare('INSERT INTO ' . $this->prefix . 'pings (subscription, content_type, content) VALUES (:subscription, :content_type, :content);');
		$insertPing->execute($ping);
	}
	
	public function getPing($subscriptionId, $timestamp) {
		$fetchPing = $this->db->prepare("SELECT * FROM {$this->prefix}pings WHERE subscription = :subscription AND datetime = :timestamp;");
		$fetchPing->execute(['subscription' => $id, 'timestamp' => $timestamp]);
		return $fetchPing->fetch();
	}
}


// TODO: in the future, if changes are made, detect the version change and migrate accordingly.
function migratePdoSubscriptionStorageTables(PDO $pdo, $prefix) {
	// Check if tables exist
	$pdo->exec(<<<EOT
CREATE TABLE IF NOT EXISTS `{$prefix}config` (
`key` varchar(100) NOT NULL,
`value` varchar(10000) NOT NULL,
PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `{$prefix}subscriptions` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
`last_updated` timestamp NULL DEFAULT NULL,
`last_pinged` timestamp NULL DEFAULT NULL,
`hub` varchar(500) NOT NULL,
`mode` varchar(100) NOT NULL DEFAULT 'subscribe',
`intent_verified` tinyint(1) NOT NULL DEFAULT '0',
`topic` varchar(500) NOT NULL,
PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `{$prefix}pings` (
`id` int(11) NOT NULL AUTO_INCREMENT,
`subscription` int(11) NOT NULL,
`datetime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
`content_type` varchar(100) NOT NULL,
`content` text NOT NULL,
PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8;
EOT
	);
	
	$currentVersion = PdoSubscriptionStorage::VERSION;
	try {
		$pdo->exec("INSERT INTO {$prefix}config (key, value) VALUES ('version', {$version});");
	} catch (Exception $e) {}
}

function tableExists(PDO $pdo, $tableName) {
	try {
		$result = $pdo->query("SELECT 1 FROM {$tableName} LIMIT 1;");
	} catch (Exception $e) {
		return false;
	}
	return $result !== false;
}
