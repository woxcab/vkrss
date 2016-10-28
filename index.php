<?php
require_once('Vk2rss.php');
header("Content-type: text/xml; charset=utf-8");


$id = isset($_GET['id']) ? $_GET['id'] :
        (isset($_GET['domain']) ? $_GET['domain'] :
            (isset($_GET['owner_id']) ? $_GET['owner_id'] : null));

try {
    $vk2rss = new Vk2rss(
        $id,
        !empty($_GET['count']) ? $_GET['count'] : 20,
        isset($_GET['include']) ? $_GET['include'] : null,
        isset($_GET['exclude']) ? $_GET['exclude'] : null,
        isset($_GET['disable_html']),
        isset($_GET['owner_only']),
        isset($_GET['access_token']) ? $_GET['access_token'] : null,
        isset($_GET['proxy']) ? $_GET['proxy'] : null,
        isset($_GET['proxy_type']) ?  mb_strtolower($_GET['proxy_type']) : null,
        isset($_GET['proxy_login']) ? $_GET['proxy_login'] : null,
        isset($_GET['proxy_password']) ? $_GET['proxy_password'] : null);
    $vk2rss->generateRSS();
} catch (APIError $exc) {
    http_response_code($exc->getCode());
    die("API Error {$exc->getApiErrorCode()}: {$exc->getMessage()}. Request URL: {$exc->getRequestUrl()}" . PHP_EOL);
} catch (Exception $exc) {
    if (function_exists('http_response_code')) {
        http_response_code($exc->getCode());
    } else {
        $statuses = array(400 => '400 Bad Request',
                          401 => '401 Unauthorized',
                          407 => '407 Proxy Authentication Required',
                          500 => '500 Internal Server Error',
                          503 => '503 Service Unavailable',
                          504 => '503 Gateway Time-out');
        header('HTTP/1.1 ' . $statuses[$exc->getCode()]);
    }
    die($exc->getMessage() . PHP_EOL);
}
