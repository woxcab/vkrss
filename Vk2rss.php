<?php

/*
 * @Description: vk.com Wall to RSS Class
 * @Author: tsarbas
 * @Website: http://sarbas.org
 *
 */

class Vk2rss {

  public $url = 'http://api.vk.com/method/wall.get', // url для получения записей со стены
    $owner_id, // идентификатор пользователя или сообщества, со стены которого необходимо получить записи // идентификатор сообщества необходимо указывать со знаком "-"
    $domain, // короткий адрес пользователя или сообщества
    $count = 10; // количество записей, которое необходимо получить (но не более 100)

  // Получаем записи со стены
  protected function getContent(){
    $url = $this->url . '?';
    if (!empty($this->domain)) {
      $url .= 'domain='.$this->domain;
    } elseif (!empty($this->owner_id)) {
      $url .= 'owner_id='.$this->owner_id;
    }
    $url .= '&count='.$this->count;
    $myCurl = curl_init();
    curl_setopt_array($myCurl, array(
      CURLOPT_URL => $url,
      CURLOPT_RETURNTRANSFER => true
    ));
    $response = curl_exec($myCurl);
    curl_close($myCurl);
    return $response;
  }

  // Генерируем RSS
  public function generateRSS(){
    include ('FeedWriter.php');
    include ('FeedItem.php');
    $feed = new FeedWriter(RSS2);
    $wall = json_decode($this->getContent());
    $title = !empty($this->domain) ? $this->domain : $this->owner_id;

    $feed->setTitle('vk.com/'.$title);
    $feed->setLink('http://vk.com/'.$title);
    $feed->setDescription('wall from vk.com/'.$title);

    $feed->setChannelElement('language', 'ru-ru');
    $feed->setChannelElement('pubDate', date(DATE_RSS, time()));

    for ($i = 1; $i<=count($wall->response)-1; $i++) {
      $newItem = $feed->createNewItem();
      $newItem->setLink("http://vk.com/wall{$owner_id}_{$wall->response[$i]->id}");
      $newItem->setDate($wall->response[$i]->date);
      $description = $wall->response[$i]->text;

      if (isset($wall->response[$i]->attachments)) {
        foreach ($wall->response[$i]->attachments as $attachment) {
          switch ($attachment->type) {
            case 'photo': {
              $description .= "<br><img src='{$attachment->photo->src_big}'/>";
              break;
            }
            case 'audio': {
              $description .= "<br><a href='http://vk.com/wall{$owner_id}_{$wall->response[$i]->id}'>{$attachment->audio->performer} &ndash; {$attachment->audio->title}</a>";
              break;
            }
            case 'doc': {
              $description .= "<br><a href='{$attachment->doc->url}'>{$attachment->doc->title}</a>";
              break;
            }
            case 'link': {
              $description .= "<br><a href='{$attachment->link->url}'>{$attachment->link->title}</a>";
              break;
            }
            case 'video': {
              $description .= "<br><a href='http://vk.com/video{$attachment->video->owner_id}_{$attachment->video->vid}'><img src='{$attachment->video->image_big}'/></a>";
              break;
            }
          }
        }
      }

      $newItem->setDescription($description);
      $newItem->addElement('guid', $wall->response[$i]->id);
      $feed->addItem($newItem);
    }
    $feed->genarateFeed();

  }

}