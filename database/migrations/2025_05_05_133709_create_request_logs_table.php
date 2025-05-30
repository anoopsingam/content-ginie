<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_id');
            $table->string('method', 10);
            $table->text('url');
            $table->json('headers')->nullable();
            $table->json('input')->nullable();
            $table->integer('status_code');
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->string('response_size')->nullable();
            $table->string('duration')->nullable(); // in seconds
            $table->string('memory_usage')->nullable(); // in bytes
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index('request_id');
            $table->index('method');
            $table->index('status_code');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_logs');
    }
};
