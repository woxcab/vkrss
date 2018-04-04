[English](#eng) | [Russian](#rus)

---

# <a name="eng"></a> Generating RSS Feed for opened or closed wall of user or community (group, public page or event page) on vk.com

## Features
* Generating RSS feed of opened wall: data extraction from different
  post parts (attachments included) and automatic title generation
  of RSS items.
* Also generating RSS feed of closed wall if there's access token
  with offline permissions that's created by user who has access
  to the closed wall. [See more here](#eng-user-access-token)
  about user access token creating.
* Generating RSS feed for different opened walls based on 
  [global search](#eng-global-search) results.
* Feeding [arbitrary number](#eng-count) of posts.
* Posts filtering [by author](#eng-owning): all posts, posts by community/profile owner
  only or all posts except posts by community/profile owner.
* Posts filtering by [signature presence](#eng-sign).
* Posts filtering by [regular expression](#eng-regex) (PCRE notation)
  matching and/or mismatching.
* Optionally [ad posts skipping](#eng-ads) [disabled by default].
* Extracting RSS categories from the post hash tags.
* Optionally [HTML formatting](#eng-html) of RSS item description:
  links, images, line breaks [enabled by default].
* HTTPS, SOCKS4, SOCKS4A or SOCKS5 [proxy usage](#eng-proxy) is available.
* Each feed item has author name (post signer/publisher or source post
  signer/publisher if wall post is the repost).
* Customizable [repost delimiter](#eng-repost-delimiter) with substitutions.


## Requirements
* PHP>=5.2.2 (5.3.X, 5.4.X, 5.5.X, 5.6.X, 7.X included)
  with installed `mbstring`, `json`, `pcre`, `openssl` bundled extensions.
* Script prefers the built-in tools for the requests.
  If `allow_url_fopen` parameter is disabled in the PHP configuration
  file or interpreter parameters and `cURL` PHP extension is installed
  then script uses `cURL` for the requests.
* If you want to use proxy server then
  * for HTTPS proxy: either `cURL`>=7.10 extension must be installed
    **or** `allow_url_fopen` parameter must be enabled in the PHP configuration
    file or interpreter parameters;
  * for SOCKS5 proxy: `cURL`>=7.10 extension must be installed;
  * for SOCKS4 proxy: PHP>=5.2.10 with `cURL`>=7.10 extension is required;
  * for SOCKS4A proxy: PHP>=5.5.23 or PHP>=5.6.7 (7.X included)
    with `cURL`>=7.18 extension is required.

If script returns page with non-200 HTTP status then some problem was occurred:
detailed problem information is described in the HTTP status phrase,
in the script output and in the server/interpreter logfile.

## Parameters
Main `index.php` script accepts the below GET-parameters.

`id` and `access_token` **OR** `global_search` and `access_token` parameters 
are required, another parameters are optional. 
`id` and `global_search` parameters **cannot** be used together.

* [conditionally required] `id` is short name, ID number (community ID is started with `-` sign)
  or full identifier (like idXXXX, clubXXXX, publicXXXX, eventXXXX) of profile or community.
  Only its single wall is processed.
  Examples of a valid values:
  * `123456`, `id123456` — both of these values identify the user profile with ID 123456,
  * `-123456`, `club123456` — both of these values identify the group with ID 123456,
  * `-123456`, `public123456` — both of these values identify the public page with ID 123456,
  * `-123456`, `event123456` — both of these values identify the event with ID 123456,
  * `apiclub` — value identifies the user profile or community with this short name.

  Deprecated `domain` and `owner_id` parameters are allowed and they're identical to `id`.
* <a name="eng-global-search"></a> [conditionally required] 
  `global_search` is an arbitrary text search query to lookup on all **opened** walls.
  It uses internal VK algorithms to search posts that're published by wall's **owner**.
  Search results are the same as on [this search page](https://vk.com/search?c[section]=statuses).
  
* [required] `access_token` is
  * either service token that's specified in the app settings
    (you can create your own standalone application
    [here](https://vk.com/editapp?act=create), app can be off)

    Service token allows to fetch only opened for everyone walls.
  * or [user access token with offline permissions](#eng-user-access-token)

    If you uses `id` parameter then user access token allows 
    to fetch both opened and closed walls that're opened for this user.

    Warning: If user terminates all sessions in the security settings of profile
    then him access token becomes invalid; in that case, user must create new access token.
    
   If you uses `global_search` then service and user access tokens give equivalent results,
   i.e. only opened walls is processed.
* <a name="eng-count"></a> `count` is a number of processing posts 
  starting with the latest published post.
  It's arbitrary amount including more than 100.

  *Default value*: 20.

  If `owner_only`, `non_owner_only`, `include`, `exclude` or `skip_ads`
  parameters are passed then amount of posts in the result RSS feed can be
  less than `count` because some post can be skipped by these parameters.
  
  If `global_search` is passed then maximum value of `count` is **1000**,
  API requests number can be no more than **1000 requests per day**, 
  and each request can fetch no more than 200 posts.
  
  Delay between requests is equal to 1 sec in order to satisfy VK API limits
  (no more than 3 requests per second).
  
  If `id` is passed then `count` is unlimited, but API requests number can be no more than 
  **5000 requests per day** and each request can fetch no more than 100 posts.
  
* <a name="eng-repost-delimiter"></a> `repost_delimiter` is a string that's placed
  between parent and child posts; in other words, it's a header of a child post
  in the repost.

  *Default value* is `<hr><hr>` if HTML formatting is enabled (default behaviour),
  otherwise `______________________` (`disable_html` parameter).

  This parameter can contain the next special strings that will be substituted in the RSS feed:
  * `{author}` that's replaced with first and last names of child post' author
    in the nominative case if author is a user,
    otherwise it's replaced with community name in the nominative that's published child post.
  * `{author_ins}` that's replaced with first and last names
    of child post' author in the instrumental case if author is a user,
    otherwise it's replaced with community name in the nominative that's published child post
  * `{author_gen}` that's replaced with first and last names
    of child post' author in the genitive case if author is a user,
    otherwise it's replaced with community name in the nominative that's published child post

  Author is child post' signer if it exists, otherwise it's child post' publisher.

  E.g., parameter value `<hr>Written by {author}` is replaced with:
  * `<hr>Written by John Smith` if author is user and publisher of child post,
  * `<hr>Written by Fun Club` if author is community,
  * `<hr>Written by John Smith in Fun Club` if author is user and signer of child post.

  Additionally substitutions adds links to user/community pages
  that're represented as either HTML hyperlinks on author name or plain text in the brackets
  (if `disable_html` is enabled).
* <a name="eng-regex"></a> `include` is case insensitive regular expression (PCRE notation)
  that must match the post text. Another posts will be skipped.
  Symbol `/` **is not** required at the start and at the end of regular expression.
* `exclude` is case insensitive regular expression (PCRE notation)
  that must **not** match the post text. Another posts will be skipped.
  Symbol `/` **is not** required at the start and at the end of regular expression.
* <a name="eng-html"></a> `disable_html` passing (including absent value) indicates
  that RSS item descriptions must be without HTML formatting.

  *By default* HTML formatting is applied for links and images.
* <a name="eng-owning"></a> `owner_only` passing (including absent value) indicates that RSS must
  contain only posts that's
  * published by community in the case of community wall;

    If `allow_signed` parameter with `false` value is also passed
    then posts with signature (that's published by community) will be skipped.
  * published by profile owner in the case of user wall.

  *By default* [absent parameter] RSS feed contains all posts that passes another filters.
* `non_owner_only` or `not_owner_only` passing (including absent value)
  indicates that RSS must contain only posts that's
  * not published by community in the case of community wall, i.e. published by users.

    If `allow_signed` parameter with `true` or absent value is also passed
    then posts with signature (that's published by community)
    will be included to the RSS feed.
  * not published by profile owner in the case of user wall, i.e. published by another users.

  *By default* [absent parameter] RSS feed contains all posts that passes another filters.
* <a name="eng-sign"></a> `allow_signed` allows or disallows posts (that's published by community)
  with signature when `owner_only` or `non_owner_only`/`not_owner_only`
  parameter is passed.

  *By default* [absent parameter] RSS feed contains all posts that passes another filters.

  Allowed values: [absent value] (same as `true`), `true`, `false`,
  `0` (same as `false`), `1` (same as `true`). Another values are interpreted as `true`.
  * If `owner_only` is passed then `allow_signed` with `false` value doesn't include
    posts with signature to the RSS feed.
  * If `non_owner_only` or `not_owner_only` is passed
    then `allow_signed` with `true` value includes posts
    with signature to the RSS feed.
* <a name="eng-ads"></a> `skip_ads` passing indicates that all marked as ad posts will be skipped.

  *By default* [absent parameter] RSS feed contains all posts that passes another filters.

  **Note**: Some wall posts that're marked as ad on the website,
  VK API doesn't mark as ad, therefore some ad posts can be in the RSS feed.
* <a name="eng-proxy"></a> `proxy` is proxy server address. Allowed value formats:
  * `address`,
  * `address:port`,
  * `type://address`,
  * `type://address:port`,
  * `login:password@address`,
  * `login:password@address:port`,
  * `type://login:password@address`,
  * `type://login:password@address:port`,

  where `address` is proxy domain or IP-address, `port` is proxy port,
  `type` is proxy type (HTTPS, SOCKS4, SOCKS4A, SOCKS5),
  `login` and `password` are login and password for proxy access if it's necessary.

  Proxy type, login and password can be passed through another parameters:
  `proxy_type`, `proxy_login` and `proxy_password` respectively.

## <a name="eng-user-access-token"></a> How To Get Permanent User Access Token
[This authorization flow](https://vk.com/dev/authcode_flow_user) is
preferred getting user access token for the server side access to the walls.

1. Create your own standalone application [here](https://vk.com/editapp?act=create).
   Created app can be off because it does not matter for the API requests.
2. Authorize necessary account on vk.com and go to the next URL

   `https://oauth.vk.com/authorize?client_id=APP_ID&display=page&redirect_uri&scope=offline&response_type=code&v=5.54`

   where replace `APP_ID` with application ID that's specified in the app settings.

3. Confirm permissions. Remember the value of GET-parameter `code`
   of the result URL in the browser address bar.

4. Go to the URL

   `https://oauth.vk.com/access_token?client_id=APP_ID&client_secret=APP_SECRET&redirect_uri&code=AUTH_CODE`

   where replace `APP_ID` with application ID, replace `APP_SECRET`
   with secure key that's specified in the app settings,
   replace `AUTH_CODE` with `code` value from the previous step.

   The result JSON-response contains sought-for access token.

Bonus: created app keeps API calls statistics so you can see it.

**Warning**: If user terminates all sessions in him security settings
then him access token becomes invalid; in that case, user must create
new access token repeating steps 2-4.


## Usage Examples
```php
index.php?id=apiclub&access_token=XXXXXXXXX
index.php?id=-1&access_token=XXXXXXXXX
index.php?id=id1&access_token=XXXXXXXXX
index.php?id=club1&access_token=XXXXXXXXX
index.php?id=club1&disable_html&access_token=XXXXXXXXX   # no HTML formatting in RSS item descriptions
index.php?id=apiclub&count=100&include=newsfeed&access_token=XXXXXXXXX   # feed contains only posts with substring 'newsfeed'
index.php?id=apiclub&count=100&exclude=newsfeed&access_token=XXXXXXXXX   # feed contains only posts without substring 'newsfeed'
index.php?id=apiclub&proxy=localhost:8080&access_token=XXXXXXXXX
index.php?id=apiclub&proxy=localhost:8080&proxy_type=https&access_token=XXXXXXXXX
index.php?id=apiclub&proxy=https%3A%2F%2Flocalhost:8080&access_token=XXXXXXXXX
index.php?id=club1&owner_only&access_token=XXXXXXXXX   # feed contains only posts by community
index.php?id=club1&owner_only&allow_signed=false&access_token=XXXXXXXXX   # feed contains only posts by community
                                                                          # that's without signature
index.php?id=club1&non_owner_only&access_token=XXXXXXXXX   # feed contains only posts by users
index.php?id=club1&non_owner_only&allow_signed&access_token=XXXXXXXXX   # feed contains only posts by users
                                                                        # and community posts with signature
index.php?id=-1&count=100&include=(new|wall|\d+)&access_token=XXXXXXXXX
index.php?global_search=query&count=300&access_token=XXXXXXXXX # search posts that contains 'query'
index.php?id=-1&count=30&repost_delimiter=<hr><hr>Written by {author}:&access_token=XXXXXXXXX
```
**Note**: one parameter contains special characters in the last example,
so URL-encoding can be required for the direct call:
```index.php?id=-1&count=100&include=(new%7Cwall%7C%5Cd%2B)&access_token=XXXXXXXXX```


## Troubleshooting
* If you get error:
  > date(): It is not safe to rely on the system's timezone settings.
    You are *required* to use the date.timezone setting or
    the date_default_timezone_set() function. In case you used any
    of those methods and you are still getting this warning,
    you most likely misspelled the timezone identifier.
    We selected the timezone 'UTC' for now, but please set date.timezone
    to select your timezone.

  then set timezone in php configuration (`date.timezone` parameter) or
  add line like `date_default_timezone_set('UTC');` to the start
  of the `index.php` script (before `require_once` statement).


---

# <a name="rus"></a> Генерация RSS-ленты открытой или закрытой стены пользователя или сообщества (группы, публичной страницы или мероприятия) во Вконтакте.


## Возможности:
* Получение RSS-ленты открытой стены: извлечение описания из разных частей
  (включая вложения) и построение заголовков на основе описания.
* Также получение RSS-ленты закрытой стены при наличии токена с правами оффлайн-доступа,
  привязанного к профилю, которому открыт доступ к такой стене.
  [Ниже описан один из способов получения токена](#rus-user-access-token).
* Получение RSS-ленты, содержащей записи с различных открытых стен, 
  которые соответствуют [глобальному поисковому запросу](#rus-global-search).
* Получение [произвольного количества](#rus-count) записей со стены.
* Получение записей, [опубликованных](#rus-owning) от кого угодно, от имени
  сообщества/владельца страницы или ото всех, кроме сообщества/владельца страницы.
* Фильтрация записей по наличию или отсутствию [подписи](#rus-sign).
* Фильтрация записей по соответствию и/или несоответствию
  [регулярному выражению](#rus-regex) в стиле PCRE.
* При желании исключение записей в сообществе, помеченных как [реклама](#rus-ads)
  [по умолчанию отключено].
* Извлечение хеш-тегов в качестве RSS-категорий.
* При желании [HTML-форматирование](#rus-html) всех видов ссылок, изображений,
  переносов строк [по умолчанию включено].
* Допустимо использование HTTPS, SOCKS4, SOCKS4A или SOCKS5
  [прокси-сервера](#rus-proxy) для запросов.
* У каждой записи в ленте указан автор (либо тот, кто подписан или опубликовал запись,
  либо тот, кто подписан или опубликовал исходную запись, если конечная запись является репостом исходной).
* Возможность задать свой [собственный разделитель](#rus-repost-delimiter) с подстановками
  между родительским и дочерним записями (репосты).


## Требования
* PHP>=5.2.2 (в т.ч. 5.3.X, 5.4.X, 5.5.X, 5.6.X, 7.X) с установленными
  по умолчанию поставляемыми расширениями `mbstring`, `json`, `pcre`, `openssl`.
* Скрипт предпочитает использовать встроенные в PHP возможности по отправке запросов.
  Если у PHP отключена встроенная возможность загрузки файлов по URL
  (отключен параметр `allow_url_fopen` в конфигурации или параметрах интерпретатора),
  но при этом у PHP установлено расширение `cURL`,
  то именно оно будет использоваться для загрузки данных.
* Если необходимо использовать прокси-сервер, то в случае
   * HTTPS-прокси — либо необходимо расширение `cURL`>=7.10, **либо**
     в конфигурационном файле или параметрах интерпретатора PHP
     должен быть включён параметр `allow_url_fopen`,
   * SOCKS5-прокси — необходимо расширение `cURL`>=7.10,
   * SOCKS4-прокси — необходим PHP>=5.2.10 с расширением `cURL`>=7.10,
   * SOCKS4A-прокси — необходим PHP>=5.5.23 или PHP>=5.6.7 (включая 7.X) с расширением `cURL`>=7.18.

В случае каких-либо проблем вместо RSS-ленты выдается страница с HTTP-статусом,
отличным от 200, и с описанием проблемы в HTTP-заголовке и теле страницы,
а также создаётся запись в журнале ошибок сервера или интерпретатора.


## Параметры
Основной скрипт `index.php` принимает следующие GET-параметры.

Пара параметров `id` и `access_token` **ИЛИ** `global_search` и `access_token` 
обязательна, остальные параметры необязательны. Нельзя одновременно использовать 
параметры `id` и `global_search`.

* [условно обязательный] `id` — короткое название, ID-номер (в случае сообщества ID начинается со знака `-`)
  или полный идентификатор человека/сообщества (в виде idXXXX, clubXXXX, publicXXXX, eventXXXX), 
  для которого будет строиться RSS-лента.
  Примеры допустимых значений параметра `id`:
  * `123456`, `id123456` — оба значения указывают на одну и ту же страницу пользователя с ID 123456,
  * `-123456`, `club123456` — оба значения указывают на одну и ту же группу с ID 123456,
  * `-123456`, `public123456` — оба значения указывают на одну и ту же публичную страницу с ID 123456,
  * `-123456`, `event123456` — оба значения указывают на одну и ту же страницу мероприятия с ID 123456,
  * `apiclub` — значение указывает на пользователя или сообщество с данным коротким названием.

  Ради обратной совместимости допускается вместо `id` использовать `domain` или `owner_id`.

* <a name="rus-global-search"></a> [условно обязательный] `global_search` —
  произвольный текстовый глобальный поисковый запрос, по которому 
  с помощью внутренних алгоритмов ВКонтакте ищутся
  записи со всех открытых стен, опубликованные владельцем профиля пользователя 
  или от имени сообщества. Результаты поиска аналогичны результатам 
  [на этой поисковой странице](https://vk.com/search?c%5Bsection%5D=statuses).

* [обязательный] `access_token` —
   * Либо сервисный ключ доступа, который указан в настройках приложения
     (создать собственное standalone-приложение можно
     [по этой ссылке](https://vk.com/editapp?act=create), само приложение может быть выключено).

     Сервисный ключ доступа дает возможность получать записи только с открытых для всех стен.
   * Либо [ключ доступа пользователя с правами оффлайн-доступа](#rus-user-access-token).

     При использовании параметра `id` ключ доступа пользователя позволяет 
     получать записи как с открытых, так и закрытых стен 
     (но открытых для пользователя, который создал токен).

     Предупреждение: если в настройках безопасности пользователя будут завершены все сессии,
     то ключ доступа пользователя станет невалидным — нужно сформировать ключ заново.
    
   Если используется параметр `global_search`, тогда генерируемые RSS-ленты
   при использовании сервисного ключа доступа и при использовании ключа 
   доступа пользователя одинаковы, 
   т.е. в любом случае все записи будут лишь с открытых стен.

* <a name="rus-count"></a> `count` — количество обрабатываемых записей, 
  начиная с последней опубликованной
  (произвольное количество, включая более 100, *по умолчанию 20*).
  
  Если дополнительно установлены параметры `owner_only`, `non_owner_only`,
  `include`, `exclude` или `skip_ads`, то количество выводимых в RSS-ленте
  записей может быть меньше значения `count` за счет исключения записей,
  которые отсеиваются этими параметрами.
  
  Если передан параметр `id`, то значение `count` неограниченно, но VK API
  позволяет делать не более **5000 запросов в сутки**, а каждый запрос может 
  получить не более 100 записей.
  
  Если передан параметр `global_search`, то значение `count` не может быть
  больше **1000**, при этом VK API позволяет делать не более **1000 запросов в сутки**,
  каждый из которых может извлечь не более 200 записей.
  
  Между запросами задержка минимум в 1 секунду, чтобы
  не превышать ограничения VK API (не более 3 запросов в секунду).

* <a name="rus-repost-delimiter"></a> `repost_delimiter` — разделитель
  между родительской и дочерней записью (когда профиль/сообщество [«родитель»]
  поделилось записью от другого профиля/сообщества [«ребенок»]),
  иными словами, заголовок дочерней записи.

  *По умолчанию* разделителем служит `<hr><hr>` в случае по умолчанию
  включенного HTML-форматирования и `______________________`
  в случае отключенного HTML-форматирования (параметр `disable_html`):

  В качестве значения параметра может быть передана любая строка.
  Допустимо использование специальных подстановок:
  * `{author}` — в RSS ленте заменяется на автора дочерней записи в именительном падеже.
  * `{author_gen}` — заменяется на автора дочерней записи в родительном падеже в случае,
    если этот автор является пользователем, а если автор — сообщество,
    то заменяется на название сообщества без морфологических изменений.
  * `{author_ins}` — заменяется на автора дочерней записи в творительном падеже в случае,
    если этот автор является пользователем, а если автор — сообщество,
    то заменяется на название сообщества без морфологических изменений.

  Под автором записи понимается в первую очередь подписанный автор,
  а если такового нет, то публикатор записи.

  Примеры значений параметра:
  * `{author} пишет:` — в случае автора-пользователя подставится,
     например, `Иван Иванов пишет:`, а в случае автора-сообщества, например,
     `ВКонтакте API пишет:`
  * `<hr>Опубликовано {author_ins}:` — в случае автора-пользователя подставится,
     например, `Опубликовано Иваном Ивановым:`, а в случае автора-сообщества, например,
     `Опубликовано ВКонтакте API:`
  * `Запись {author_gen}:` — в случае автора-пользователя подставится,
     например, `Запись Ивана Иванова:`, а в случае автора-сообщества, например,
     `Запись ВКонтакте API:`
  * `<hr>Написано {author_ins}:` — в случае автора-пользователя подставится,
     например, `<hr>Написано Иваном Ивановым:`, а в случае автора-сообщества, например,
     `<hr>Написано ВКонтакте API:`. Если же запись опубликована в сообществе, но при этом
     подписана автором, то подстановка станет наподобие такой:
     `<hr>Написано Иваном Ивановым в сообществе ВКонтакте API:`
     (аналогично будет в предыдущих примерах)

   В указанных примерах в результатах подстановки еще подставляются либо HTML-форматированные
   ссылки на пользователя/сообщество, либо эти же же ссылки в виде простого текста
   в случае отключенного HTML-форматирования (параметр `disable_html`).
* <a name="rus-regex"></a> `include` — регистронезависимое регулярное
  выражение в стиле PCRE, которое должно соответствовать тексту записи.
  Остальные записи будут пропущены.
  В начале и в конце выражения символ `/` **не** нужен.
* `exclude` — регистронезависимое регулярное выражение в стиле PCRE,
  которое **не** должно соответствовать тексту записи.
  Остальные записи будут пропущены.
  В начале и в конце выражения символ `/` **не** нужен.
* <a name="rus-html"></a> `disable_html` — если передан (можно без значения),
  то описание каждой записи не будет содержать никаких HTML тегов.

  *По умолчанию* (отсутствие `disable_html`) описание может включать
  HTML-теги для создания гиперссылок и вставки изображений.
* <a name="rus-owning"></a> `owner_only` — если передан (можно без значения),
  то в RSS-ленту выводятся лишь те записи, которые
   * в случае стены сообщества опубликованы от имени сообщества;

     если в этом случае дополнительно передан параметр `allow_signed=false`,
     то не будут выводиться подписанные записи, опубликованные от имени сообщества.
   * в случае стены пользователя опубликованы самим этим пользователем.

   *По умолчанию* (отсутствие параметра) выводятся записи ото всех,
   если они не фильтруются другими параметрами.
* `non_owner_only` или `not_owner_only` — если передан любой из них
  (можно без значения), то в RSS-ленту выводятся лишь те записи, которые
  * в случае стены сообщества опубликованы не от имени сообщества, а пользователями;

    если в этом случае дополнительно передан параметр `allow_signed`
    с отсутствующим значением или со значение`true`, то еще будут
    выводиться подписанные записи, опубликованные от имени сообщества;
  * в случае стены пользователя опубликованы не самим этим пользователем, а другими.

   *По умолчанию* (отсутствие параметра) выводятся записи ото всех,
   если они не фильтруются другими параметрами.
* <a name="rus-sign"></a> `allow_signed` — допускать или нет подписанные записи, опубликованные
  от имени сообщества, если передан параметр `owner_only`
  или `non_owner_only`/`not_owner_only`.

  *По умолчанию* (отсутствие параметра) допустимы все записи,
  которые проходят фильтрацию другими параметрами.

  Допустимые значения (регистр не учитывается): [отсутствие значения]
  (аналог `true`), `true`, `false`, `0` (аналог `false`),
  `1` (аналог `true`), все остальные значения воспринимаются как `true`.
  * В случае переданного параметра `owner_only` позволяет исключать
    подписанные записи путем передачи параметра `allow_signed` со значением `false`.
  * В случае переданного параметра `non_owner_only` или `not_owner_only`
    позволяет дополнительно включать в RSS-ленту подписанные записи
    путем передачи параметра `allow_signed` со значением `true`,
* <a name="rus-ads"></a> `skip_ads` — если передан (можно без значения),
   то не выводить в RSS-ленту записи, помеченные как реклама.

   *По умолчанию* (отсутствие параметра) выводятся все записи,
   если они не фильтруются другими параметрами.

   **Примечание**: API Вконтакте помечает как рекламу не все записи,
   которые помечены на стене на сайте, поэтому некоторые рекламные посты параметр не убирает.
* <a name="rus-proxy"></a> `proxy` — адрес прокси-сервера. Допустимые форматы значения этого параметра:
  * `address`,
  * `address:port`,
  * `type://address`,
  * `type://address:port`,
  * `login:password@address`,
  * `login:password@address:port`,
  * `type://login:password@address`,
  * `type://login:password@address:port`,

  где `address` — домен или IP-адрес прокси, `port` — порт,
  `type` — тип прокси (HTTPS, SOCKS4, SOCKS4A, SOCKS5),
  `login` и `password` — логин и пароль для доступа к прокси, если необходимы.

  Тип прокси и параметры авторизации можно передавать в виде отдельных параметров:
  * `proxy_type` — тип прокси (по умолчанию считается HTTP, если не указано в `proxy` и `proxy_type`),
  * `proxy_login` — логин для доступа к прокси-серверу,
  * `proxy_password` — пароль для доступа к прокси-серверу.


## <a name="rus-user-access-token"></a> Как получить бессрочный токен для доступа к стенам, которые доступны своему профилю
Для серверного доступа предпочтительна [такая схема](https://vk.com/dev/authcode_flow_user):

1. Создать собственное standalone-приложение [по этой ссылке](https://vk.com/editapp?act=create).
   По желанию в настройках приложения можно изменить состояние
   на «Приложение отключено» — это никак не помешает генерации RSS-ленты.

2. После авторизации под нужным профилем пройти по ссылке:

   `https://oauth.vk.com/authorize?client_id=APP_ID&display=page&redirect_uri&scope=offline&response_type=code&v=5.54`

   где вместо `APP_ID` подставить ID созданного приложения — его можно увидеть,
   например, в настройках приложения.

3. Подтвердить права. В результате в адресной строке будет GET-параметр `code`.

4. Пройти по ссылке:

   `https://oauth.vk.com/access_token?client_id=APP_ID&client_secret=APP_SECRET&redirect_uri&code=AUTH_CODE`

   где `APP_ID` — ID созданного приложения,
   `APP_SECRET` — защищенный ключ приложения (можно увидеть в настройках приложения),
   `AUTH_CODE` — значение параметра `code` из предыдущего шага.

   В результате будет выдан JSON-отклик с искомым `access_token` —
   именно это значение и следует использовать
   в качестве GET-параметра скрипта, генерирующего RSS-ленту.

5. При первом использовании токена с IP адреса, отличного от того,
   с которого получался токен, может выскочить ошибка "API Error 17: Validation required",
   требующая валидации: для этого необходимо пройти по первой ссылке из описания ошибки
   и ввести недостающие цифры номера телефона профиля.

В качестве бонуса в статистике созданного приложения можно смотреть частоту запросов к API.

**Внимание!** Если в настройках безопасности профиля будут завершены сессии приложения,
то токен станет невалидным — нужно сформировать новый токен, повторив пункты 2-4.


## Примеры использования:
```php
index.php?id=apiclub&access_token=XXXXXXXXX
index.php?id=-1&access_token=XXXXXXXXX
index.php?id=id1&access_token=XXXXXXXXX
index.php?id=club1&access_token=XXXXXXXXX
index.php?id=club1&disable_html&access_token=XXXXXXXXX   # в данных RSS-ленты отсутстуют HTML-сущности
index.php?id=apiclub&count=100&include=рекомендуем&access_token=XXXXXXXXX   # выводятся только записи со словом 'рекомендуем'
index.php?id=apiclub&count=100&exclude=рекомендуем&access_token=XXXXXXXXX   # выводятся только записи без слова 'рекомендуем'
index.php?id=apiclub&proxy=localhost:8080&access_token=XXXXXXXXX
index.php?id=apiclub&proxy=localhost:8080&proxy_type=https&access_token=XXXXXXXXX
index.php?id=apiclub&proxy=https%3A%2F%2Flocalhost:8080&access_token=XXXXXXXXX
index.php?id=club1&owner_only&access_token=XXXXXXXXX   # выводятся только записи от имени сообщества
index.php?id=club1&owner_only&allow_signed=false&access_token=XXXXXXXXX   # выводятся только записи от имени сообщества,
                                                                          # у которых нет подписи
index.php?id=club1&non_owner_only&access_token=XXXXXXXXX   # выводятся только записи от пользователей (не от имени сообщества)
index.php?id=club1&non_owner_only&allow_signed&access_token=XXXXXXXXX   # выводятся только записи от имени сообщества,
                                                                        # у которых есть подпись, и записи от пользователей
index.php?id=-1&count=100&include=(рекомендуем|приглашаем|\d+)&access_token=XXXXXXXXX
index.php?global_search=запрос&count=300&access_token=XXXXXXXXX # поиск записей, содержащих слово "запрос"
index.php?id=-1&count=30&repost_delimiter=<hr><hr>{author} пишет:&access_token=XXXXXXXXX
```
**Примечание**: в последнем примере при таком вызове напрямую через
GET-параметры может потребоваться URL-кодирование символов:
```index.php?id=-1&count=100&include=(%D1%80%D0%B5%D0%BA%D0%BE%D0%BC%D0%B5%D0%BD%D0%B4%D1%83%D0%B5%D0%BC%7C%D0%BF%D1%80%D0%B8%D0%B3%D0%BB%D0%B0%D1%88%D0%B0%D0%B5%D0%BC%7C%5Cd%2B)&access_token=XXXXXXXXX```

## Возможные проблемы и их решения
* Если при запуске скрипта интерпретатор выдает ошибку:
  > date(): It is not safe to rely on the system's timezone settings.
    You are *required* to use the date.timezone setting or
    the date_default_timezone_set() function. In case you used any
    of those methods and you are still getting this warning,
    you most likely misspelled the timezone identifier.
    We selected the timezone 'UTC' for now, but please set date.timezone
    to select your timezone.

  тогда необходимо либо добавить информацию о часовом поясе
  в конфигурационный файл PHP (параметр `date.timezone`),
  либо добавить в начале скрипта `index.php` (перед `require_once`) строку,
  подобную `date_default_timezone_set('UTC');`,
  устанавливающую часовую зону `UTC` для скрипта.
