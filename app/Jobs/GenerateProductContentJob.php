<?php

namespace App\Jobs;

use App\Models\ProductContentGeneration;
use App\Services\AI\AIServiceFactory;
use App\Services\TranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateProductContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $generationId;
    private $aiProvider;

    public function __construct(int $generationId, string $aiProvider = 'openai')
    {
        $this->generationId = $generationId;
        $this->aiProvider = $aiProvider;
    }

    public function handle()
    {
        $generation = ProductContentGeneration::find($this->generationId);

        if (!$generation) {
            Log::error("ProductContentGeneration not found: {$this->generationId}");
            return;
        }

        try {
            $generation->update(['status' => 'processing']);

            // Check cache first
            $cacheKey = $this->getCacheKey($generation);
            $cachedContent = Cache::get($cacheKey);

            if ($cachedContent) {
                Log::info("Using cached content for generation: {$this->generationId}");
                $this->processResult($generation, $cachedContent);
                return;
            }

            // Generate new content
            Log::info("Generating content for ID: {$this->generationId} using provider: {$this->aiProvider}");

            $aiService = AIServiceFactory::create($this->aiProvider);

            $data = [
                'image_path' => $generation->image_path,
                'prices' => $generation->prices,
                'category' => $generation->category,
                'product_type' => $generation->product_type,
                'sample_title' => $generation->sample_title,
                'brand' => $generation->brand,
            ];

            $generatedContent = $aiService->generateProductContent($data);

            // Generate translations for countries that need them
            $translatedContent = [];
            $countries = collect($generation->prices)->pluck('country');
            Log::info("Countries for translation: " . implode(', ', $countries->toArray()));

            foreach ($countries as $country) {
                Log::info("Checking translation for country: {$country}");
                if (TranslationService::shouldTranslate($country)) {
                    Log::info("Translating content for country: {$country}");
                    $language = TranslationService::getLanguageForCountry($country);
                    Log::info("Translating content to language: {$language}");
                    $contentToTranslate = json_encode($generatedContent, JSON_PRETTY_PRINT);
                    Log::info("Translating content:", ['language' => $language]);
                    $translated = $aiService->translateContent($contentToTranslate, $language);
                    $translatedContent[$country] = [
                        'language' => $language,
                        'content' => $translated
                    ];
                    Log::info("Translation completed for country: {$country}");
                }
            }

            $result = [
                'generated_content' => $generatedContent,
                'translated_content' => $translatedContent
            ];

            // Cache the result for 24 hours
            Cache::put($cacheKey, $result, 86400);

            $this->processResult($generation, $result);

        } catch (\Exception $e) {
            Log::error("Content generation failed for ID {$this->generationId}: " . $e->getMessage());

            $generation->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);
        }
    }

    private function processResult(ProductContentGeneration $generation, array $result)
    {
        $generation->update([
            'status' => 'completed',
            'generated_content' => $result['generated_content'],
            'translated_content' => $result['translated_content'],
        ]);

        Log::info("Content generation completed for ID: {$this->generationId}");
    }

    private function getCacheKey(ProductContentGeneration $generation): string
    {
        $dataHash = md5(serialize([
            'image_path' => $generation->image_path,
            'prices' => $generation->prices,
            'category' => $generation->category,
            'product_type' => $generation->product_type,
            'sample_title' => $generation->sample_title,
            'brand' => $generation->brand,
            'provider' => $this->aiProvider
        ]));

        return "product_content_{$dataHash}";
    }

}
