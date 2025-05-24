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
        Schema::create('product_content_generations', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique();
            $table->longText('image_path');
            $table->json('prices'); // [{'country': 'UAE', 'price': 100, 'currency': 'AED'}]
            $table->string('category');
            $table->string('product_type');
            $table->string('sample_title')->nullable();
            $table->string('brand')->default('Fashion and Clothing');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->json('generated_content')->nullable();
            $table->json('translated_content')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_content_generations');
    }
};
