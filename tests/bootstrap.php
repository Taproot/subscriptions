<?php

namespace Taproot\Subscriptions;

ob_start();
require __DIR__ . '/../vendor/autoload.php';
ob_end_clean();

function fixturePath($path) {
	return __DIR__ . '/fixtures/' . ltrim($path, '/');
}

function fixture($path) {
	return file_get_contents(fixturePath($path));
}
