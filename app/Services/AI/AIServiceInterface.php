<?php

namespace App\Services\AI;

interface AIServiceInterface
{
    public function generateProductContent(array $data): array;
    public function translateContent(string $content, string $targetLanguage): array;
}
