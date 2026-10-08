<?php

use Predis\Client;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$redisUrl = $_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? '';
$database = (int) ltrim((string) parse_url($redisUrl, PHP_URL_PATH), '/');
if (0 === $database) {
    throw new RuntimeException('Tests need a dedicated non-zero Redis database in REDIS_URL.');
}
$_ENV['REDIS_URL'] = $_SERVER['REDIS_URL'] = $redisUrl;
putenv('REDIS_URL='.$redisUrl);
(new Client($redisUrl))->flushdb();

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
