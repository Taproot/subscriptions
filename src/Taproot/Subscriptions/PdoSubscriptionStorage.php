<?php

namespace Taproot\Subscriptions;

use PDO;

/**
 * PDO Subscription Storage
 * 
 * Implements a basic Subscription creation/retrieval interface using PDO for persistance.
 * 
 * @todo prepare each query used as an instance variable rather than redefining inconsistently.
 * @todo document table structures required for this to work, maybe check for them and create/upgrade on creation
 */
class PdoSubscriptionStorage implements SubscriptionStorage {
	protected $db;
	protected $prefix;
	
	public function __construct(PDO $pdo, $tablePrefix = 'shrewdness_') {
		$this->db = $pdo;
		$this->prefix = $tablePrefix;
	}
	
	public function getSubscriptions() {
		return $this->db->query('SELECT * FROM ' . $this->prefix . 'subscriptions;')->fetchAll();
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
