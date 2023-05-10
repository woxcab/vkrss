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
 *   description extraction from attachment,
 *   hashtags extractor to 'category' tags,
 *   author extraction
 * @author woxcab
 **/

require_once('utils.php');

set_error_handler('exceptions_error_handler');

function exceptions_error_handler($severity, $message, $filename, $lineno) {
    if (error_reporting() == 0) {
        return;
    }
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
    }
}

class Vk2rss
{
    const HASH_TAG_PATTERN = '#([а-яёА-ЯЁa-zA-Z0-9_]+)(?:@[a-zA-Z0-9_]+)?';
    const TEXTUAL_LINK_PATTERN = '@(?:\[((?:https?://)?(?:m\.)?vk\.com/[^|]*)\|([^\]]+)\]|(\s*)\b(https?://\S+?)(?=[.,!?;:»”’"]?(?:\s|$))|(\()(https?://\S+?)(\))|(\([^(]*?)(\s*)\b(\s*https?://\S+?)([.,!?;:»”’"]?\s*\)))@u';
    const TEXTUAL_LINK_REPLACE_PATTERN = '$3$5$8$9<a href=\'$1$4$6${10}\'>$2$4$6${10}</a>$7${11}';
    const TEXTUAL_LINK_REMOVE_PATTERN = '$2$5$8';
    const EMPTY_STRING_PATTERN = '@^(?:[\s ]*(?:<br/?>|\n)+[\s ]*)*$@u';

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
    const GROUP_FEED_DESCRIPTION_PREFIX = "Стена сообщества ";
    /**
     * Feed title and description prefix when global searching is performed
     */
    const GLOBAL_SEARCH_FEED_TITLE_PREFIX = "Результаты поиска по запросу ";
    /**
     * Video title
     */
    const VIDEO_TITLE_PREFIX = "Видеозапись";
    /**
     * Audio record title
     */
    const AUDIO_TITLE_PREFIX = "Аудиозапись";
    /**
     * Image title
     */
    const IMAGE_TITLE_PREFIX = "Изображение";
    /**
     * Album title
     */
    const ALBUM_TITLE_PREFIX = "Альбом";
    /**
     * Non-image file title
     */
    const FILE_TITLE_PREFIX = "Файл";
    /**
     * Title of repost (in the prepositional case) that's written by community
     */
    const COMMUNITY_REPOST_TITLE_ABL = "в сообществе";
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
    const API_BASE_URL = 'https://api.vk.com/method/';

    /**
     * @var int   identifier of user or group which wall going to be extracted
     */
    protected $owner_id;
    /**
     * @var string   short address of user or group which wall going to be extracted
     */
    protected $domain;
    /**
     * @var string   search query to lookup on all opened walls
     */
    protected $global_search;
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
     * @var bool   whether <splash:comments> (comments amount) should be presented in the feed item
     */
    protected $disable_comments_amount;
    /**
     * @var bool   whether the post should be published by community/profile owner only
     */
    protected $owner_only;
    /**
     * @var bool   whether the post should be published by anyone except community/profile owner
     */
    protected $non_owner_only;
    /**
     * @var bool   whether posts (that's published by community) with signature are allowed
     *             when owner_only or non_owner_only/not_owner_only parameter is passed
     */
    protected $allow_signed;
    /**
     * @var bool   whether posts are skipped that's marked as ad
     */
    protected $skip_ads;
    /**
     * @var string   text or HTML formatted string that's placed between parent and child posts
     */
    protected $repost_delimiter;
    /**
     * @var string|null   Service token to get access to opened walls (there's in app settings)
     *                    or user access token to get access to closed walls that opened for token creator
     */
    protected $access_token;

    /**
     * @var ProxyDescriptor|null   Proxy descriptor
     */
    protected $proxy = null;

    /**
     * @var string   text or HTML formatted string that's placed between post' content and the first attachment,
     *               and between attachments
     */
    protected $attachment_delimiter;
    /**
     * @var string   regular expression that matches delimiter
     */
    protected $delimiter_regex;

    /**
     * @var bool   whether the access token has video permission and video embedding is enabled
     */
    protected $allow_embedded_video;

    /**
     * @var bool $donut   whether include donut posts to the feed or not
     */
    protected $donut;

    /**
    * @var ConnectionWrapper
    */
    protected $connector;

    /**
     * Vk2rss constructor.
     * @param array $config    Parameters from the set: id, access_token,
     *                           count, include, exclude, disable_html, owner_only, non_owner_only,
     *                           disable_comments_amount, allow_signed, skip_ads, repost_delimiter,
     *                           proxy, proxy_type, proxy_login, proxy_password
     *                         where id and access_token are required
     * @throws Exception   If required parameters id or access_token do not present in the configuration
     *                     or proxy parameters are invalid
     */
    public function __construct($config)
    {
        if (!((empty($config['id']) xor empty($config['global_search']))
              && !empty($config['access_token']))) {
            throw new Exception("Identifier of user or community and access/service token ".
                                "OR global search query and access/service token must be passed", 400);
        }
        $this->access_token = $config['access_token'];
        $id = $config['id'];
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
        $this->global_search = empty($config['global_search']) ? null : $config['global_search'];
        $this->count = empty($config['count']) ? 20 : $config['count'];
        $this->include = isset($config['include']) && $config['include'] !== ''
            ? preg_replace("/(?<!\\\)\//u", "\\/", $config['include']) : null;
        $this->exclude = isset($config['exclude']) && $config['exclude'] !== ''
            ? preg_replace("/(?<!\\\)\//u", "\\/", $config['exclude']) : null;
        $this->disable_html = logical_value($config, 'disable_html');
        $this->disable_comments_amount = logical_value($config, 'disable_comments_amount');
        $this->owner_only = logical_value($config, 'owner_only');
        $this->non_owner_only = logical_value($config, 'non_owner_only') || logical_value($config, 'not_owner_only');
        $this->allow_signed = logical_value($config, 'allow_signed');
        $this->skip_ads =  logical_value($config, 'skip_ads');
        $this->allow_embedded_video =  logical_value($config, 'allow_embedded_video');
        $this->repost_delimiter = isset($config['repost_delimiter'])
            ? $config['repost_delimiter']
            : ($this->disable_html ? "______________________" : "<hr><hr>");
        $this->attachment_delimiter = $this->disable_html ? "______________________" : "<hr>";
        if (preg_match('/\{author[^}]*\}/', $this->repost_delimiter) === 1) {
            $this->delimiter_regex = '/^' . preg_quote($this->attachment_delimiter, '/') . '$/u';
        } else {
            $this->delimiter_regex = '/^(' . preg_quote($this->repost_delimiter, '/')
                . '|' . preg_quote($this->attachment_delimiter, '/') . '$)/u';
        }
        if (isset($config['proxy'])) {
            try {
                $this->proxy = new ProxyDescriptor($config['proxy'],
                                                   isset($config['proxy_type']) ? $config['proxy_type'] : null,
                                                   isset($config['proxy_login']) ? $config['proxy_login'] : null,
                                                   isset($config['proxy_password']) ? $config['proxy_password'] : null);
            } catch (Exception $exc) {
                throw new Exception("Invalid proxy parameters: " . $exc->getMessage(), 400);
            }
        }
        if (isset($config['donut'])) {
            $this->donut = logical_value($config, 'donut');
        }

        $this->connector = new ConnectionWrapper($this->proxy);
    }

    /**
     * Generate RSS feed as output
     *
     * @throws Exception
     */
    public function generateRSS()
    {
        include('FeedWriter.php');

        $outer_encoding = mb_internal_encoding();
        if (!mb_internal_encoding("UTF-8")) {
            throw new Exception("Cannot set encoding UTF-8 for multibyte strings", 500);
        }

        $feed = new FeedWriter(RSS2);
        $id = $this->domain ?: ($this->owner_id > 0 ? 'id' . $this->owner_id : 'club' . abs($this->owner_id));

        $feed->setLink('https://vk.com/' . $id);

        $feed->setChannelElement('language', 'ru-ru');
        $feed->setChannelElement('pubDate', date(DATE_RSS, time()));

        $profiles = array();
        $groups = array();
        $next_from = null;
        $offset_step = empty($this->global_search) ? 100 : 200;

        if (empty($this->global_search) && $this->donut) {
            for ($offset = 0; $offset < $this->count; $offset += $offset_step) {
                $wall_response = $this->getContent("wall.get", $offset, true);
                if (!$this->processWallResponse($feed, $wall_response, $profiles, $groups)) {
                    break;
                }
            }
        }

        for ($offset = 0; $offset < $this->count; $offset += $offset_step) {
            if (empty($this->global_search)) {
                $wall_response = $this->getContent("wall.get", $offset);
            } else {
                $wall_response = $this->getContent("newsfeed.search", $next_from);
            }
            if (!empty($this->global_search)) {
                $next_from = empty($wall_response->response->next_from)
                    ? null : $wall_response->response->next_from;
            }
            if (!$this->processWallResponse($feed, $wall_response, $profiles, $groups)) {
                break;
            }

            if (!empty($this->global_search) && is_null($next_from)) {
                break;
            }
        }

        try {
            if (!empty($this->global_search)) {
                $feed_title = self::GLOBAL_SEARCH_FEED_TITLE_PREFIX . '"' . $this->global_search . '"';
                $feed_description = $feed_title;
            } elseif (!empty($this->domain) && isset($profiles[$this->domain])
                || (!empty($this->owner_id) && $this->owner_id > 0)
            ) {
                $profile = isset($profiles[$this->domain]) ? $profiles[$this->domain] : $profiles[$this->owner_id];
                $feed_title = $profile->first_name . ' ' . $profile->last_name;
                $feed_description = self::USER_FEED_DESCRIPTION_PREFIX . $profile->first_name . ' ' . $profile->last_name;
            } else {
                $group = isset($groups[$this->domain]) ? $groups[$this->domain] : $groups[abs($this->owner_id)];
                $feed_title = $group->name;
                $feed_description = self::GROUP_FEED_DESCRIPTION_PREFIX . $group->name;
            }
        } catch (Exception $exc) {
            throw new Exception("Invalid user/group identifier, its wall is empty, or empty search result", 400);
        }

        $feed->setTitle($feed_title);
        $feed->setDescription($feed_description);

        $feed->generateFeed();
        mb_internal_encoding($outer_encoding);
    }

    /**
     * @param FeedWriter $feed
     * @param $wall_response
     * @param array $profiles
     * @param array $groups
     *
     * @return bool   Whether response contains at least one item or not
     * @throws \APIError
     */
    protected function processWallResponse($feed, $wall_response, &$profiles, &$groups) {
        if (property_exists($wall_response, 'error')) {
            throw new APIError($wall_response, $this->connector->getLastUrl());
        }
        if (empty($wall_response->response->items)) {
            return false;
        }

        $videos = array();
        if ($this->allow_embedded_video) {
            $this->extractVideos($videos, $wall_response->response->items);
            foreach (array_chunk($videos, 200) as $videos_chunk) {
                $videos_str = join(",", array_map(function($v) { return empty($v->access_key)
                    ? "{$v->owner_id}_{$v->id}"
                    : "{$v->owner_id}_{$v->id}_{$v->access_key}"; },
                    $videos_chunk));
                $videos_response = $this->getContent("video.get", null, false, array("videos" => $videos_str));
                if (property_exists($videos_response, 'error')) {
                    $error_code = $videos_response->error->error_code;
                    if ($error_code == 204 || $error_code == 15) {
                        $this->allow_embedded_video = false;
                        break;
                    } else {
                        throw new APIError($videos_response, $this->connector->getLastUrl());
                    }
                } else {
                    foreach ($videos_response->response->items as $video_info) {
                        $videos["{$video_info->owner_id}_{$video_info->id}"] = $video_info;
                    }
                }
            }
        }

        foreach ($wall_response->response->profiles as $profile) {
            if (!isset($profile->screen_name)) {
                $profile->screen_name = "id{$profile->id}";
            }
            $profiles[$profile->screen_name] = $profile;
            $profiles[$profile->id] = $profile;
        }
        foreach ($wall_response->response->groups as $group) {
            if (!isset($group->screen_name)) {
                $group->screen_name = "club{$group->id}";
            }
            $groups[$group->screen_name] = $group;
            $groups[$group->id] = $group;
        }

        foreach ($wall_response->response->items as $post) {
            if ($post->id == 0
                || $this->owner_only
                    && ($post->owner_id != $post->from_id
                        || !is_null($this->allow_signed) && property_exists($post, 'signer_id')
                        && !$this->allow_signed)
                || $this->non_owner_only && $post->owner_id == $post->from_id
                    && (is_null($this->allow_signed) || !property_exists($post, 'signer_id')
                        || !$this->allow_signed)
                || $this->skip_ads && $post->marked_as_ads) {
                continue;
            }
            $new_item = $feed->createNewItem();
            $new_item->setLink("https://vk.com/wall{$post->owner_id}_{$post->id}");
            $new_item->addElement('guid', "https://vk.com/wall{$post->owner_id}_{$post->id}");
            $new_item->setDate($post->date);

            $description = array();
            $this->extractDescription($description, $videos, $post, $profiles, $groups);
            if (!empty($description) && preg_match($this->delimiter_regex, $description[0]) === 1) {
                array_shift($description);
            }

            foreach ($description as &$paragraph) {
                // process internal short vk links like [id123|Name]
                if ($this->disable_html) {
                    $paragraph = preg_replace('/\[([a-zA-Z0-9_]+)\|([^\]]+)\]/u', '$2 (https://vk.com/$1)', $paragraph);
                } else {
                    $paragraph = preg_replace('/\[([a-zA-Z0-9_]+)\|([^\]]+)\]/u', '<a href="https://vk.com/$1">$2</a>', $paragraph);
                }
            }

            $imploded_description = implode($this->disable_html ? PHP_EOL : '<br/>', $description);
            $new_item->setDescription($imploded_description);

            if (isset($this->include) && preg_match('/' . $this->include . '/iu', $imploded_description) !== 1) {
                continue;
            }
            if (isset($this->exclude) && preg_match('/' . $this->exclude . '/iu', $imploded_description) !== 0) {
                continue;
            }

            $new_item->addElement('title', $this->getTitle($description));
            $new_item->addElement("comments", "https://vk.com/wall{$post->owner_id}_{$post->id}");
            if (!$this->disable_comments_amount) {
                $new_item->addElement("slash:comments", $post->comments->count);
            }
            if (isset($post->signer_id) && isset($profiles[$post->signer_id])) { # the 2nd owing to VK API bug
                $profile = $profiles[$post->signer_id];
                $new_item->addElement('author', $profile->first_name . ' ' . $profile->last_name);
            } else {
                $base_post = isset($post->copy_history) ? end($post->copy_history) : $post;
                if (isset($base_post->signer_id) && isset($profiles[$base_post->signer_id])) { # the 2nd owing to VK API bug
                    $profile = $profiles[$base_post->signer_id];
                    $new_item->addElement('author', $profile->first_name . ' ' . $profile->last_name);
                } elseif ($base_post->from_id > 0) {
                    $profile = $profiles[$base_post->from_id];
                    $new_item->addElement('author', $profile->first_name . ' ' . $profile->last_name);
                } elseif ($base_post->from_id < 0) {
                    $group = $groups[abs($base_post->from_id)];
                    $new_item->addElement('author', $group->name);
                } elseif ($base_post->owner_id > 0) {
                    $profile = $profiles[$base_post->owner_id];
                    $new_item->addElement('author', $profile->first_name . ' ' . $profile->last_name);
                } elseif ($base_post->owner_id < 0) {
                    $group = $groups[abs($base_post->owner_id)];
                    $new_item->addElement('author', $group->name);
                }
            }

            preg_match_all('/' . self::HASH_TAG_PATTERN . '/u', implode(' ', $description), $hash_tags);

            foreach ($hash_tags[1] as $hash_tag) {
                $new_item->addElement('category', $hash_tag);
            }

            $feed->addItem($new_item);
        }
        return true;
    }

    protected function extractVideos(&$videos, &$posts) {
        foreach ($posts as $post) {
            if (isset($post->attachments)) {
                foreach ($post->attachments as $attachment) {
                    if ($attachment->type === 'video') {
                        $video = $attachment->video;
                        $videos["{$video->owner_id}_{$video->id}"] = $video;
                    }
                }
            }
            if (isset($post->copy_history)) {
                $this->extractVideos($videos, $post->copy_history);
            }
        }
    }

    protected function extractDescription(&$description, &$videos, $post, &$profiles, &$groups)
    {
        $par_split_regex = '@[\s ]*?(?:<br/?>|\n)+[\s ]*?@u'; # PHP 5.2.X: \s does not contain non-break space

        if (preg_match(self::EMPTY_STRING_PATTERN, $post->text) === 0) {
            $post_text = htmlspecialchars($post->text, ENT_NOQUOTES);
            if (!$this->disable_html) {
                $post_text = preg_replace(self::TEXTUAL_LINK_PATTERN,
                                          self::TEXTUAL_LINK_REPLACE_PATTERN,
                                          $post_text);
            }
            $description = array_merge($description, preg_split($par_split_regex, $post_text));
        }

        if (isset($post->attachments)) {
            foreach ($post->attachments as $attachment) {
                switch ($attachment->type) {
                    case 'photo': {
                        list($preview_photo_src, $huge_photo_src) = $this->getPreviewAndOriginal($attachment->photo->sizes);
                        $photo_text = preg_replace('|^Original: https?://\S+\s*|u',
                                                   '',
                                                   htmlspecialchars($attachment->photo->text, ENT_NOQUOTES));
                        if (!$this->disable_html) {
                            $photo_text = preg_replace(self::TEXTUAL_LINK_PATTERN,
                                                       self::TEXTUAL_LINK_REPLACE_PATTERN,
                                                       $photo_text);
                        }
                        $photo = $this->disable_html
                            ? $huge_photo_src
                            : "<a href='{$huge_photo_src}'><img src='{$preview_photo_src}'/></a>";
                        if (preg_match(self::EMPTY_STRING_PATTERN, $photo_text) === 0) {
                            $description = array_merge($description,
                                                       array($this->attachment_delimiter),
                                                       preg_split($par_split_regex, $photo_text),
                                                       array($photo));
                        } else {
                            $description[] = $photo;
                        }
                        break;
                    }
                    case 'album': {
                        list($preview_thumb_src, $huge_thumb_src) = $this->getPreviewAndOriginal($attachment->album->thumb->sizes);
                        $album_title = htmlspecialchars($attachment->album->title, ENT_NOQUOTES);
                        $album_url = "https://vk.com/album" . $attachment->album->owner_id . "_" . $attachment->album->id;
                        $description[] = $this->attachment_delimiter;
                        if ($this->disable_html) {
                            $description[] = self::ALBUM_TITLE_PREFIX . " «" . $album_title . "»: " . $album_url;
                        } else {
                            $description[] = "<a href='{$album_url}'>" . self::ALBUM_TITLE_PREFIX . " «" . $album_title . "»</a>";
                        }
                        $album_description = htmlspecialchars($attachment->album->description, ENT_NOQUOTES);
                        if (!$this->disable_html) {
                            $album_description = preg_replace(self::TEXTUAL_LINK_PATTERN,
                                                       self::TEXTUAL_LINK_REPLACE_PATTERN,
                                                       $album_description);
                        }
                        if (preg_match(self::EMPTY_STRING_PATTERN, $album_description) === 0) {
                            $description = array_merge($description, preg_split($par_split_regex, $album_description));
                        }
                        $thumb = $this->disable_html
                            ? $huge_thumb_src
                            : "<a href='{$huge_thumb_src}'><img src='{$preview_thumb_src}'/></a>";
                        $description[] = $thumb;
                        break;
                    }
                    case 'audio': {
                        $title = self::AUDIO_TITLE_PREFIX . " {$attachment->audio->artist} — «{$attachment->audio->title}»";
                        $description[] = htmlspecialchars($title, ENT_NOQUOTES);
                        break;
                    }
                    case 'doc': {
                        $url = parse_url($attachment->doc->url);
                        parse_str($url['query'], $params);
                        unset($params['api']);
                        unset($params['dl']);
                        $url['query'] = http_build_query($params);
                        $url = build_url($url);
                        $title = htmlspecialchars($attachment->doc->title, ENT_NOQUOTES);
                        if (!empty($attachment->doc->preview->photo)) {
                            list($preview_src, $huge_photo_src) = $this->getPreviewAndOriginal($attachment->doc->preview->photo->sizes);
                            if ($this->disable_html) {
                                $description[] = self::IMAGE_TITLE_PREFIX . " «{$title}»: {$huge_photo_src} ({$url})";
                            } else {
                                array_push($description,
                                           $this->attachment_delimiter,
                                           "<a href='{$url}'>" . self::IMAGE_TITLE_PREFIX . " «{$title}»</a>:",
                                           "<a href='{$huge_photo_src}'><img src='{$preview_src}'/></a>");
                            }
                        } else {
                            if ($this->disable_html) {
                                $description[] = self::FILE_TITLE_PREFIX . " «{$title}»: {$url}";
                            } else {
                                $description[] = "<a href='{$url}'>" . self::FILE_TITLE_PREFIX . " «{$title}»</a>";
                            }
                        }
                        break;
                    }
                    case 'link': {
                        if ($this->disable_html) {
                            array_push($description,
                                       $this->attachment_delimiter,
                                       "{$attachment->link->title}: {$attachment->link->url}");
                        } else {
                            $link_text = preg_match(self::EMPTY_STRING_PATTERN, $attachment->link->title) === 0 ?
                                htmlspecialchars($attachment->link->title, ENT_NOQUOTES) : $attachment->link->url;
                            if (!empty($attachment->link->photo)) {
                                list($preview_src, $_) = $this->getPreviewAndOriginal($attachment->link->photo->sizes);
                                array_push($description,
                                           $this->attachment_delimiter,
                                           "<a href='{$attachment->link->url}'><img src='{$preview_src}'/></a>",
                                           "<a href='{$attachment->link->url}'>{$link_text}</a>");
                            } else {
                                array_push($description,
                                           $this->attachment_delimiter,
                                           "<a href='{$attachment->link->url}'>{$link_text}</a>");
                            }
                        }
                        if (!empty($attachment->link->description)
                            && preg_match(self::EMPTY_STRING_PATTERN, $attachment->link->description) === 0) {
                            $description[] = htmlspecialchars($attachment->link->description, ENT_NOQUOTES);
                        }
                        break;
                    }
                    case 'video': {
                        $restricted = true;
                        if (isset($attachment->video->restriction)) {
                            $video_text = $attachment->video->restriction->text;
                        } elseif (!empty($attachment->video->content_restricted)) {
                            $video_text = $attachment->video->content_restricted_message;
                        } else {
                            $video_text = $attachment->video->description;
                            $restricted = false;
                        }
                        $video_text = htmlspecialchars($video_text, ENT_NOQUOTES);
                        if (!$this->disable_html) {
                            $video_text = preg_replace(self::TEXTUAL_LINK_PATTERN,
                                                       self::TEXTUAL_LINK_REPLACE_PATTERN,
                                                       $video_text);
                        }
                        $video_description = preg_match(self::EMPTY_STRING_PATTERN, $video_text) === 1 ?
                            array() : preg_split($par_split_regex, $video_text);
                        if (empty($attachment->video->title)) {
                            $content = array(self::VIDEO_TITLE_PREFIX . ":");
                        } else {
                            $content = array(self::VIDEO_TITLE_PREFIX . " «{$attachment->video->title}»:");
                        }
                        if ($video_description) {
                            array_unshift($content, $this->attachment_delimiter);
                        }

                        $video_id = "{$attachment->video->owner_id}_{$attachment->video->id}";
                        $playable = !$restricted && !empty($videos[$video_id]) && !empty($videos[$video_id]->player);
                        $video_url = $playable ? $videos[$video_id]->player : "https://vk.com/video{$video_id}";

                        if ($this->disable_html) {
                            $content[] = $video_url;
                        } else {
                            if ($playable) {
                                $content[] = "<iframe src='${video_url}'>${video_url}</iframe>";
                            } else {
                                list($preview_src, $_) = $this->getPreviewAndOriginal($attachment->video->image);
                                $content[] = "<a href='${video_url}'><img src='{$preview_src}'/></a>";
                            }
                        }
                        $description = array_merge($description, $content, $video_description);
                        break;
                    }
                    case 'page':
                        if ($this->disable_html) {
                            array_push($description,
                                       $this->attachment_delimiter,
                                       "{$attachment->page->title}: https://vk.com/page-{$attachment->page->group_id}_{$attachment->page->id}");
                        } else {
                            array_push($description,
                                       $this->attachment_delimiter,
                                       "<a href='https://vk.com/page-{$attachment->page->group_id}_{$attachment->page->id}'>{$attachment->page->title}</a>");
                        }
                        break;
                }
            }
        }

        if (isset($post->copy_history)) {
            foreach ($post->copy_history as $repost) {
                $author_id = isset($repost->signer_id) && isset($profiles[$repost->signer_id])
                    ? $repost->signer_id : $repost->owner_id;
                if ($author_id < 0) {
                    $author_name = $groups[abs($author_id)]->name;
                    $author_link = 'https://vk.com/' . $groups[abs($author_id)]->screen_name;
                } else {
                    $author_name = $profiles[$author_id]->first_name . ' '
                        . $profiles[$author_id]->last_name;
                    $author_name_ins = $profiles[$author_id]->first_name_ins . ' '
                        . $profiles[$author_id]->last_name_ins;
                    $author_name_gen = $profiles[$author_id]->first_name_gen . ' '
                        . $profiles[$author_id]->last_name_gen;
                    $author_link = 'https://vk.com/' . $profiles[$author_id]->screen_name;
                    $author_ins = $this->disable_html
                        ? "$author_name_ins ($author_link)"
                        : "<a href='$author_link'>$author_name_ins</a>";
                    $author_gen = $this->disable_html
                        ? "$author_name_gen ($author_link)"
                        : "<a href='$author_link'>$author_name_gen</a>";
                }
                $author = $this->disable_html
                    ? "$author_name ($author_link)"
                    : "<a href='$author_link'>$author_name</a>";
                if (!isset($author_ins)) {
                    $author_ins = $author;
                }
                if (!isset($author_gen)) {
                    $author_gen = $author;
                }
                if (isset($repost->signer_id)) {
                    $repost_owner = $groups[abs($repost->owner_id)]->name;
                    $repost_owner_url = "https://vk.com/{$groups[abs($repost->owner_id)]->screen_name}";
                    if ($this->disable_html) {
                        $repost_place = " " .self::COMMUNITY_REPOST_TITLE_ABL . " $repost_owner ($repost_owner_url)";
                    } else {
                        $repost_place = " " . self::COMMUNITY_REPOST_TITLE_ABL . " <a href='$repost_owner_url'>$repost_owner</a>";
                    }
                    $author .= $repost_place;
                    $author_gen .= $repost_place;
                    $author_ins .= $repost_place;
                }
                $repost_delimiter = preg_replace('/\{author\}/u', $author, $this->repost_delimiter);
                $repost_delimiter = preg_replace('/\{author_ins\}/u', $author_ins, $repost_delimiter);
                $repost_delimiter = preg_replace('/\{author_gen\}/u', $author_gen, $repost_delimiter);
                $description[] = $repost_delimiter;
                $this->extractDescription($description, $videos, $repost, $profiles, $groups);
                if (preg_match($this->delimiter_regex, end($description)) === 1) {
                    array_pop($description);
                }
            }
        }
    }

    /**
     * Get posts of wall
     *
     * @param string $api_method API method name
     * @param int    $offset     offset for wall.get
     * @param bool   $donut      whether retrieve donut only posts or others
     * @param array  $params     additional key-value request parameters
     *
     * @return mixed   Json VK response in appropriate PHP type
     * @throws Exception   If unsupported API method name is passed or data retrieving is failed
     */
    protected function getContent($api_method, $offset = null, $donut = false,
                                  $params = array('extended'=> '1', 'fields' => 'first_name_ins,last_name_ins,first_name_gen,last_name_gen,screen_name'))
    {
        $url = self::API_BASE_URL . $api_method . '?v=5.131';
        if (isset($this->access_token)) {
            $url .= "&access_token={$this->access_token}";
        }
        switch ($api_method) {
            case "wall.get":
                if (!empty($this->domain)) {
                    $url .= "&domain={$this->domain}";
                } else {
                    $url .= "&owner_id={$this->owner_id}";
                }
                $default_count = 100;
                if (!empty($offset)) {
                    $url .= "&offset=${offset}";
                }
                if ($donut) {
                    $url .= "&filter=donut";
                }
                break;
            case "newsfeed.search":
                $url .= "&q=" . urlencode($this->global_search);
                $default_count = 200;
                if (!empty($offset)) {
                    $url .= "&start_from=${offset}";
                }
                break;
            case "video.get":
                $default_count = 200;
                if (!empty($offset)) {
                    $url .= "&offset=${offset}";
                }
                break;
            default:
                throw new Exception("Passed unsupported VK API method name '${api_method}'", 400);
        }
        foreach ($params as $key => $value) {
            $url .= "&${key}=${value}";
        }

        $total_count = ($api_method === "video.get") ? 200 : $this->count;
        if (!empty($offset) && $api_method !== "newsfeed.search") {
            $count = min($total_count - $offset, $default_count);
        } else {
            $count = min($total_count, $default_count);
        }
        $url .= "&count={$count}";

        $this->connector->openConnection();
        try {
            $content = $this->connector->getContent($url, null, true);
            $this->connector->closeConnection();
            return json_decode($content);
        } catch (Exception $exc) {
            $this->connector->closeConnection();
            throw new Exception("Failed to get content of URL ${url}: " . $exc->getMessage(), $exc->getCode());
        }
    }

    /**
     * Generate title using text of post
     *
     * @param array $raw_description   post paragraphs
     * @return string   generated title
     */
    protected function getTitle($raw_description)
    {
        if (empty($raw_description)) {
            return self::EMPTY_POST_TITLE;
        }
        $description = array_fill(0, count($raw_description), null);
        foreach ($raw_description as $par_idx => $par) {
            $description[$par_idx] = $par;
        }
        $repost_delimiter_regex = '/^' . preg_replace('/\\\{author[^}]*\\\}/u', '.*', preg_quote($this->repost_delimiter, '/')) . '$/su';
        foreach ($description as $par_idx => &$paragraph) {
            if (preg_match('/^\s*(?:' . self::HASH_TAG_PATTERN . '\s*)*$/u', $paragraph) === 1 // paragraph contains only hash tags
                || preg_match($this->delimiter_regex, $paragraph) === 1
                || preg_match($repost_delimiter_regex, $paragraph) === 1) {
                unset($description[$par_idx]);
                continue;
            }
            if (!$this->disable_html) {
                $paragraph = preg_replace('/<[^>]+?>/u', '', $paragraph); // remove all tags
                $paragraph = strtr($paragraph, array('&lt;' => '<',
                                                     '&gt;' => '>',
                                                     '&quot;' => '"' ,
                                                     '&apos;' => '\''));
            }
            if (preg_match($this->delimiter_regex, $paragraph) === 1) {
                $paragraph = "";
            } else {
                $paragraph = trim(preg_replace(self::TEXTUAL_LINK_PATTERN, self::TEXTUAL_LINK_REMOVE_PATTERN, $paragraph));
            }
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
        $slice_length = 0;

        foreach ($description as $par_idx => &$paragraph) {
            // hash tags (if exist) are semantic part of paragraph
            $paragraph = preg_replace_callback('/' . self::HASH_TAG_PATTERN . '/u',
                                               'remove_underscores_from_hash_tag', # anonymous function only in PHP>=5.3.0
                                               $paragraph);

            if ($curr_title_length < self::MAX_TITLE_LENGTH) {
                if (preg_match(self::EMPTY_STRING_PATTERN, $paragraph) === 1) {
                    unset($description[$par_idx]);
                    continue;
                }
                if(mb_strlen($paragraph) >= self::MIN_PARAGRAPH_LENGTH_FOR_TITLE
                   || $curr_title_length + self::MIN_PARAGRAPH_LENGTH_FOR_TITLE < self::MAX_TITLE_LENGTH) {
                    if (!in_array(mb_substr($paragraph, -1), array('.', '!', '?', ',', ':', ';'))) {
                        $paragraph .= '.';
                    }
                    $curr_title_length += mb_strlen($paragraph);
                    $slice_length += 1;
                } else {
                    break;
                }
            }
        }

        $full_title = implode(' ', array_slice($description, 0, $slice_length));
        if (mb_strlen($full_title) > self::MAX_TITLE_LENGTH) {
            $split = preg_split('/\s+/u', utf8_strrev(mb_substr($full_title, 0, self::MAX_TITLE_LENGTH)), 2);
            $full_title = isset($split[1]) ? utf8_strrev($split[1]) : utf8_strrev($split[0]);

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

    protected function getPreviewAndOriginal($sizes) {
        $photos = array();
        $typable = true;
        array_walk($sizes, function(&$size_info, $k) use (&$photos, &$typable) {
            $url = isset($size_info->url) ? $size_info->url : $size_info->src;
            if (isset($size_info->type)) {
                $photos[$size_info->type] = $url;
            } else {
                $typable = false;
                $photos[$size_info->width] = $url;
            }
        });
        $huge_photo_src = null;
        $preview_photo_src = null;
        if ($typable) {
            foreach (array('w', 'z', 'y', 'x', 'r', 'q', 'p', 'm', 'o', 's') as $type) {
                if (array_key_exists($type, $photos)) {
                    $huge_photo_src = $photos[$type];
                    break;
                }
            }
            foreach (array('x', 'r', 'q', 'p', 'o', 'm', 's') as $type) {
                if (array_key_exists($type, $photos)) {
                    $preview_photo_src = $photos[$type];
                    break;
                }
            }
        } else {
            ksort($photos);
            foreach ($photos as $width => $photo) {
                if ($width > 800) {
                    break;
                }
                $preview_photo_src = $photo;
            }
            $huge_photo_src = end($photos);
        }
        return array($preview_photo_src, $huge_photo_src);
    }
}
