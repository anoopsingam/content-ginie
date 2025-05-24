<?php

namespace App\Services\AI;

use App\Services\AI\AIServiceInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService implements AIServiceInterface
{
    private $apiKey;
    private $baseUrl = 'https://api.anthropic.com/v1';
    private $model = 'claude-3-5-sonnet-20241022';

    public function __construct()
    {
        $this->apiKey = config('services.claude.api_key');
    }

    /**
     * @throws ConnectionException
     */
    public function generateProductContent(array $data): array
    {
        try {
            $prompt = $this->buildPrompt($data);

            // Build message content array
            $messageContent = [
                [
                    'type' => 'text',
                    'text' => $prompt
                ]
            ];

            // Add image if provided
            if (!empty($data['image_path'])) {
                // For Claude, we need base64 encoded image data
                $imageData = $this->processImageForClaude($data['image_path']);
                if ($imageData) {
                    $messageContent[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $imageData['media_type'],
                            'data' => $imageData['data']
                        ]
                    ];
                }
            }

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01'
            ])->post($this->baseUrl . '/messages', [
                'model' => $this->model,
                'max_tokens' => 1000,
                'temperature' => 0.7,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $messageContent
                    ]
                ]
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $content = $responseData['content'][0]['text'];
                return $this->parseGeneratedContent($content);
            }

            throw new \Exception('Claude API request failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('Claude content generation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @throws ConnectionException
     */
    public function translateContent(string $content, string $targetLanguage): string
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'anthropic-version' => '2023-06-01'
            ])->post($this->baseUrl . '/messages', [
                'model' => $this->model,
                'max_tokens' => 800,
                'temperature' => 0.3,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => "Translate the following product content to {$targetLanguage}. Maintain SEO optimization and marketing tone:\n\n{$content}"
                            ]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return $responseData['content'][0]['text'];
            }

            throw new \Exception('Translation failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('Claude translation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function buildPrompt(array $data): string
    {
        $priceInfo = collect($data['prices'])->map(function($price) {
            return "{$price['country']}: {$price['price']} {$price['currency']}";
        })->join(', ');

        return "Analyze this product image and generate SEO-optimized content for an e-commerce fashion product with the following details:
            Brand: {$data['brand']}
            Category: {$data['category']}
            Product Type: {$data['product_type']}
            Sample Title: {$data['sample_title']}
            Prices: {$priceInfo}

            Please provide the response in this exact JSON format:
            {
                \"title\": \"SEO title under 70 characters\",
                \"description\": \"Meta description under 160 characters\",
                \"product_description\": \"Detailed product description with SEO keywords\",
                \"features\": [\"feature1\", \"feature2\", \"feature3\"],
                \"keywords\": [\"keyword1\", \"keyword2\", \"keyword3\"]
            }

            Focus on fashion terminology, style features visible in the image, and include relevant keywords for search optimization.";
    }

    private function processImageForClaude(string $imagePath): ?array
    {
        try {
            // Check if input is a data URI (base64)
            if (preg_match('/^data:(image\/[a-zA-Z0-9.+-]+);base64,(.+)$/', $imagePath, $matches)) {
                $contentType = $matches[1];
                $imageData = base64_decode($matches[2]);
            } elseif (filter_var($imagePath, FILTER_VALIDATE_URL)) {
                // If it's a URL, fetch the image
                $imageResponse = Http::get($imagePath);
                if (!$imageResponse->successful()) {
//                    Log::warning('Failed to fetch image from URL: ' . $imagePath);
                    return null;
                }
                $imageData = $imageResponse->body();
                $contentType = $imageResponse->header('Content-Type');
            } else {
                // If it's a local file path
                if (!file_exists($imagePath)) {
//                    Log::warning('Image file not found: ' . $imagePath);
                    return null;
                }
                $imageData = file_get_contents($imagePath);
                $contentType = mime_content_type($imagePath);
            }

            // Validate image type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($contentType, $allowedTypes)) {
                Log::warning('Unsupported image type: ' . $contentType);
                return null;
            }

            return [
                'media_type' => $contentType,
                'data' => base64_encode($imageData)
            ];

        } catch (\Exception $e) {
            Log::error('Error processing image for Claude: ' . $e->getMessage());
            return null;
        }
    }

    private function parseGeneratedContent(string $content): array
    {
        // Extract JSON from the response
        preg_match('/\{.*\}/s', $content, $matches);

        if (empty($matches)) {
            throw new \Exception('Invalid response format from Claude');
        }

        $decoded = json_decode($matches[0], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON response from Claude');
        }

        // Validate required fields
        $requiredFields = ['title', 'description', 'product_description', 'features', 'keywords'];
        foreach ($requiredFields as $field) {
            if (!isset($decoded[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        return $decoded;
    }
}
