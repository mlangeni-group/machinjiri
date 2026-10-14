<?php

use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

if (!function_exists('http_client')) {
    /**
     * Create a new HttpClient instance.
     *
     * @param string $baseUrl
     * @param Session|null $session
     * @param Cookie|null $cookie
     * @return HttpClient
     */
    function http_client(string $baseUrl = '', ?Session $session = null, ?Cookie $cookie = null): HttpClient
    {
        return new HttpClient($baseUrl, $session, $cookie);
    }
}

if (!function_exists('http_get')) {
    /**
     * Execute a GET request.
     *
     * @param string $url
     * @param array $queryParams
     * @param array $options Additional cURL options (e.g., headers, timeout)
     * @return array Response data (see HttpClient::execute)
     */
    function http_get(string $url, array $queryParams = [], array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->get($url, $queryParams);
    }
}

if (!function_exists('http_post')) {
    /**
     * Execute a POST request.
     *
     * @param string $url
     * @param array $data
     * @param bool $isJson Whether to send as JSON (default true)
     * @param array $options Additional cURL options
     * @return array
     */
    function http_post(string $url, array $data = [], bool $isJson = true, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->post($url, $data, $isJson);
    }
}

if (!function_exists('http_put')) {
    /**
     * Execute a PUT request.
     *
     * @param string $url
     * @param array $data
     * @param bool $isJson
     * @param array $options
     * @return array
     */
    function http_put(string $url, array $data = [], bool $isJson = true, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->put($url, $data, $isJson);
    }
}

if (!function_exists('http_patch')) {
    /**
     * Execute a PATCH request.
     *
     * @param string $url
     * @param array $data
     * @param bool $isJson
     * @param array $options
     * @return array
     */
    function http_patch(string $url, array $data = [], bool $isJson = true, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->patch($url, $data, $isJson);
    }
}

if (!function_exists('http_delete')) {
    /**
     * Execute a DELETE request.
     *
     * @param string $url
     * @param array $data Optional JSON body
     * @param array $options
     * @return array
     */
    function http_delete(string $url, array $data = [], array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->delete($url, $data);
    }
}

if (!function_exists('http_head')) {
    /**
     * Execute a HEAD request.
     *
     * @param string $url
     * @param array $options
     * @return array
     */
    function http_head(string $url, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->head($url);
    }
}

if (!function_exists('http_options')) {
    /**
     * Execute an OPTIONS request.
     *
     * @param string $url
     * @param array $options
     * @return array
     */
    function http_options(string $url, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->options($url);
    }
}

if (!function_exists('http_upload_file')) {
    /**
     * Upload a file via multipart/form-data.
     *
     * @param string $url
     * @param string $fieldName
     * @param string $filePath
     * @param array $additionalData
     * @param array $options
     * @return array
     * @throws MachinjiriException
     */
    function http_upload_file(string $url, string $fieldName, string $filePath, array $additionalData = [], array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->uploadFile($url, $fieldName, $filePath, $additionalData);
    }
}

if (!function_exists('http_download_file')) {
    /**
     * Download a file from a URL and save it locally.
     *
     * @param string $url
     * @param string $savePath
     * @param array $options
     * @return array
     * @throws MachinjiriException
     */
    function http_download_file(string $url, string $savePath, array $options = []): array
    {
        $client = http_client();
        apply_options($client, $options);
        return $client->downloadFile($url, $savePath);
    }
}

if (!function_exists('http_multi_request')) {
    /**
     * Execute multiple requests concurrently.
     *
     * @param array $requests Array where each element has 'url' and optionally 'options' (cURL options for that request)
     * @param array $globalOptions Options to apply to all requests (e.g., timeout)
     * @return array Results keyed by original request keys
     */
    function http_multi_request(array $requests, array $globalOptions = []): array
    {
        $client = http_client();
        apply_options($client, $globalOptions);
        
        // Transform simplified requests into the format expected by multiRequest
        $multiRequests = [];
        foreach ($requests as $key => $req) {
            $multiRequests[$key] = [
                'options' => array_merge(
                    $globalOptions,
                    $req['options'] ?? [],
                    [CURLOPT_URL => $req['url']]
                )
            ];
        }
        return $client->multiRequest($multiRequests);
    }
}

if (!function_exists('apply_options')) {
    /**
     * Apply common configuration options to an HttpClient instance.
     *
     * @param HttpClient $client
     * @param array $options Supported keys:
     *   - headers: array
     *   - timeout: int
     *   - max_redirects: int
     *   - user_agent: string
     *   - referer: string
     *   - basic_auth: [username, password]
     *   - bearer_token: string
     *   - proxy: [proxy, port?, username?, password?]
     *   - ssl: [verify_peer, verify_host, cert_path, key_path]
     *   - retry: [max_retries, retry_delay]
     *   - compress: bool
     *   - cookies: bool|string (true = use memory, string = file path)
     *   - cookie_pairs: array (name => value)
     *   - custom_method: string (GET, POST, etc.)
     *   - capture_headers: bool
     */
    function apply_options(HttpClient $client, array $options): void
    {
        if (isset($options['headers'])) {
            $client->setHeaders($options['headers']);
        }
        if (isset($options['timeout'])) {
            $client->setTimeout($options['timeout']);
        }
        if (isset($options['max_redirects'])) {
            $client->setMaxRedirects($options['max_redirects']);
        }
        if (isset($options['user_agent'])) {
            $client->setUserAgent($options['user_agent']);
        }
        if (isset($options['referer'])) {
            $client->setReferer($options['referer']);
        }
        if (isset($options['basic_auth']) && is_array($options['basic_auth']) && count($options['basic_auth']) >= 2) {
            $client->setBasicAuth($options['basic_auth'][0], $options['basic_auth'][1]);
        }
        if (isset($options['bearer_token'])) {
            $client->setBearerToken($options['bearer_token']);
        }
        if (isset($options['proxy'])) {
            $proxy = $options['proxy'];
            if (is_array($proxy)) {
                $client->setProxy($proxy[0], $proxy[1] ?? null, $proxy[2] ?? null, $proxy[3] ?? null);
            } else {
                $client->setProxy($proxy);
            }
        }
        if (isset($options['ssl']) && is_array($options['ssl'])) {
            $ssl = $options['ssl'];
            $client->setSslOptions(
                $ssl['verify_peer'] ?? true,
                $ssl['verify_host'] ?? 2,
                $ssl['cert_path'] ?? null,
                $ssl['key_path'] ?? null
            );
        }
        if (isset($options['retry']) && is_array($options['retry'])) {
            $client->setRetryOptions($options['retry']['max_retries'] ?? 3, $options['retry']['retry_delay'] ?? 1000);
        }
        if (isset($options['compress']) && $options['compress'] === true) {
            $client->enableCompression();
        }
        if (isset($options['cookies'])) {
            if (is_string($options['cookies'])) {
                $client->enableCookies($options['cookies']);
            } elseif ($options['cookies'] === true) {
                $client->enableCookies();
            }
        }
        if (isset($options['cookie_pairs']) && is_array($options['cookie_pairs'])) {
            foreach ($options['cookie_pairs'] as $name => $value) {
                $client->setCookie($name, $value);
            }
        }
        if (isset($options['custom_method'])) {
            $client->setCustomRequest($options['custom_method']);
        }
        if (isset($options['capture_headers']) && $options['capture_headers'] === true) {
            $client->withHeaderCapture();
        }
    }
}

if (!function_exists('http_api')) {
    /**
     * Execute a POST/GET/PUT/PATCH/DELETE request in one function.
     *
     * @param string $url
     * @param string $method
     * @param array $data
     * @param array $headers
     * @return HttpResponse
     */
    function http_api(string $url, ?string $method = null, $data = null, array $headers = []): HttpResponse
    {
        $request = HttpRequest::createFromGlobals();
        return $request->api($url, $method, $data, $headers);
    }
}