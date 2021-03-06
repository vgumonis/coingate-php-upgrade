<?php

namespace CoinGate\HttpClient;

use CoinGate\Exception\ApiConnectionException;
use CoinGate\Exception\UnexpectedValueException;

class CurlClient implements ClientInterface
{
    const DEFAULT_TIMEOUT = 60;

    const DEFAULT_CONNECT_TIMEOUT = 20;

    /**
     * @var int
     */
    private $timeout = self::DEFAULT_TIMEOUT;

    /**
     * @var int
     */
    private $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT;

    /**
     * singleton object
     *
     * @var self
     */
    protected static $instance;

    /**
     * @return self
     */
    public static function instance(): self
    {
        if (! static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * @param int $seconds
     * @return $this
     */
    public function setTimeout(int $seconds): self
    {
        $this->timeout = max($seconds, 0);

        return $this;
    }

    /**
     * @param int $seconds
     * @return $this
     */
    public function setConnectTimeout(int $seconds): self
    {
        $this->connectTimeout = max($seconds, 0);

        return $this;
    }

    /**
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * @return int
     */
    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    /**
     * @param string $method
     * @param string $absUrl
     * @param array $headers
     * @param array $params
     * @return array
     */
    private function getRequestOptions(string $method, string $absUrl, array $headers = [], array $params = []): array
    {
        $method = strtolower($method);
        $options = [];

        if ($method === 'get') {
            $options[CURLOPT_HTTPGET] = 1;

            if (! empty($params)) {
                $absUrl = $absUrl . '?' . http_build_query($params);
            }

        } elseif (in_array($method, ['patch', 'post'])) {

            $options[CURLOPT_POST] = 1;
            $options[CURLOPT_POSTFIELDS] = http_build_query($params);

            if ($method === 'patch') {
                $options[CURLOPT_CUSTOMREQUEST] = 'PATCH';
            }

        } elseif ($method === 'delete') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';

            if (! empty($params)) {
                $absUrl = $absUrl . '?' . http_build_query($params);
            }

        } else {
            throw new UnexpectedValueException("Unrecognized method $method");
        }

        $options[CURLOPT_URL] = $absUrl;
        $options[CURLOPT_RETURNTRANSFER] = true;
        $options[CURLOPT_CONNECTTIMEOUT] = $this->connectTimeout;
        $options[CURLOPT_TIMEOUT] = $this->timeout;
        $options[CURLOPT_HTTPHEADER] = $headers;

        $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        $options[CURLOPT_SSL_VERIFYPEER] = true;

        return [$options, $absUrl];
    }

    /**
     * @param string $method
     * @param string $absUrl
     * @param array $headers
     * @param array $params
     * @return array
     *
     * @throws ApiConnectionException
     */
    public function request(string $method, string $absUrl, array $headers = [], array $params = []): array
    {
        [$options, $absUrl] = $this->getRequestOptions($method, $absUrl, $headers, $params);

        // ---------------------------------------------------------
        // ---------------------------------------------------------
        // ---------------------------------------------------------

        // create a new cURL resource
        $ch = curl_init();
        // set URL and other appropriate options
        curl_setopt_array($ch, $options);

        $responseBody = curl_exec($ch);

        // if empty response body received, check for errors...
        if ($responseBody === false) {
            $errno = curl_errno($ch);
            $message = curl_error($ch);

            $this->handleCurlError($absUrl, $errno, $message);
        }

        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // close cURL resource, and free up system resources
        curl_close($ch);

        return [$responseBody, $responseCode];
    }

    /**
     * @param $url
     * @param $errno
     * @param $message
     *
     * @throws ApiConnectionException
     */
    private function handleCurlError($url, $errno, $message)
    {
        switch ($errno) {
            case CURLE_COULDNT_CONNECT:
            case CURLE_COULDNT_RESOLVE_HOST:
            case CURLE_OPERATION_TIMEOUTED:
                $response = "Could not connect to CoinGate ($url). Please check your internet connection and try again. ";

                break;

            case CURLE_SSL_CACERT:
            case CURLE_SSL_PEER_CERTIFICATE:
                $response = "Could not verify CoinGate's SSL certificate. Please make sure that your network is not intercepting certificates. "
                    . "(Try going to $url in your browser.) ";

                break;

            default:
                $response = "Unexpected error communicating with CoinGate. ";
        }

        $response .= 'If this problem persists, let us know at https://support.coingate.com.';

        $response .= "\n\n(Network error [errno $errno]: $message)";

        throw new ApiConnectionException($response);
    }
}