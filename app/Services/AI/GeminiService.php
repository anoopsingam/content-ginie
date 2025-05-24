<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService implements AIServiceInterface
{
    private $apiKey;
    private $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
    }

    public function generateProductContent(array $data): array
    {
        try {
            $imageData = $data['image_path'];

            // Strip the data URI scheme
            if (str_starts_with($imageData, 'data:image')) {
                $imageData = preg_replace('#^data:image/\w+;base64,#i', '', $imageData);
            }
            $prompt = $this->buildPrompt($data);

            $response = Http::post($this->baseUrl . '/models/gemini-1.5-flash:generateContent?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => 'image/jpeg',
                                    'data' => $imageData
                                ]
                            ]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.7,
                    'maxOutputTokens' => 1000
                ]
            ]);

            if ($response->successful()) {
                $content = $response->json()['candidates'][0]['content']['parts'][0]['text'];
                return $this->parseGeneratedContent($content);
            }

            throw new \Exception('Gemini API request failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('Gemini content generation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function translateContent(string $content, string $targetLanguage): array
    {
        try {
            Log::info("Translating content to {$targetLanguage} using Gemini");
            $response = Http::post($this->baseUrl . '/models/gemini-1.5-flash:generateContent?key=' . $this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => "Translate the following product content to {$targetLanguage}. Maintain SEO optimization and marketing tone:\n\n{$content}"]
                        ]
                    ]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 800
                ]
            ]);

            if ($response->successful()) {
                $translatedContent= $response->json()['candidates'][0]['content']['parts'][0]['text'];
                return $this->parseGeneratedTranslatedContent($translatedContent);

            }

            throw new \Exception('Translation failed: ' . $response->body());

        } catch (\Exception $e) {
            Log::error('Gemini translation failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function buildPrompt(array $data): string
    {
        // Same prompt as OpenAI service
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
                \"product_description\": \"Detailed product description with SEO keywords limit to 300 words\",
                \"features\": [\"feature1\", \"feature2\", \"feature3\"],
                \"keywords\": [\"keyword1\", \"keyword2\", \"keyword3\"]
            }

            Focus on fashion terminology, style features visible in the image, and include relevant keywords for search optimization.";
    }

    private function parseGeneratedContent(string $content): array
    {
        // Same parsing logic as OpenAI service
        preg_match('/\{.*\}/s', $content, $matches);

        if (empty($matches)) {
            throw new \Exception('Invalid response format from Gemini');
        }

        $decoded = json_decode($matches[0], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON response from Gemini');
        }

        return $decoded;
    }


    private function parseGeneratedTranslatedContent(string $content): array
    {
        // Same parsing logic as OpenAI service
        preg_match('/\{.*\}/s', $content, $matches);

        if (empty($matches)) {
            throw new \Exception('Invalid response format from Gemini');
        }

        $decoded = json_decode($matches[0], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse JSON response from Gemini');
        }

        return $decoded;
    }
}
