# taproot/subscriptions

taproot/subscriptions is a small framework for easily subscribing to web content from Silex/Symfony+Pimple projects.

It provides an API (as well as some minimal web UIs) for subscribing to future updates to resources as well as crawling historical content. Currently only HTML resources are supported fully.

## Installation

Install using [Composer](https://getcomposer.org):

    ./composer.phar require taproot/subscriptions:~0.1

## Setup

taproot/subscriptions requires a few services in order to work properly. They should be set up approximately like this:

```php
<?php

// subscriptions.storage needs to be an instance implementing Taproot\Subscriptions\SubscriptionStorage
// Currently the only provided implementation is PdoSubscriptionStorage, which takes a PDO instance and an
// optional prefix
$app['subscriptions.storage'] = $app->share(function () use ($app) {
	return new Taproot\Subscriptions\PdoSubscriptionStorage($db, 'subscriptions_');
});

// subscriptions.defaulthub should be an subclass of Taproot\Subscriptions\PushHub, which will be used to 
// subscribe to content which doesn’t natively support PuSH. Most of the time this should be a Superfeedr hub,
// for which Taproot\Subscriptions\SuperfeedrHub is provided.
$app['subscriptions.defaulthub'] = function () use ($app) {
	return new Taproot\Subscriptions\SuperfeedrHub('username', 'password or token');
};
```

Then, set up taproot/subscriptions and mount its routes somewhere on your app by calling `Taproot\Subscriptions\controllers()`:

The first argument is a reference to $app if you’re using Silex, or your Pimple-compatible dependency container otherwise. All other arguments are optional callbacks.
The second is an authorization callback and will be called with the current $request before every non-public request.
It should check if the current user is authorized and abort otherwise.
The third is a shortcut for adding a listener for the `subscriptions.ping` event — it’s a callable which 

```php
<?php

$app->mount('/subscriptions', Taproot\Subscriptions\controller($app, function ($request) {
	// For the subscription admin routes, check if the current user is allowed to view them.
	if (!userIsAdmin($request)) {
		$app->abort(401, 'Only admins may view this page');
	}
}, function ($resource) {
	// A new resource has been fetched, either via historical crawling or new content from a subscription.
	// Do something with the resource which has been fetched, e.g. store, index, processing
	$url = $resource['url'];
}));
```

## Web UI

Navigate to wherever you mounted the subscriptions routes on your site to see the web UI. It lists your current subscriptions as well as allowing you to subscribe or subscribe+crawl, view individual subscription information and individual ping content.

## API

TODO: write this