<?php

namespace App\Jobs;

use App\Models\RequestLogs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LogRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $requestId,
        public array $requestData,
        public array $responseData,
        public array $context
    ) {}

    public function handle(): void
    {

        try {
            RequestLogs::create([
                'request_id' => $this->requestId,
                'method' => $this->requestData['method'],
                'url' => $this->requestData['url'],
                'headers' => json_encode($this->requestData['headers']),
                'input' => json_encode($this->requestData['input']),
                'status_code' => $this->responseData['status'],
                'response_headers' => json_encode([
                    'content-type' => $this->responseData['content_type']
                ]),
                'response_body' => $this->responseData['body'],
                'response_size' => $this->context['response_size'] ?? 0,
                'duration' => $this->context['duration'],
                'memory_usage' => $this->context['memory'],
                'ip' => $this->responseData['ip'] ?? "UNKNOWN_IP",
                'user_agent' => $this->responseData['user_agent'] ?? "UNKNOWN_USER_AGENT",
            ]);


        } catch (\Throwable $e) {
            Log::error("Failed to log request {$this->requestId}: " . $e->getMessage());
        }
    }
}
