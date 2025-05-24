<?php

namespace App\Services\AI;

use App\Services\AI\AIServiceInterface;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService implements AIServiceInterface
{
    private $apiKey;
    private $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
    }

    /**
     * @throws ConnectionException
     */
    public function generateProductContent(array $data): array
    {
        try {


            $prompt = $this->buildPrompt($data);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => $prompt
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => $data['image_path']
                                ]
                            ]
                        ]
                    ]
                ],
                'max_tokens' => 1000,
                'temperature' => 0.7
            ]);

            if ($response->successful()) {
                $content = $response->json()['choices'][0]['message']['content'];
                return $this->parseGeneratedContent($content);
            }

            throw new \Exception('OpenAI API request failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('OpenAI content generation failed: ' . $e->getMessage());
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
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post($this->baseUrl . '/chat/completions', [
                'model' => 'gpt-4',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "Translate the following product content to {$targetLanguage}. Maintain SEO optimization and marketing tone:\n\n{$content}"
                    ]
                ],
                'max_tokens' => 800,
                'temperature' => 0.3
            ]);

            if ($response->successful()) {
                return $response->json()['choices'][0]['message']['content'];
            }

            throw new \Exception('Translation failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('OpenAI translation failed: ' . $e->getMessage());
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

    private function parseGeneratedContent(string $content): array
    {
        // Extract JSON from the response
        preg_match('/\{.*\}/s', $content, $matches);

        if (empty($matches)) {
            throw new \Exception('Invalid response format from OpenAI');
        }

        $decoded = json_decode($matches[0], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON response from OpenAI');
        }

        return $decoded;
    }
}
