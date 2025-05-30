<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;

class RequestTracker
{
    const CACHE_PREFIX = 'outgoing_req:';
    const OUTGOING_LIST_KEY = 'outgoing_list:';

    public static function trackRequest(TransferStats $stats, string $mainRequestId): string
    {
        $requestId = $mainRequestId . '_' . Str::random(6);
        $request = $stats->getRequest();

        $data = [
            'request_id' => $requestId,
            'main_request_id' => $mainRequestId,
            'method' => $request->getMethod(),
            'uri' => (string) $request->getUri(),
            'headers' => $request->getHeaders(),
            'start_time' => microtime(true),
            'stats' => $stats->getHandlerStats(),
            'status' => null,
            'response' => null
        ];

        Cache::put(
            self::CACHE_PREFIX . $requestId,
            $data,
            Config::get('request_interceptor.cache_ttl', 300)
        );

        // Add to main request's outgoing list
        $listKey = self::OUTGOING_LIST_KEY . $mainRequestId;
        $list = Cache::get($listKey, []);
        $list[] = $requestId;
        Cache::put($listKey, $list, Config::get('request_interceptor.cache_ttl', 300));

        return $requestId;
    }

    public static function finalizeRequest(string $requestId, $response): void
    {
        $cacheKey = self::CACHE_PREFIX . $requestId;
        $data = Cache::get($cacheKey);

        if (!$data) {
            return;
        }

        // Handle both success and error responses
        if ($response instanceof ResponseInterface) {
            $data['status'] = $response->getStatusCode();
            $data['response'] = [
                'headers' => $response->getHeaders(),
                'body' => Str::limit((string)$response->getBody(), 500)
            ];
        } elseif ($response instanceof \Exception) {
            $data['error'] = [
                'message' => $response->getMessage(),
                'code' => $response->getCode()
            ];
            $data['status'] = $response->getCode() ?: 500;
        }

        $data['duration'] = microtime(true) - $data['start_time'];
        Cache::put($cacheKey, $data, Config::get('request_interceptor.cache_ttl', 300));
    }
    public static function getTrackedRequests(string $mainRequestId): array
    {
        $listKey = self::OUTGOING_LIST_KEY . $mainRequestId;
        $requestIds = Cache::get($listKey, []);

        return array_filter(array_map(
            fn($id) => Cache::get(self::CACHE_PREFIX . $id),
            $requestIds
        ));
    }
}
