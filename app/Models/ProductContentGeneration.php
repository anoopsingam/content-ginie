<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductContentGeneration extends Model
{
    protected $fillable = [
        'request_id',
        'image_path',
        'prices',
        'category',
        'product_type',
        'sample_title',
        'brand',
        'status',
        'generated_content',
        'translated_content',
        'error_message'
    ];

    protected $casts = [
        'prices' => 'array',
        'generated_content' => 'array',
        'translated_content' => 'array',
    ];
}
