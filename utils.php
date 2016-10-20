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


class ConnectionWrapper
{
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
    protected static $downloader;

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
    protected static $httpsAllowed;

    /**
     * @var bool   Whether the connection to be closed
     */
    protected $connectionIsClosed;

    /**
     * @var string|null   URL of the last sent request
     */
    protected $lastUrl = null;

    /**
     * @var bool   Whether the connection wrapper to be initialized for the first time
     */
    protected static $isFirstInitialized = true;

    /**
     * ConnectionWrapper constructor.
     *
     * @throws Exception   If PHP configuration does not allow to use file_get_contents or cURL to download remote data
     */
    public function __construct()
    {
        if (self::$isFirstInitialized) {
            if (ini_get("allow_url_fopen") == 1) {
                self::$downloader = self::BUILTIN_DOWNLOADER;
            } elseif (extension_loaded("curl")) {
                self::$downloader = self::CURL_DOWNLOADER;
            } else {
                throw new Exception("PHP configuration does not allow to use either file_get_contents or cURL to download remote data", 500);
            }
            self::$httpsAllowed = extension_loaded('openssl');
            self::$isFirstInitialized = false;
        }
        switch (self::$downloader) {
            case self::BUILTIN_DOWNLOADER:
                $this->context = stream_context_create();
                break;
            case self::CURL_DOWNLOADER:
                $this->curlOpts = array(CURLOPT_RETURNTRANSFER => true);
                break;
        }
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
        switch (self::$downloader) {
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
            switch (self::$downloader) {
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
        if (empty($https_url) && $http_to_https) {
            $https_url = preg_replace('/^http:/ui', 'https:', $url);
        }
        if (self::$httpsAllowed && $http_to_https) {
            $request_url = $https_url;
        } else {
            if (mb_substr($url, 0, 5) === "https") {
                throw new Exception("Cannot send request through HTTPS protocol. Only HTTP protocol is allowed");
            }
            $request_url = $url;
        }

        $this->lastUrl = $request_url;
        switch (self::$downloader) {
            case self::BUILTIN_DOWNLOADER:
                $response = file_get_contents($request_url, false, $this->context);
                break;
            case self::CURL_DOWNLOADER:
                curl_setopt($this->curlHandler, CURLOPT_URL, $request_url);
                $response = curl_exec($this->curlHandler);
                break;
            default:
                $response = false;
        }
        return $response;
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
     * @param string $message
     * @param int $api_error_code
     * @param string $request_url
     * @param Exception $previous
     */
    public function __construct($message, $api_error_code, $request_url, $previous = null)
    {
        parent::__construct($message, 400, $previous);
        $this->apiErrorCode = $api_error_code;
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
