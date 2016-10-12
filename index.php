<?php require('Vk2rss.php');
header("Content-type: text/xml; charset=utf-8");

$id = isset($_GET['id']) ? $_GET['id'] :
        (isset($_GET['domain']) ? $_GET['domain'] :
            (isset($_GET['owner_id']) ? $_GET['owner_id'] : NULL));

try {
    $vk2rss = new Vk2rss(
        $id,
        !empty($_GET['count']) ? $_GET['count'] : 20,
        isset($_GET['include']) ? $_GET['include'] : NULL,
        isset($_GET['exclude']) ? $_GET['exclude'] : NULL);
    $vk2rss->generateRSS();
} catch (APIError $exc) {
    http_response_code($exc->getCode());
    die("API Error {$exc->getApiErrorCode()}: {$exc->getMessage()}. Request URL: {$exc->getRequestUrl()}");
} catch (Exception $exc) {
    http_response_code($exc->getCode());
    die($exc->getMessage());
}
