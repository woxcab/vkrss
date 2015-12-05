<?php require('Vk2rss.php');
header("Content-type: text/xml; charset=utf-8");
$vk2rss = new Vk2rss;
$vk2rss->domain = isset($_GET['domain']) ? $_GET['domain'] : NULL;
$vk2rss->owner_id = isset($_GET['owner_id']) ? $_GET['owner_id'] : NULL;
$vk2rss->count = !empty($_GET['count']) ? $_GET['count'] : 20;
$vk2rss->include = !empty($_GET['include']) ? $_GET['include'] : NULL;
$vk2rss->exclude = !empty($_GET['exclude']) ? $_GET['exclude'] : NULL;
$vk2rss->generateRSS();