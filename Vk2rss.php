<?php

/**
 * @Description: vk.com Wall to RSS Class
 *
 * Initial creators
 * @author tsarbas
 * @author: kadukmm <nikolay.kaduk@gmail.com>
 **/

/**
 * Additional features:
 *   including & excluding conditions,
 *   title generation,
 *   description extraction from attachment
 *   hashtags extractor to 'category' tags
 * @author woxcab
 **/
class Vk2rss
{
    /**
     * Delimiter of text from different parts of post
     */
    const VERTICAL_DELIMITER = ' <br/> ________________ <br/> ';
    /**
     * Default title value when no text in the post
     */
    const EMPTY_TITLE = '[Без текста]';
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
    const API_URL = 'http://api.vk.com/method/wall.get';
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
     * Get posts of wall
     *
     * @return mixed   VK response
     */
    protected function getContent()
    {
        $url = self::API_URL . '?';
        if (!empty($this->domain)) {
            $url .= 'domain=' . $this->domain;
        } elseif (!empty($this->owner_id)) {
            $url .= 'owner_id=' . $this->owner_id;
        }
        $url .= '&count=' . $this->count . '&extended=1';
        $myCurl = curl_init();
        curl_setopt_array($myCurl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true
        ));
        $posts = curl_exec($myCurl);
        curl_close($myCurl);
        return json_decode($posts);
    }

    /**
     * Reverse UTF8 string
     *
     * @param $str string   source string
     * @return string   reversed string
     */
    static protected function utf8_strrev($str)
    {
        preg_match_all('/./us', $str, $ar);
        return join('', array_reverse($ar[0]));
    }

    /**
     * Generate title using text of post
     *
     * @param $description string   text of post
     * @return string   generated title
     */
    protected function getTitle($description)
    {
        $description = preg_replace('/#[\w_]+/u', '', $description); // remove hash tags
        $description = preg_replace('/(^(<br\/?>\s*?)+|(<br\/?>\s*?)+$|(<br\/?>\s*?)+(?=<br\/?>))/u', '', $description); // remove superfluous <br>
        $description = preg_replace('/<(?!br|br\/)[^>]+>/u', '', $description); // remove all tags exclude <br> (but leave all other tags starting with 'br'...)

        if (empty($description)) {
            return self::EMPTY_TITLE;
        }

        $splitDescription = explode("<br", $description);
        $currentTitleLength = 0;
        for ($part = 0;
             $part < count($splitDescription)
             and $currentTitleLength < self::MAX_TITLE_LENGTH
             and (mb_strlen($splitDescription[$part]) >= self::MIN_PARAGRAPH_LENGTH_FOR_TITLE or $currentTitleLength + self::MIN_PARAGRAPH_LENGTH_FOR_TITLE < self::MAX_TITLE_LENGTH);
             $currentTitleLength += mb_strlen($splitDescription[$part++])) {
            $splitDescription[$part] = str_replace(array("<br", "/>", ">"), '', $splitDescription[$part]);
            // $splitDescription[$part] = preg_replace('/(<[^>]*>)/u', '', $splitDescription[$part]);
        }
        $fullTitle = implode(' ', array_slice($splitDescription, 0, $part));
        if (mb_strlen($fullTitle) > self::MAX_TITLE_LENGTH) {
            $fullTitle = Vk2rss::utf8_strrev(explode(' ', Vk2rss::utf8_strrev(mb_substr($fullTitle, 0, self::MAX_TITLE_LENGTH)), 2)[1]) . "...";
        }
        return $fullTitle;
    }

    public function __construct($id, $count = 20, $include = NULL, $exclude = NULL)
    {
        if (empty($id)) {
            http_response_code(400);
            die("Valid identifier of user or group is absent");
        }

        if (strcmp(substr($id, 0, 2), 'id') === 0 && ctype_digit(substr($id, 2))) {
            $this->owner_id = (int)substr($id, 2);
            $this->domain = NULL;
        } elseif (strcmp(substr($id, 0, 4), 'club') === 0 && ctype_digit(substr($id, 4))) {
            $this->owner_id = -(int)substr($id, 4);
            $this->domain = NULL;
        } elseif (is_numeric($id) && is_int(abs($id))) {
            $this->owner_id = (int)$id;
            $this->domain = NULL;
        } else {
            $this->owner_id = NULL;
            $this->domain = $id;
        }
        $this->count = $count;
        $this->include = $include;
        $this->exclude = $exclude;
    }

    /**
     * Generate RSS feed as output
     */
    public function generateRSS()
    {
        include('FeedWriter.php');
        include('FeedItem.php');
        $response = $this->getContent();
        if (property_exists($response, 'error')) {
            http_response_code(400);
            die('Error ' . $response->error->error_code . ': ' . $response->error->error_msg);
        }
        $response = $response->response;
        $feed = new FeedWriter(RSS2);
        if ($response->profiles) {
            $profile = $response->profiles[0];
            $title = $profile->first_name . ' ' . $profile->last_name;
            $description = 'Wall of user ' . $profile->first_name . ' ' . $profile->last_name;
        } else {
            $group = $response->groups[0];
            $title = $group->name;
            $description = 'Wall of group ' . $group->name;
        }
        $id = $this->domain ? $this->domain :
            ($this->owner_id > 0 ? 'id' . $this->owner_id : 'club' . abs($this->owner_id));

        $feed->setTitle($title);
        $feed->setDescription($description);
        $feed->setLink('https://vk.com/' . $id);

        $feed->setChannelElement('language', 'ru-ru');
        $feed->setChannelElement('pubDate', date(DATE_RSS, time()));

        foreach (array_slice($response->wall, 1) as $post) {
            $newItem = $feed->createNewItem();
            $newItem->setLink("http://vk.com/wall{$post->to_id}_{$post->id}");
            $newItem->setDate($post->date);
            $description = $post->text;
            if (isset($post->copy_text)) { # additional content in re-posts
                $description .= (empty($description) ? '' : self::VERTICAL_DELIMITER)
                    . $post->copy_text;
            }
            if (isset($post->attachment->photo->text) and !empty($post->attachment->photo->text)) {
                $description .= (empty($description) ? '' : self::VERTICAL_DELIMITER)
                    . $post->attachment->photo->text;
            }
            if (isset($post->attachment->video->text) and !empty($post->attachment->video->text)) {
                $description .= (empty($description) ? '' : self::VERTICAL_DELIMITER)
                    . $post->attachment->video->text;
            }
            if (isset($post->attachment->link)) {
                $description .= (empty($description) ? '' : self::VERTICAL_DELIMITER)
                    . $post->attachment->link->title . '<br/>' . $post->attachment->link->description;
            }
            if (!is_null($this->include) && preg_match('/' . $this->include . '/iu', $description) !== 1) {
                continue;
            }
            if (!is_null($this->exclude) && preg_match('/' . $this->exclude . '/iu', $description) !== 0) {
                continue;
            }

            $hashTags = array();
            $description = preg_replace('/\[[^|]+\|([^\]]+)\]/u', '$1', $description); // remove internal vk links
            preg_match_all('/#([\d\w_]+)/u', $description, $hashTags);

            if (isset($post->attachments)) {
                foreach ($post->attachments as $attachment) {
                    switch ($attachment->type) {
                        case 'photo': {
                            $description .= "<br><img src='{$attachment->photo->src_big}'/>";
                            break;
                        }
                        /*case 'audio': {
                          $description .= "<br><a href='http://vk.com/wall{$owner_id}_{$post->id}'>{$attachment->audio->performer} &ndash; {$attachment->audio->title}</a>";
                          break;
                        }*/
                        case 'doc': {
                            $description .= "<br><a href='{$attachment->doc->url}'>{$attachment->doc->title}</a>";
                            break;
                        }
                        case 'link': {
                            $description .= "<br><a href='{$attachment->link->url}'>{$attachment->link->title}</a>";
                            break;
                        }
                        /*case 'video': {
                          $description .= "<br><a href='http://vk.com/video{$attachment->video->owner_id}_{$attachment->video->vid}'><img src='{$attachment->video->image_big}'/></a>";
                          break;
                        }*/
                    }
                }
            }

            $newItem->setDescription($description);
            $newItem->addElement('title', $this->getTitle($description));
            $newItem->addElement('guid', $post->id);

            foreach ($hashTags[1] as $hashTag) {
                $newItem->addElement('category', $hashTag);
            }

            $feed->addItem($newItem);
        }
        $feed->generateFeed();
    }

}
