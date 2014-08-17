<?php

namespace Taproot\Subscriptions;

/**
 * Subscription Storage Interface
 * 
 * An interface for creating and retriving subscriptions and pings. Instances implementing this
 * can be used to store subscriptions in different persistance backends.
 * 
 * @todo document all these methods.
 */
interface SubscriptionStorage {
	public function getSubscriptions();
	public function createSubscription($topic, PushHub $hub);
	public function getSubscription($id);
	public function getPingsForSubscription($id, $limit=20, $offset=0);
	public function subscriptionIntentVerified($id, $leaseSeconds=null);
	public function getLatestPingForSubscription($id);
	public function createPing(array $ping);
	public function getPing($subscriptionId, $timestamp);
	public function getExpiringSubscriptions();
}
