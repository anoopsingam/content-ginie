<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RequestLogs extends Model
{

    use HasUuids;

    protected $table = 'request_logs';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'request_id',
        'method',
        'url',
        'headers',
        'input',
        'status_code',
        'response_headers',
        'response_body',
        'response_size',
        'duration',
        'memory_usage',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'headers' => 'array',
        'input' => 'array',
        'response_headers' => 'array',
        'duration' => 'float',
        'response_size' => 'integer',
        'memory_usage' => 'integer',
        'status_code' => 'integer',
    ];
}
