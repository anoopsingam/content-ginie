<?php

namespace App\Http\Middleware;

use App\Helpers\RequestTracker;
use App\Jobs\LogRequest;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to intercept and track incoming and outgoing HTTP requests.
 */
class RequestInterceptor
{
    protected const REQUEST_ID_HEADER = 'X-Request-ID';
    protected const CACHE_PREFIX = 'req_track:';
    protected const OUTGOING_REQUEST_PREFIX = 'outgoing_req:';

    public function handle(Request $request, Closure $next): Response
    {
        if (!Config::get('request_interceptor.enabled', true)) {
            return $next($request);
        }

        $requestId = $this->getOrCreateRequestId($request);
        $request->attributes->set('request_id', $requestId);

        $this->initializeRequestTracking($requestId, $request);
        $this->logRequest($requestId, 'START', $this->getSanitizedRequestContext($request));

        if (Config::get('request_interceptor.track_outgoing_requests', true)) {
            $this->setupOutgoingRequestTracking($requestId);
        }

        $response = $next($request);
        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $requestId = $request->attributes->get('request_id');
        if (!$requestId) {
            return;
        }

        try {
            $duration = microtime(true) - LARAVEL_START;
            $outgoingRequests = RequestTracker::getTrackedRequests($requestId);

            $context = [
                'duration' => $this->formatDuration($duration),
                'memory' => $this->formatMemory(memory_get_peak_usage(true)),
                'response_size' => $this->formatSize($this->getResponseSize($response)),
                'outgoing_requests' => $outgoingRequests,
                'status' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ];

            if (class_exists(LogRequest::class)) {
                LogRequest::dispatch(
                    $requestId,
                    $this->getSanitizedRequestData($request),
                    $this->getSanitizedResponseData($response, $request),
                    $context
                );
            }

            $this->logRequest($requestId, 'COMPLETE', $context);
            $this->cleanupRequestTracking($requestId);
        } catch (\Throwable $e) {
            Log::error("Request termination failed: " . $e->getMessage());
        }
    }
    protected function setupOutgoingRequestTracking(string $mainRequestId): void
    {
        App::bind(Client::class, function () use ($mainRequestId) {
            return new Client([
                'handler' => $this->createGuzzleHandlerStack($mainRequestId)
            ]);
        });
    }


    protected function createTrackingMiddleware(string $mainRequestId): callable
    {
        return function (callable $handler) use ($mainRequestId) {
            return function ($request, $options) use ($handler, $mainRequestId) {
                $requestId = null;

                $options['on_stats'] = function (TransferStats $stats) use ($mainRequestId, &$requestId) {
                    $requestId = RequestTracker::trackRequest($stats, $mainRequestId);
                };

                return $handler($request, $options)->then(
                    function ($response) use (&$requestId) {
                        if ($requestId) {
                            RequestTracker::finalizeRequest($requestId, $response);
                        }
                        return $response;
                    },
                    function ($exception) use (&$requestId) {
                        if ($requestId) {
                            RequestTracker::finalizeRequest($requestId, $exception);
                        }
                        throw $exception;
                    }
                );
            };
        };
    }
    protected function createGuzzleHandlerStack(string $mainRequestId): \GuzzleHttp\HandlerStack
    {
        $stack = \GuzzleHttp\HandlerStack::create();

        // Add our tracking middleware
        $stack->push($this->createTrackingMiddleware($mainRequestId));

        return $stack;
    }





    protected function associateOutgoingRequest(string $mainRequestId, string $outgoingRequestId): void
    {
        $key = $this->getOutgoingRequestsListKey($mainRequestId);
        $requests = Cache::get($key, []);
        $requests[] = $outgoingRequestId;
        Cache::put($key, $requests, Config::get('request_interceptor.cache_ttl', 300));
    }


    protected function cleanupRequestTracking(string $requestId): void
    {
        Cache::forget($this->getCacheKey($requestId));

        if (Config::get('request_interceptor.track_outgoing_requests', true)) {
            $this->cleanupOutgoingRequestsTracking($requestId);
        }
    }

    protected function cleanupOutgoingRequestsTracking(string $mainRequestId): void
    {
        $key = $this->getOutgoingRequestsListKey($mainRequestId);
        $requestIds = Cache::get($key, []);

        foreach ($requestIds as $requestId) {
            Cache::forget($this->getOutgoingRequestCacheKey($requestId));
        }

        Cache::forget($key);
    }

    protected function getOrCreateRequestId(Request $request): string
    {
        return $request->header(self::REQUEST_ID_HEADER) ?? Str::uuid()->toString();
    }

    protected function initializeRequestTracking(string $requestId, Request $request): void
    {
        Cache::put(
            $this->getCacheKey($requestId),
            [
                'start_time' => microtime(true),
                'method' => $request->method(),
                'url' => $request->path(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
            Config::get('request_interceptor.cache_ttl', 300)
        );
    }

    protected function getCacheKey(string $requestId): string
    {
        return self::CACHE_PREFIX . $requestId;
    }

    protected function getOutgoingRequestCacheKey(string $requestId): string
    {
        return self::OUTGOING_REQUEST_PREFIX . $requestId;
    }

    protected function getOutgoingRequestsListKey(string $mainRequestId): string
    {
        return self::CACHE_PREFIX . 'outgoing:' . $mainRequestId;
    }

    protected function getSanitizedRequestContext(Request $request): array
    {
        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'ip' => $request->ip(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
        ];
    }

    protected function getSanitizedRequestData(Request $request): array
    {
        return [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'headers' => $this->sanitizeHeaders($request->headers->all()),
            'input' => $this->sanitizeInput($request->all()),
        ];
    }

    protected function getSanitizedResponseData(Response $response, Request $request): array
    {
        $maxSize = Config::get('request_interceptor.max_response_log_size', 2048);
        $contentType = $response->headers->get('Content-Type');
        $content = $response->getContent();

        if (str_contains($contentType, 'text/html')) {
            $content = 'HTML_CONTENT';
        } else {
            $content = Str::limit($this->sanitizeResponseContent($content), $maxSize, '[TRUNCATED]');
        }

        return [
            'status' => $response->getStatusCode(),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->userAgent(),
            'content_type' => $contentType,
            'body' => $content,
        ];
    }


    protected function sanitizeHeaders(array $headers): array
    {
        $sensitiveHeaders = Config::get('request_interceptor.sensitive_headers', [
            'authorization', 'cookie', 'password', 'token',
            'mobile', 'phone', 'email', 'otp', 'api_key'
        ]);

        return array_map(function ($value, $key) use ($sensitiveHeaders) {
            $lowerKey = strtolower($key);
            if (in_array($lowerKey, $sensitiveHeaders)) {
                return "***REDACTED_HEADER_{$lowerKey}***";
            }
            return $this->maskSensitiveData($value);
        }, $headers, array_keys($headers));
    }

    protected function sanitizeInput(array $input): array
    {
        $sensitiveFields = Config::get('request_interceptor.sensitive_fields', [
            'password', 'credit_card', 'cvv', 'token',
            'mobile', 'mobile_no', 'phone', 'phone_number',
            'email', 'email_address', 'otp', 'api_key'
        ]);

        $sanitized = [];
        foreach ($input as $key => $value) {
            $lowerKey = strtolower($key);

            if (in_array($lowerKey, $sensitiveFields)) {
                $sanitized[$key] = "***REDACTED_FIELD_{$lowerKey}***";
                continue;
            }

            $sanitized[$key] = $this->maskSensitiveData($value);
        }

        return $sanitized;
    }

    protected function sanitizeResponseContent(string $content): string
    {
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return json_encode($this->maskSensitiveData($decoded));
        }
        return $this->maskSensitiveData($content);
    }

    protected function maskSensitiveData($value)
    {
        if (is_array($value)) {
            return array_map([$this, 'maskSensitiveData'], $value);
        }

        if (!is_string($value)) {
            return $value;
        }

        $value = preg_replace_callback(
            Config::get('request_interceptor.mask_patterns.mobile', '/\b\d{10}\b/'),
            function ($matches) {
                return substr($matches[0], 0, 3) . '****' . substr($matches[0], -3);
            },
            $value
        );

        $value = preg_replace_callback(
            Config::get('request_interceptor.mask_patterns.email', '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/'),
            function ($matches) {
                $parts = explode('@', $matches[0]);
                $username = $parts[0];
                $domain = $parts[1] ?? '';

                $maskedUsername = strlen($username) > 2
                    ? substr($username, 0, 1) . '***' . substr($username, -1)
                    : '***';

                return $maskedUsername . '@' . $domain;
            },
            $value
        );

        return $value;
    }

    protected function getResponseSize(Response $response): int
    {
        return strlen($response->getContent());
    }

    protected function formatDuration(float $duration): string
    {
        return $duration < 1
            ? round($duration * 1000, 2) . ' ms'
            : round($duration, 2) . ' s';
    }

    protected function formatMemory(int $memory): string
    {
        return $memory < 1024 * 1024
            ? round($memory / 1024, 2) . ' KB'
            : round($memory / (1024 * 1024), 2) . ' MB';
    }

    protected function formatSize(int $size): string
    {
        return $size < 1024 * 1024
            ? round($size / 1024, 2) . ' KB'
            : round($size / (1024 * 1024), 2) . ' MB';
    }

    protected function logRequest(string $requestId, string $stage, array $context = []): void
    {
        try {
            $logEntry = [
                'request_id' => $requestId,
                'stage' => $stage,
                'timestamp' => now()->toISOString(),
                'context' => $context,
            ];

            Log::channel('requests')->info(json_encode($logEntry));
        } catch (\Throwable $e) {
            error_log("Request {$stage} - {$requestId}: " . json_encode($context));
        }
    }
}
