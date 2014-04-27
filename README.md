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

### Subscribe to a feed

```php
<?php

use Taproot\Subscriptions;

list($subscription, $error) = Subscriptions\subscribe($app, 'http://waterpigs.co.uk');
if ($error !== null) {
	// There was a problem, and $error is a Guzzle Exception with more information about exactly what.
} else {
	// Otherwise, $subscription is an array representing the subscription which was just created
}
```

### Subscribe to a feed and crawl historical content

```php
<?php

use Taproot\Subscriptions;

// The extra (optional) argument is a callback to be run for each page which gets crawled, in addition to any listeners
// on subscriptions.ping — handy e.g. for logging purposes
list($subscription, $error) = Subscriptions\subscribeAndCrawl($app, 'http://waterpigs.co.uk', function ($resource) {
	echo "Crawled {$resource['url']}\n";
});

```

### Perform tasks on new/old content

Whenever a feed resource, whether current from a subscription ping or historical from crawling rel=prev[ious] links, the `subscriptions.ping` event is dispatched on `$app['dispatcher']` with a GenericEvent containing information about the resource.

You can either attach a listener to that event, or pass one as the third argument to Subscriptions\controllers() — they accomplish the exact same thing, one is just a shortcut.

TODO: document exactly what properties the event has.

Your handlers should be written in such a way that running them over the same content multiple times doesn’t produce duplicate results, as it’s entirely possible that they will be run multiple times.

E.G. a typical h-feed subscription should looks something like this (pseudocode):

```php
<?php

function ($resource) {
	$posts = postsFromMicroformats($resource['mf']);
	foreach ($posts as $post) {
		createOrUpdatePost($post);
	}
}
```


# Changelog 

## v0.1.0

* First version
