<?php

namespace Taproot\Subscriptions;

use Symfony\Component\HttpFoundation as Http;
use Symfony\Component\EventDispatcher;
use Guzzle;
use Mf2;
use Exception;
use DateTime;

/**
 * Subscribe
 *
 * Given a loaded app and a URL, subscribe to that URL at its hub, or
 * $defaultHub/$app['subscriptions.defaulthub'] if it doesn’t support PuSH.
 *
 * Unlike crawl(), this function requires a loaded app due to the need for storage and
 * dynamic callback URL generation.
 *
 * @return [array $subscription|null, Exception $error|null]
 */
function subscribe($app, $url, $client = null, $defaultHub = null) {
	if ($client === null) {
		$client = new Guzzle\Http\Client();
	}

	if ($defaultHub === null) {
		$defaultHub = $app['subscriptions.defaulthub'];
	}

	$storage = $app['subscriptions.storage'];

	// Discover Hub.
	try {
		$resp = $client->get($url)->send();
	} catch (Guzzle\Common\Exception\GuzzleException $e) {
		// Resource does not exist, return exception
		return $app->abort(400, "The given topic, {$url}, could not be fetched.");
	}

	$links = pushLinksForResponse($resp);
	$topic = $links['self'] ?: $url;
	$hubs = $links['hub'];

	$hub = empty($hubs) ? $defaultHub : new PushHub($hubs[0]);

	$subscription = $storage->createSubscription($topic, $hub);
	// Regardless of the state of the database beforehand, $subscription now exists, has an ID and a mode of “subscribe”.

	$result = $hub->subscribe($topic, $app['url_generator']->generate('subscriptions.id.ping', ['id' => $subscription['id']], true));
	if ($result instanceof Exception) {
		return [null, $result];
	}

	// Allow clients to access the content of the fetched page after subscribing.
	$subscription['resource'] = contextFromResponse($resp->getBody(1), $resp->getEffectiveUrl(), $resp->getHeaders(), $subscription['topic']);

	return [$subscription, null];
}


/**
 * @param $html
 * @param $url
 * @param $headers
 * @param $topic
 * @return array
 *
 * Currently $headers is just returned as-is, so is usually an array. This should probably have some abstraction for
 * case insensitivity but that might be an issue if we can’t guarantee that $headers is an array, or that each item is a
 * scalar or array — e.g. how are multiple values for a header handled?
 */
function contextFromResponse($html, $url, $headers, $topic) {
	$content = $html;

	// TODO: per-host handling of microformats shim parsing should be handled by Mf2\Shim automatically.
	$host = parse_url($url, PHP_URL_HOST);
	if (in_array($host, ['www.twitter.com', 'twitter.com'])) {
		$parser = new Mf2\Shim\Twitter($html, $url);
	} elseif (in_array($host, ['www.facebook.com', 'facebook.com'])) {
		$parser = new Mf2\Shim\Facebook($html, $url);
	} else {
		$parser = new Mf2\Parser($html, $url);
	}
	$mf2 = $parser->parse();

	return [
		'topic' => $topic,
		'url' => $url,
		'mf2' => $mf2,
		'content' => $content,
		'domdocument' => $parser->doc,
		'parser' => $parser,
		'headers' => $headers
	];
}


/**
 * Crawl
 *
 * Given a URL, recursively fetches rel=prev[ious], calling $callback at each stage.
 * returns Exception $error | null on success
 * $callback is passed one argument, an array with: url, mf2, content, domdocument, parser
 * If $callback returns false, crawl is halted.
 *
 * @TODO: make this function check for duplicate content (pass previousResponse on recurse) and halt if duplicated.
 */
function crawl($url, $callback, $timeout=null, $client=null, $extraContext=[]) {
	if ($timeout !== null) {
		$timeStarted = microtime(true);
	} elseif ($timeout !== null and $timeout <= 0) {
		// Crawl timed out but was successful.
		return null;
	}

	if ($client == null) {
		$client = new Guzzle\Http\Client();
	}

	$fetch = function ($url) use ($client) {
		try {
			return [$client->get($url)->send(), null];
		} catch (Guzzle\Common\Exception\GuzzleException $e) {
			return [null, $e];
		}
	};

	list($resp, $err) = $fetch($url);
	if ($err !== null) {
		return $err;
	}

	// In this case, topic is empty unless set by $extraContext
	$context = array_merge($extraContext, contextFromResponse($resp->getBody(1), $resp->getEffectiveUrl(), $resp->getHeaders(), null));
	$mf2 = $context['mf2'];

	$result = $callback($context);

	$prevUrl = !empty($mf2['rels']['prev']) ? $mf2['rels']['prev'][0] : !empty($mf2['rels']['previous']) ? $mf2['rels']['previous'][0] : null;

	if ($prevUrl === null or $result === false) {
		return null;
	}

	if ($timeout !== null) {
		$timeout = $timeout - (microtime(true) - $timeStarted);
	}

	return crawl($prevUrl, $callback, $timeout, $client);
}


/**
 * Subscribe And Crawl
 *
 * Given a loaded app, a URL and an optional callback to run over each page (in addition to subscriptions.ping event listeners)
 * subscribe to that URL and crawl rel=prev[ious] until the end, $timeout (in seconds) or an error is reached.
 *
 * $app needs subscriptions.defaulthub and url_generator.
 *
 * Example usage:
 *
 *     list($subscription, $error) = subscribeAndCrawl($app, 'http://waterpigs.co.uk', function ($response) {
 *       echo "Crawled {$response['url']}\n";
 *     }, 60);
 *     if ($error !== null) {
 *       // There was a problem, either with subscribing or crawling.
 *       // $error is an Exception subclass, do whatever logging/reporting you want to with it.
 *     }
 *
 * @param \Silex\Application $app
 * @param string $url The URL to subscribe to, and the base from which to crawl
 * @param callable $crawlCallback (optional) a function to be run over each page whilst crawling. Passed standard $response.
 * @param int $timeout (optional) the maximum number of seconds to crawl for. Default null, meaning infinity
 * @param Guzzle\Http\Client $client (optional) a HTTP client to use. One will be created if none passed.
 * @return array [$subscription|null, $error|null]
 */
function subscribeAndCrawl($app, $url, $crawlCallback = null, $timeout = null, $client = null) {
	if ($client === null) {
		$client = new Guzzle\Http\Client();
	}

	if ($crawlCallback === null) {
		$crawlCallback = function () {};
	}

	// Subscribe to URL.
	list($subscription, $error) = subscribe($app, $url, $client);

	if ($error !== null) {
		return [null, $error];
	}

	$error = crawl($url, function ($resource) use ($app, $crawlCallback) {
		$app['dispatcher']->dispatch('subscriptions.ping', new EventDispatcher\GenericEvent(null, $resource));
		$crawlCallback($resource);
	}, $timeout, $client, ['topic' => $subscription['topic']]);

	if ($error !== null) {
		return [null, $error];
	}

	return [$subscription, null];
}


/**
 * Subscription Controllers
 *
 * Given a loaded application and optionally authentication and new-content callback functions,
 * create a bunch of routes and return them.
 *
 * Required dependencies in $app:
 *
 * * subscriptions.storage: An instance implementing SubscriptionStorage
 * * subscriptions.defaulthub: An instance of PushHub (probably actually SuperfeedrHub) to use for subscribing if content doesn’t support PuSH
 * * url_generator: A UrlGenerator service
 * * http.client: A Guzzle HTTP client.
 *
 * Example usage:
 *
 *     $app->mount('/subscriptions', controllers($app, function (Symfony\Component\HttpFoundation\Request $request) {
 *       // If the request is not authenticated, $app->abort(401, 'Reason');
 *     }, function ($response) {
 *       // $response is an array-accessible object or array with a bunch of keys as documented elsewhere.
 *       // In here, do whatever you want to (e.g. indexing, storage, processing) with the new/crawled content.
 *     });
 *
 * @param \Silex\Application $app
 * @param callable $authFunction (optional) a function (passed $request) run before any non-public routes.
 * @param callable $contentCallbackFunction (optional) shortcut for adding a function which is called for each bit of new content
 * @return RouteCollection
 */
function controllers($app, $authFunction = null, $contentCallbackFunction = null) {
	$subscriptions = $app['controllers_factory'];
	$storage = $app['subscriptions.storage'];

	if ($authFunction === null) {
		$authFunction = function ($request) { return; };
	}

	if ($contentCallbackFunction !== null) {
		$app['dispatcher']->addListener('subscriptions.ping', $contentCallbackFunction);
	}

	$render = function ($template, $__templateData=array()) {
		$__basedir = __DIR__;
		$render = function ($__path, $__templateData) use ($__basedir) {
			ob_start();
			extract($__templateData);
			unset($__templateData);
			include $__basedir . '/../templates/' . $__path . '.php';
			return ob_get_clean();
		};
		return $render($template, $__templateData);
	};

	// Subscription list.
	$subscriptions->get('/', function (Http\Request $request) use ($app, $storage, $render) {
		$subscriptions = $storage->getSubscriptions();

		foreach ($subscriptions as &$subscription) {
			$subscription['url'] = $app['url_generator']->generate('subscriptions.id.get', ['id' => $subscription['id']]);
		}

		return $render('subscriptions.html', [
			'subscriptions' => $subscriptions,
			'newSubscriptionUrl' => $app['url_generator']->generate('subscriptions.post'),
			'crawlUrl' => $app['url_generator']->generate('subscriptions.crawl')
		]);
	})->bind('subscriptions')
		->before($authFunction);


	// Crawl(+ensure subscription).
	$subscriptions->post('/crawl/', function (Http\Request $request) use ($app, $storage) {
		$url = $request->request->get('url');
		$client = $app['http.client'];

		return new Http\StreamedResponse(function () use ($url, $app, $client) {
			list($subscription, $error) = subscribeAndCrawl($app, $url, function ($resource) {
				echo "{$resource['url']}\n";
				ob_flush();
				flush();
			}, null, $client);
			if ($error !== null) {
				$app['logger']->warn('Crawl: Subscribing to a URL failed:', [
					'url' => $url,
					'exceptionClass' => get_class($error),
					'message' => $error->getMessage()
				]);
				echo "Subscribing and/or crawling {$url} failed.\n";
				echo get_class($error) . "\n";
				echo $error->getMessage() . "\n";
				ob_flush();
				flush();
			}
		}, 200, ['Content-type' => 'text/plain']);
	})->bind('subscriptions.crawl')
		->before($authFunction);


	// Subscription creation.
	$subscriptions->post('/', function (Http\Request $request) use ($app, $storage) {
		if (!$request->request->has('url')) {
			return $app->abort(400, 'Subscription requests must have a url parameter.');
		}

		$url = $request->request->get('url');
		$client = $app['http.client'];

		list($subscription, $error) = subscribe($app, $url, $client);
		if ($error !== null) {
			$app['logger']->warn('Ran into an error whilst creating a subscription', [
				'exception' => get_class($error),
				'message' => $error->getMessage()
			]);
			$app->abort(400, 'Ran into an error whilst trying to subscribe (see logs)');
		}

		return $app->redirect($app['url_generator']->generate('subscriptions.id.get', ['id' => $subscription['id']]));
	})->bind('subscriptions.post')
		->before($authFunction);


	// Subscription summary, list of recent pings.
	$subscriptions->get('/{id}/', function (Http\Request $request, $id) use ($app, $storage, $render) {
		$subscription = $storage->getSubscription($id);
		if (empty($subscription)) {
			return $app->abort(404, 'No such subscription found!');
		}

		$pings = $storage->getPingsForSubscription($id);
		foreach ($pings as &$ping) {
			$ping['url'] = $app['url_generator']->generate('subscriptions.id.ping.datetime', ['id' => $id, 'timestamp' => (new Datetime($ping['datetime']))->format('Y-m-d\TH:i:s')]);
		}

		return $render('subscription.html', [
			'subscription' => $subscription,
			'pings' => $pings
		]);
	})->bind('subscriptions.id.get')
		->before($authFunction);


	// Verification of intent (public).
	$subscriptions->get('/{id}/ping/', function (Http\Request $request, $id) use ($app, $storage) {
		$subscription = $storage->getSubscription($id);
		if (empty($subscription)) {
			return $app->abort(404, 'No such subscription found!');
		}

		// For some reason the periods are converted into underscores by PHP.
		if ($request->query->has('hub_mode')) {
			$p = $request->query->all();
			if ($p['hub_mode'] === $subscription['mode'] and $p['hub_topic'] === $subscription['topic']) {
				$storage->subscriptionIntentVerified($id);
				return $p['hub_challenge'];
			} else {
				return $app->abort(404, 'No such intent!');
			}
		}

		return $app->abort(400, 'GET requests to subscription callbacks must be verifications of intent, i.e. have hub.mode parameters');
	});


	// New content update (public).
	$subscriptions->post('/{id}/ping/', function (Http\Request $request, $id) use ($app, $storage) {
		$subscription = $storage->getSubscription($id);
		if (empty($subscription)) {
			return $app->abort(404, 'No such subscription found!');
		}

		// Compare content with most recent ping, if it exists.
		$latestPing = $storage->getLatestPingForSubscription($id);

		if (!empty($latestPing) and $latestPing['content'] == $request->getContent()) {
			$app['logger']->info("Not adding new ping for subscription {$id} as content is the same as previous ping.");
			return '';
		}

		$ping = [
				'subscription' => $subscription['id'],
				'content_type' => $request->headers->get('Content-type'),
				'content' => $request->getContent()
		];

		$storage->createPing($ping);

		$response = new Guzzle\Http\Message\Response(200, $request->headers->all(), $request->getContent());
		// In this case we do not know the exact URL, but can safely assume that it is the topic of the subscription.
		$event = new EventDispatcher\GenericEvent($response, contextFromResponse(
				$response->getBody(1),
				$subscription['topic'],
				$request->headers->all(),
				$subscription['topic']));
		$app['dispatcher']->dispatch('subscriptions.ping', $event);

		return '';
	})->bind('subscriptions.id.ping');


	// Individual ping content view.
	$subscriptions->get('/{id}/{timestamp}/', function (Http\Request $request, $id, $timestamp) use ($app, $storage) {
		$ping = $storage->getPing($id, $timestamp);
		if (empty($ping)) {
			$app->abort(404, 'No such ping found!');
		}

		if (strstr($ping['content_type'], 'html') !== false) {
			return new Http\Response($ping['content'], 200, ['Content-type' => 'text/plain']);
		} else {
			// Probably a bunch of potential attacks here, but for the moment it’s adequate.
			return new Http\Response($ping['content'], 200, ['Content-type' => $ping['content_type']]);
		}
	})->bind('subscriptions.id.ping.datetime')
		->before($authFunction);

	return $subscriptions;
}

function pushLinksForResponse(Guzzle\Http\Message\Response $resp) {
	$self = null;
	$hubs = [];

	$linkHeader = $resp->getHeader('link');
	if ($linkHeader instanceof Guzzle\Http\Message\Header\Link) {
		$links = $linkHeader->getLinks();
		foreach ($links as $link) {
			if (strpos(" {$link['rel']} ", ' self ') !== false) {
				$self = $link['url'];
			}
			if (strpos(" {$link['rel']} ", ' hub ') !== false) {
				$hubs[] = $link['url'];
			}
		}
	}

	if (strpos($resp->getContentType(), 'html') !== false) {
		$mf = Mf2\parse($resp->getBody(true), $resp->getEffectiveUrl());
		if (!empty($mf['rels']['hub'])) {
			$hubs = array_merge($hubs, $mf['rels']['hub']);
		}

		if (!empty($mf['rels']['self']) and $self === null) {
			$self = $mf['rels']['self'][0];
		}
	}

	return [
		'self' => $self,
		'hub' => $hubs
	];
}
