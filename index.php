<?php
require_once('Vk2rss.php');
header("Content-type: text/xml; charset=utf-8");

$supported_parameters = array('id', 'domain', 'owner_id', 'global_search', 'count', 'include', 'exclude',
                              'disable_html', 'owner_only', 'non_owner_only', 'not_owner_only', 'access_token',
                              'disable_comments_amount', 'proxy', 'proxy_type', 'proxy_login', 'proxy_password',
                              'allow_signed', 'skip_ads', 'allow_embedded_video', 'repost_delimiter',
                              'donut');

try {
    $params = array_keys($_GET);
    $matched_params = preg_grep('/index[._]php$/u', $params);
    if (!empty($matched_params)) {
        unset($_GET[$matched_params[0]]);
    }
    $diff = array_diff(array_keys($_GET), $supported_parameters);
    if (!empty($diff)) {
        throw new Exception("Unknown parameters: " . implode(', ', $diff)
                            . ". Supported parameters: " . implode(', ', $supported_parameters), 400);
    }
    $id = isset($_GET['id']) ? $_GET['id'] :
        (isset($_GET['domain']) ? $_GET['domain'] :
            (isset($_GET['owner_id']) ? $_GET['owner_id'] : null));
    $config = array('id' => $id);
    $config = array_merge($config, $_GET);
    $vk2rss = new Vk2rss($config);
    $vk2rss->generateRSS();
} catch (APIError $exc) {
    header("Content-type: text/plain; charset=utf-8");
    $msg = "API Error {$exc->getApiErrorCode()}: {$exc->getMessage()}. Request URL: {$exc->getRequestUrl()}" . PHP_EOL;
    header("HTTP/1.1 {$exc->getCode()} {$msg}");
    error_log($msg);
    die($msg);
} catch (Exception $exc) {
    header("Content-type: text/plain; charset=utf-8");
    header("HTTP/1.1 {$exc->getCode()} {$exc->getMessage()}");
    error_log($exc);
    die($exc->getMessage());
}
