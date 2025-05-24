<?php

namespace App\Services;

class TranslationService
{
    private static $countryLanguageMap = [
        'AE' => 'Arabic',
        'KSA' => 'Arabic',
        'QAT' => 'Arabic',
        'KWT' => 'Arabic',
        'BHR' => 'Arabic',
        'OMN' => 'Arabic',
        'FR' => 'French',
        'DE' => 'German',
        'ES' => 'Spanish',
        'IT' => 'Italian',
        'JP' => 'Japanese',
        'CN' => 'Chinese',
        'KR' => 'Korean',
        'RU' => 'Russian',
        'BR' => 'Portuguese',
        'MX' => 'Spanish',
    ];

    public static function getLanguageForCountry(string $country): ?string
    {
        return self::$countryLanguageMap[strtoupper($country)] ?? null;
    }

    public static function shouldTranslate(string $country): bool
    {
        return isset(self::$countryLanguageMap[strtoupper($country)]);
    }
}
