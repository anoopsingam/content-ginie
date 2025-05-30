<?php


use GuzzleHttp\Client;
use Illuminate\Support\Str;

if (!function_exists('getRequestId')) {
    /**
     * Get the request ID from the request headers or generate a new one.
     *
     * @return string
     */
    function getRequestId(): string
    {
        return request()->header('X-Request-ID') ?: (string) Str::uuid();
    }
}

if (!function_exists('HttpClient')) {
    /**
     * Create a new HTTP client instance with tracking support
     *
     * @param array $config Additional Guzzle client configuration
     * @return Client
     */
    function HttpClient(array $config = []): Client
    {
        // Get the base configured client from container
        $client = app(Client::class);

        // Merge with custom config if provided
        if (!empty($config)) {
            $existingConfig = $client->getConfig();
            $newConfig = array_merge($existingConfig, $config);

            // Create new client instance with merged config
            return new Client($newConfig);
        }

        return $client;
    }
}









