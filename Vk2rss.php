<?php

/**
 * @Description: vk.com Wall to RSS Class
 *
 * Initial creators
 * @author tsarbas
 * @author: kadukmm <nikolay.kaduk@gmail.com>
 **/

/**
 * Refactoring and featuring:
 *   including & excluding conditions,
 *   title generation,
 *   description extraction from attachment
 *   hashtags extractor to 'category' tags
 * @author woxcab
 **/

require_once('utils.php');

class Vk2rss
{
    const HASH_TAG_REGEX = '#([а-яёА-ЯЁa-zA-Z0-9_]+)(?:@[a-zA-Z0-9_]+)?';
    /**
     * Delimiter of text from different parts of post
     */
    const VERTICAL_DELIMITER = '________________';
    /**
     * Default title value when no text in the post
     */
    const EMPTY_POST_TITLE = '[Без текста]';
    /**
     * Prefix of feed description for user wall
     */
    const USER_FEED_DESCRIPTION_PREFIX = "Стена пользователя ";
    /**
     * Prefix of feed description for group wall
     */
    const GROUP_FEED_DESCRIPTION_PREFIX = "Стена группы ";
    /**
     * Maximum title length in symbols
     */
    const MAX_TITLE_LENGTH = 80;
    /**
     * Required minimum number of symbols in second or following paragraphs in order to use its for title
     */
    const MIN_PARAGRAPH_LENGTH_FOR_TITLE = 30;
    /**
     * URL of API method that returns wall posts
     */
    const API_BASE_URL = 'http://api.vk.com/method/'; # do not change on HTTPS, connection wrapper do it itself if HTTPS is supported

    /**
     * @var int   identifier of user or group which wall going to be extracted
     */
    protected $owner_id;
    /**
     * @var string   short address of user or group which wall going to be extracted
     */
    protected $domain;
    /**
     * @var int   quantity of last posts from the wall (at most 100)
     */
    protected $count;
    /**
     * @var string   case insensitive regular expression that have to match text of post
     */
    protected $include;
    /**
     * @var string   case insensitive regular expression that have not to match text of post
     */
    protected $exclude;
    /**
     * @var bool   whether the HTML tags to be used in the feed item description
     */
    protected $disable_html;

    /**
     * @var ProxyDescriptor|null   Proxy descriptor
     */
    protected $proxy = null;

    public function __construct($id, $count = 20, $include = null, $exclude = null, $disable_html=false,
                                $proxy = null, $proxy_type = null, $proxy_login = null, $proxy_password = null)
    {
        if (empty($id)) {
            throw new Exception("Empty identifier of user or group is passed", 400);
        }

        if (strcmp(substr($id, 0, 2), 'id') === 0 && ctype_digit(substr($id, 2))) {
            $this->owner_id = (int)substr($id, 2);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 4), 'club') === 0 && ctype_digit(substr($id, 4))) {
            $this->owner_id = -(int)substr($id, 4);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 5), 'event') === 0 && ctype_digit(substr($id, 5))) {
            $this->owner_id = -(int)substr($id, 5);
            $this->domain = null;
        } elseif (strcmp(substr($id, 0, 6), 'public') === 0 && ctype_digit(substr($id, 6))) {
            $this->owner_id = -(int)substr($id, 6);
            $this->domain = null;
        } elseif (is_numeric($id) && is_int(abs($id))) {
            $this->owner_id = (int)$id;
            $this->domain = null;
        } else {
            $this->owner_id = null;
            $this->domain = $id;
        }
        $this->count = $count;
        $this->include = isset($include) ? preg_replace("/(?<!\\\)\//u", "\\/", $include) : null;
        $this->exclude = isset($exclude) ? preg_replace("/(?<!\\\)\//u", "\\/", $exclude) : null;
        $this->disable_html = $disable_html;
        if (isset($proxy)) {
            try {
                $this->proxy = new ProxyDescriptor($proxy, $proxy_type, $proxy_login, $proxy_password);
            } catch (Exception $exc) {
                throw new Exception("Invalid proxy parameters: " . $exc->getMessage(), 400);
            }
        }
    }

    /**
     * Generate RSS feed as output
     */
    public function generateRSS()
    {
        include('FeedWriter.php');

        $outer_encoding = mb_internal_encoding();
        if (!mb_internal_encoding("UTF-8")) {
            throw new Exception("Cannot set encoding UTF-8 for multibyte strings", 500);
        }

        $connector = new ConnectionWrapper($this->proxy);

        if (!empty($this->domain) || (!empty($this->owner_id) && $this->owner_id < 0)) {
            $group_response = $this->getContent($connector, "groups.getById");
            if (property_exists($group_response, 'error') && $group_response->error->error_code != 100) {
                throw new APIError($group_response->error->error_msg,
                    $group_response->error->error_code,
                    $connector->getLastUrl());
            }
        }
        if (isset($group_response) && !property_exists($group_response, 'error') && !empty($group_response->response)) {
            $group = $group_response->response[0];
            $title = $group->name;
            $feed_description = self::GROUP_FEED_DESCRIPTION_PREFIX . $group->name;
        } else {
            $user_response = $this->getContent($connector, "users.get");
            if (property_exists($user_response, 'error')) {
                throw new APIError($user_response->error->error_msg,
                    $user_response->error->error_code, $connector->getLastUrl());
            }
            if (!empty($user_response->response)) {
                $profile = $user_response->response[0];
                $title = $profile->first_name . ' ' . $profile->last_name;
                $feed_description = self::USER_FEED_DESCRIPTION_PREFIX . $profile->first_name . ' ' . $profile->last_name;
            } else {
                throw new Exception("Invalid user or group identifier", 400);
            }
        }

        $wall_response = $this->getContent($connector, "wall.get");
        if (property_exists($wall_response, 'error')) {
            throw new APIError($wall_response->error->error_msg,
                $wall_response->error->error_code,
                $connector->getLastUrl());
        }

        $feed = new FeedWriter(RSS2);
        $id = $this->domain ? $this->domain :
            ($this->owner_id > 0 ? 'id' . $this->owner_id : 'club' . abs($this->owner_id));

        $feed->setTitle($title);
        $feed->setDescription($feed_description);
        $feed->setLink('https://vk.com/' . $id);

        $feed->setChannelElement('language', 'ru-ru');
        $feed->setChannelElement('pubDate', date(DATE_RSS, time()));

        foreach ($wall_response->response->items as $post) {
            $new_item = $feed->createNewItem();
            $new_item->setLink("https://vk.com/wall{$post->owner_id}_{$post->id}");
            $new_item->addElement('guid', "https://vk.com/wall{$post->owner_id}_{$post->id}");
            $new_item->setDate($post->date);

            $description = array();
            $this->extractDescription($description, $post);
            if ($description[0] === self::VERTICAL_DELIMITER) {
                array_shift($description);
            }

            foreach ($description as &$paragraph) {
                $paragraph = preg_replace('/\[[^|]+\|([^\]]+)\]/u', '$1', $paragraph); // remove internal vk links like [id123|Name]
            }

            $imploded_description = implode($this->disable_html ? PHP_EOL : '<br/>', $description);
            $new_item->setDescription($imploded_description);

            if (!is_null($this->include) && preg_match('/' . $this->include . '/iu', $imploded_description) !== 1) {
                continue;
            }
            if (!is_null($this->exclude) && preg_match('/' . $this->exclude . '/iu', $imploded_description) !== 0) {
                continue;
            }

            $new_item->addElement('title', $this->getTitle($description));

            $hash_tags = array();
            preg_match_all('/' . self::HASH_TAG_REGEX . '/u', implode(' ', $description), $hash_tags);

            foreach ($hash_tags[1] as $hash_tag) {
                $new_item->addElement('category', $hash_tag);
            }

            $feed->addItem($new_item);
        }

        $feed->generateFeed();
        mb_internal_encoding($outer_encoding);
    }

    protected function extractDescription(&$description, $post)
    {
        $par_split_regex = '@[\s ]*?(?:<br/?>|\n)+[\s ]*?@u'; # PHP 5.2.X: \s does not contain non-break space
        $empty_string_regex = '@^(?:[\s ]*(?:<br/?>|\n)+[\s ]*)*$@u';

        if (preg_match($empty_string_regex, $post->text) === 0) {
            $description = array_merge($description, preg_split($par_split_regex, $post->text));
        }

        if (isset($post->attachments)) {
            foreach ($post->attachments as $attachment) {
                switch ($attachment->type) {
                    case 'photo': {
                        $photo_sizes = array_values(preg_grep('/^photo_/u', array_keys(get_object_vars($attachment->photo))));
                        $photo_text = preg_replace('|^Original: https?://\S+\s*|u',
                                                   '',
                                                   $attachment->photo->text);
                        $huge_photo_src = $attachment->photo->{end($photo_sizes)};
                        $photo = $this->disable_html ?
                            $huge_photo_src : "<a href='{$huge_photo_src}'><img src='{$attachment->photo->{$photo_sizes[2]}}'/></a>";
                        if (preg_match($empty_string_regex, $photo_text) === 0) {
                            $description = array_merge($description,
                                                       array(self::VERTICAL_DELIMITER),
                                                       preg_split($par_split_regex, $photo_text),
                                                       array($photo));
                        } else {
                            array_push($description, $photo);
                        }
                        break;
                    }
                    case 'audio': {
                        $title = "Аудиозапись {$attachment->audio->artist} — «{$attachment->audio->title}»";
                        if ($this->disable_html) {
                            array_push($description, $title, $attachment->audio->url);
                        } else {
                            array_push($description,
                                       $title,
                                       "<audio controls><source src='{$attachment->audio->url}' type='audio/mpeg'/><a href='{$attachment->audio->url}'>Загрузить</a></audio>");
                        }
                        break;
                    }
                    case 'doc': {
                        if ($this->disable_html) {
                            array_push($description, "Файл «{$attachment->doc->title}»: {$attachment->doc->url}");
                        } else {
                            array_push($description, "<a href='{$attachment->doc->url}'>Файл «{$attachment->doc->title}»</a>");
                        }
                        break;
                    }
                    case 'link': {
                        if ($this->disable_html) {
                            array_push($description,
                                       self::VERTICAL_DELIMITER,
                                       "{$attachment->link->title}: {$attachment->link->url}");
                        } else {
                            array_push($description,
                                       self::VERTICAL_DELIMITER,
                                       "<a href='{$attachment->link->url}'>{$attachment->link->title}</a>");
                        }
                        if (preg_match($empty_string_regex, $attachment->link->description) === 0) {
                            array_push($description, $attachment->link->description);
                        }
                        break;
                    }
                    case 'video': {
                        $video_description = preg_match($empty_string_regex, $attachment->video->description) === 1 ?
                            array() : preg_split($par_split_regex, $attachment->video->description);
                        $content = array("Видеозапись «{$attachment->video->title}»:");
                        if ($video_description) {
                            array_unshift($content, self::VERTICAL_DELIMITER);
                        }
                        if ($this->disable_html) {
                            array_push($content, "https://vk.com/video{$attachment->video->owner_id}_{$attachment->video->id}");
                        } else {
                            $preview_sizes = array_values(preg_grep('/^photo_/u', array_keys(get_object_vars($attachment->video))));
                            array_push($content, "<a href='https://vk.com/video{$attachment->video->owner_id}_{$attachment->video->id}'><img src='{$attachment->video->{$preview_sizes[2]}}'/></a>");
                        }
                        $description = array_merge($description, $content, $video_description);
                        break;
                    }
                }
            }
        }

        if (isset($post->copy_history)) {
            foreach ($post->copy_history as $repost) {
                array_push($description, self::VERTICAL_DELIMITER);
                $this->extractDescription($description, $repost);
                if (end($description) === self::VERTICAL_DELIMITER) {
                    array_pop($description);
                }
            }
        }
    }

    /**
     * Get posts of wall
     *
     * @param ConnectionWrapper $connector
     * @param string $api_method   API method name
     * @return mixed   Json VK response in appropriate PHP type
     * @throws Exception   If unsupported API method name is passed or data retrieving is failed
     */
    protected function getContent($connector, $api_method)
    {
        $url = self::API_BASE_URL . $api_method . '?v=5.54&';
        switch ($api_method) {
            case "wall.get":
                if (!empty($this->domain)) {
                    $url .= "domain={$this->domain}";
                } else {
                    $url .= "owner_id={$this->owner_id}";
                }
                $url .= "&count={$this->count}";
                break;
            case "users.get":
                $url .= "fields=first_name,last_name&user_ids=" . (!empty($this->domain) ? $this->domain : $this->owner_id);
                break;
            case "groups.getById":
                $url .= "fields=name&group_id=" . (!empty($this->domain) ? $this->domain : abs($this->owner_id));
                break;
            default:
                throw new Exception("Passed unsupported API method name '${api_method}'", 400);
        }
        $connector->openConnection();
        $content = null;
        try {
            $content = $connector->getContent($url, null, true);
            $connector->closeConnection();
        } catch (Exception $exc) {
            $connector->closeConnection();
            throw new Exception("Failed to get content of URL ${url}: " . $exc->getMessage(), $exc->getCode());
        }
        return json_decode($content);
    }

    /**
     * Generate title using text of post
     *
     * @param $description array   post paragraphs
     * @return string   generated title
     */
    protected function getTitle($description)
    {
        foreach ($description as &$paragraph) {
            $paragraph = preg_replace('/<[^>]+?>/u', '', $paragraph); // remove all tags
        }
        if (preg_match('/^\s*$/u', implode(PHP_EOL, $description)) === 1) {
            return self::EMPTY_POST_TITLE;
        }

        if (!function_exists('remove_underscores_from_hash_tag')) {
            function remove_underscores_from_hash_tag($match)
            {
                return str_replace('_', ' ', $match[1]);
            }
        }

        $curr_title_length = 0;
        $par_idx = 0;

        foreach ($description as $par_idx => &$paragraph) {
            $paragraph = trim($paragraph);
            if (preg_match('/^\s*(?:' . self::HASH_TAG_REGEX . '\s*)*$/u', $paragraph) === 1 // paragraph contains only hash tags
                    || $paragraph === self::VERTICAL_DELIMITER) {
                unset($description[$par_idx]);
                continue;
            }
            // hash tags (if exist) are semantic part of paragraph
            $paragraph = preg_replace_callback('/' . self::HASH_TAG_REGEX . '/u',
                                               'remove_underscores_from_hash_tag', # anonymous function only in PHP>=5.3.0
                                               $paragraph);

            if ($curr_title_length < self::MAX_TITLE_LENGTH
                    && (mb_strlen($paragraph) >= self::MIN_PARAGRAPH_LENGTH_FOR_TITLE
                    || $curr_title_length + self::MIN_PARAGRAPH_LENGTH_FOR_TITLE < self::MAX_TITLE_LENGTH)) {
                if (!in_array(mb_substr($paragraph, -1), array('.', '!', '?', ',', ':', ';'))) {
                    $paragraph .= '.';
                }
                $curr_title_length += mb_strlen($paragraph);
            } else {
                break;
            }
        }

        $full_title = implode(' ', array_slice($description, 0, $par_idx));
        if (mb_strlen($full_title) > self::MAX_TITLE_LENGTH) {
            $split = preg_split('/\s+/u', utf8_strrev(mb_substr($full_title, 0, self::MAX_TITLE_LENGTH)), 2);
            $full_title = utf8_strrev($split[1]);

            $last_char = mb_substr($full_title, -1);
            if (in_array($last_char, array(',', ':', ';', '-'))) {
                $full_title = mb_substr($full_title, 0, -1) . '...';
            } elseif (!in_array($last_char, array('.', '!', '?', ')'))) {
                $full_title .= '...';
            }
        }
        $full_title = mb_strtoupper(mb_substr($full_title, 0, 1)) . mb_substr($full_title, 1);
        return $full_title;
    }

}
