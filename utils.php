<?php
/**
 * @author woxcab
 */


/**
 * Reverse UTF8-encoded string
 *
 * @param string $str   source string
 * @return string   reversed string
 */
function utf8_strrev($str)
{
    preg_match_all('/./us', $str, $ar);
    return join('', array_reverse($ar[0]));
}


/**
 * Get logical value of array element
 *
 * @param array $array   array
 * @param string $key   checked key
 * @return bool   False IF key does not present in the array
 *                OR value $array[$key] is 0 or false like (0, '0', false, 'false', 'FALSE', etc.),
 *                OTHERWISE True (including empty string '' value)
 */
function logical_value($array, $key) {
    return isset($array[$key])
        && (!empty($array[$key]) || $array[$key] === '')
        && mb_strtolower($array[$key]) !== 'false';
}


/**
 * Build URL from components (oppositely to the parse_url function).
 *
 * @param array $parsed_url   URL components (see docs for parse_url function).
 * @return string  Compound URL
 */
function build_url($parsed_url) {
    $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
    $host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
    $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
    $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
    $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
    $pass     = ($user || $pass) ? "$pass@" : '';
    $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
    $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
    $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
    return "$scheme$user$pass$host$port$path$query$fragment";
}

class ProxyDescriptor
{
    protected static $supportedTypes;

    protected $address;
    protected $type;
    protected $login;
    protected $password;

    public function __construct($address, $type = null, $login = null, $password = null)
    {
        $type = mb_strtolower($type);
        preg_match('/^(?:(?P<type>[^:]+?):\/\/)?(?:(?<login>[^\/:]+):(?<password>[^\/@]+)@)?(?P<address>[^\/@]+?)\/?$/',
                   $address, $match);
        if (!empty($match['type'])) {
            $match['type'] = mb_strtolower($match['type']);
            if (!empty($type) && $match['type'] !== $type) {
                throw new Exception("Proxy type is passed multiple times (as part of address and as separate type) and these types are different");
            }
            $type = $match['type'];
        }
        if (empty($type)) {
            $type = 'http';
        }
        if (!isset(self::$supportedTypes[$type])) {
            throw new Exception("Proxy type '${type}' does not allowed or incorrect. Allowed types: "
                . implode(', ', array_keys(self::$supportedTypes)));
        }
        $this->type = $type;

        if (!empty($match['login'])) {
            if (!empty($login) && $match['login'] !== $login) {
                throw new Exception("Login for proxy is passed multiple times (as part of address and as separate login) and these logins are different");
            }
            $login = $match['login'];
        }

        if (!empty($match['password'])) {
            if (!empty($password) && $match['password'] !== $password) {
                throw new Exception("Password for proxy is passed multiple times (as part of address and as separate password) and these passwords are different");
            }
            $password = $match['password'];
        }

        if (empty($login) && !empty($password) || !empty($login) && empty($password)) {
            throw new Exception("Both login and password must be given or not simultaneously.");
        }
        if (!empty($login) && mb_strpos($login, ':') !== false) {
            throw new Exception("Login must not contain colon ':'.");
        }
        $this->login = $login;
        $this->password = $password;

        if (empty($match['address'])) {
            throw new Exception("Invalid proxy address: '${$address}'");
        }
        $this->address = $match['address'];
    }

    /**
     * @return array   Matches allowed proxy type as string to cURL opt proxy type if cURL extension is loaded
     *                 otherwise matches allowed proxy type to 'true'
     */
    public static function getSupportedTypes()
    {
        return self::$supportedTypes;
    }

    public static function init()
    {
        self::$supportedTypes = array();
        if (extension_loaded('curl')) {
            self::$supportedTypes['http'] = CURLPROXY_HTTP;
            if (extension_loaded('openssl')) {
                self::$supportedTypes['https'] = CURLPROXY_HTTP;
            }
            if (defined('CURLPROXY_SOCKS4')) {
                self::$supportedTypes['socks4'] = CURLPROXY_SOCKS4;
            }
            if (defined('CURLPROXY_SOCKS4A')) {
                self::$supportedTypes['socks4a'] = CURLPROXY_SOCKS4A;
            }
            if (defined('CURLPROXY_SOCKS5')) {
                self::$supportedTypes['socks5'] = CURLPROXY_SOCKS5;
            }
        }
        if (ini_get('allow_url_fopen') == 1) {
            self::$supportedTypes['http'] = true;
            if (extension_loaded('openssl')) {
                self::$supportedTypes['https'] = true;
            }
        }
    }

    /**
     * @return string   Proxy address including port if it's presented
     */
    public function getAddress()
    {
        return $this->address;
    }

    /**
     * @return string   Proxy type
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return string|null   Login for identification on proxy
     */
    public function getLogin()
    {
        return $this->login;
    }

    /**
     * @return string|null   Password for authentication on proxy
     */
    public function getPassword()
    {
        return $this->password;
    }
}
ProxyDescriptor::init();


class ConnectionWrapper
{
    /**
     * The number of seconds to wait while trying to connect
     */
    const CONNECT_TIMEOUT = 10;
    /**
     * States to use file_get_contents function to download data
     */
    const BUILTIN_DOWNLOADER = 1;
    /**
     * States to use cURL library to download data
     */
    const CURL_DOWNLOADER = 2;
    /**
     * @var int    way to download data
     */
    protected $downloader;

    /**
     * @var resource   Stream context resource
     */
    protected $context;
    /**
     * @var array   options for a cURL transfer
     */
    protected $curlOpts;
    /**
     * @var resource   cURL resource
     */
    protected $curlHandler;

    /**
     * @var bool   Whether the HTTPS protocol to be enabled for requests
     */
    protected $httpsAllowed;

    /**
     * @var string|null   Type of proxy in lowercase: http or https
     */
    protected $proxyType = null;

    /**
     * @var bool   Whether the connection to be closed
     */
    protected $connectionIsClosed;

    /**
     * @var string|null   URL of the last sent request
     */
    protected $lastUrl = null;

    protected $nRequests = 0;

    /**
     * ConnectionWrapper constructor.
     *
     * @param ProxyDescriptor|null $proxy   Proxy descriptor
     * @throws Exception   If PHP configuration does not allow to use file_get_contents or cURL to download remote data
     */
    public function __construct($proxy = null)
    {
        $supported_proxy_types = isset($proxy) ? ProxyDescriptor::getSupportedTypes() : null;

        if (ini_get('allow_url_fopen') == 1
            && (!isset($supported_proxy_types) || $supported_proxy_types[$proxy->getType()] === true)) {
            $this->downloader = self::BUILTIN_DOWNLOADER;
        } elseif (extension_loaded('curl')) {
            $this->downloader = self::CURL_DOWNLOADER;
        } else {
            throw new Exception('PHP configuration does not allow to use either file_get_contents ' .
                'or cURL to download remote data, or chosen proxy type requires non-installed cURL extension', 500);
        }

        switch ($this->downloader) {
            case self::BUILTIN_DOWNLOADER:
                $opts = array();
                $opts['http']['timeout'] = self::CONNECT_TIMEOUT;
                if (isset($proxy)) {
                    $this->proxyType = $proxy->getType();
                    $address = $proxy->getAddress();
                    $opts['http']['proxy'] = "tcp://${address}";
                    $opts['http']['request_fulluri'] = true;
                    $login = $proxy->getLogin();
                    if (isset($login)) {
                        $password = $proxy->getPassword();
                        $login_pass = base64_encode("${login}:${password}");
                        $opts['http']['header'] = "Proxy-Authorization: Basic ${login_pass}\r\nAuthorization: Basic ${login_pass}";
                    }
                }
                $this->context = stream_context_create($opts);
                break;
            case self::CURL_DOWNLOADER:
                $this->curlOpts = array(CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_HEADER => true,
                                        CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT);
                if (isset($proxy)) {
                    $this->curlOpts[CURLOPT_PROXY] = $proxy->getAddress();
                    $this->proxyType = $proxy->getType();
                    $this->curlOpts[CURLOPT_PROXYTYPE] = $supported_proxy_types[$this->proxyType];
                    $login = $proxy->getLogin();
                    if (isset($login)) {
                        $password = $proxy->getPassword();
                        $this->curlOpts[CURLOPT_USERPWD] = "${login}:${password}";
                        $this->curlOpts[CURLOPT_PROXYUSERPWD] = "${login}:${password}";
                    }
                }
                break;
        }

        $this->httpsAllowed = (isset($this->proxyType) && $this->proxyType === 'http') ? false : extension_loaded('openssl');
        $this->connectionIsClosed = true;
    }

    public function __destruct()
    {
        $this->closeConnection();
    }

    /**
     * Change connector state to be ready to retrieve content
     */
    public function openConnection()
    {
        switch ($this->downloader) {
            case self::BUILTIN_DOWNLOADER:
                break;
            case self::CURL_DOWNLOADER:
                $this->curlHandler = curl_init();
                curl_setopt_array($this->curlHandler, $this->curlOpts);
                break;
        }
        $this->connectionIsClosed = false;
    }

    /**
     * Close opened session and free resources
     */
    public function closeConnection()
    {
        if (!$this->connectionIsClosed) {
            switch ($this->downloader) {
                case self::BUILTIN_DOWNLOADER:
                    break;
                case self::CURL_DOWNLOADER:
                    curl_close($this->curlHandler);
                    break;
            }
            $this->connectionIsClosed = true;
        }
    }

    /**
     * Retrieve content from given URL.
     *
     * @param string|null $url    URL
     * @param string|null $https_url   URL with HTTPS protocol. If it's null then it's equal to $url where HTTP is replaced with HTTPS
     * @param bool $http_to_https   Whether to use HTTP URL with replacing its HTTP protocol on HTTPS protocol
     * @return mixed   Response body or FALSE on failure
     * @throws Exception   If HTTPS url is passed and PHP or its extension does not support OpenSSL
     */
    public function getContent($url, $https_url = null, $http_to_https = false)
    {
        if ($this->httpsAllowed && (!empty($https_url) || $http_to_https)) {
            $request_url = empty($https_url) ? preg_replace('/^http:/ui', 'https:', $url) : $https_url;
        } else {
            if (mb_substr($url, 0, 5) === "https") {
                throw new Exception("Cannot send request through HTTPS protocol. "
                                    . "Only HTTP protocol is allowed by configuration and your arguments", 400);
            }
            $request_url = $url;
        }
        $this->lastUrl = $request_url;
        if ($this->nRequests && $this->nRequests % 2 == 0) {
            usleep(1100000);
        }
        switch ($this->downloader) {
            case self::BUILTIN_DOWNLOADER:
                $this->nRequests += 1;
                $body = @file_get_contents($request_url, false, $this->context);
                $response_code = isset($http_response_header) ? (int)substr($http_response_header[0], 9, 3) : null;
                if (empty($body)) {
                    $error_msg = error_get_last();
                    throw new Exception("Cannot retrieve data from URL '${request_url}'"
                                        . (isset($error_msg) ? ": ${error_msg['message']}" : ''),
                                        (!empty($response_code) && $response_code != 200) ? $response_code : 500);
                }
                if ($response_code != 200) {
                    throw new Exception($body, $response_code);
                }
                break;
            case self::CURL_DOWNLOADER:
                $this->nRequests += 1;
                curl_setopt($this->curlHandler, CURLOPT_URL, $request_url);
                $response = curl_exec($this->curlHandler);
                if (empty($response)) {
                    $response_code = curl_getinfo($this->curlHandler, CURLINFO_HTTP_CODE);
                    throw new Exception("Cannot retrieve data from URL '${request_url}': "
                                        . curl_error($this->curlHandler),
                                        in_array($response_code, array(0, 200)) ? 500 : $response_code);
                }
                $split_response = explode("\r\n\r\n", $response, 3);
                if (isset($split_response[2])) {
                    $header = $split_response[1];
                    $body = $split_response[2];
                } else {
                    $header = $split_response[0];
                    $body = $split_response[1];
                }
                if (empty($body)) {
                    throw new Exception("Cannot retrieve data from URL '${request_url}': empty body",
                                        503);
                }
                list($header, ) = explode("\r\n", $header, 2);
                $response_code = (int)substr($header, 9, 3);
                if ($response_code != 200) {
                    throw new Exception("Cannot retrieve data from URL '${request_url}': " . substr($header, 13)
                                        . ": ["  . curl_errno($this->curlHandler) . "] ". curl_error($this->curlHandler),
                                        $response_code == 0 ? 500 : $response_code);
                }
                break;
            default:
                throw new ErrorException("", 500);
        }
        return $body;
    }

    /**
     * @return string|null   The last retrieved URL
     */
    public function getLastUrl()
    {
        return $this->lastUrl;
    }
}


class APIError extends Exception {
    protected $apiErrorCode;
    protected $requestUrl;

    /**
     * APIError constructor.
     *
     * @param object $error_response
     * @param string $request_url
     */
    public function __construct($error_response, $request_url)
    {
        $message = $error_response->error->error_msg;
        switch ($error_response->error->error_code) {
            case 5:
                if (mb_strpos($message, "invalid session") !== false) {
                    $message = "Access token is expired (probably by app session terminating). It is necessary to create new token. ${message}";
                }
                break;
            case 17:
                $message .= ": {$error_response->error->redirect_uri}";
                break;
        }
        parent::__construct($message, 400);
        $this->apiErrorCode = $error_response->error->error_code;
        $this->requestUrl = $request_url;
    }

    /**
     * @return int   API error code
     */
    public function getApiErrorCode()
    {
        return $this->apiErrorCode;
    }

    /**
     * @return string   Requested API URL
     */
    public function getRequestUrl()
    {
        return $this->requestUrl;
    }
}
