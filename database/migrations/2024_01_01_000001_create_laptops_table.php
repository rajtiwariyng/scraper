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
        Schema::create('laptops', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 50)->index(); // Amazon, Flipkart, etc.
            $table->string('sku')->index(); // Product SKU/ID
            $table->string('product_name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->text('offers')->nullable(); // JSON or text for offers/discounts
            $table->string('inventory_status', 50)->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->integer('review_count')->default(0);
            $table->json('variants')->nullable(); // JSON for variants
            $table->string('brand', 100)->nullable();
            $table->string('model_name')->nullable();
            $table->string('screen_size', 50)->nullable();
            $table->string('color', 50)->nullable();
            $table->string('hard_disk', 100)->nullable();
            $table->string('cpu_model', 100)->nullable();
            $table->string('ram', 50)->nullable();
            $table->string('operating_system', 100)->nullable();
            $table->text('special_features')->nullable();
            $table->string('graphics_card', 100)->nullable();
            $table->json('image_urls')->nullable(); // JSON array of image URLs
            $table->json('video_urls')->nullable(); // JSON array of video URLs
            $table->string('product_url')->nullable(); // Original product URL
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_scraped_at')->nullable();
            $table->timestamps();
            
            // Composite unique index for platform + sku
            $table->unique(['platform', 'sku'], 'platform_sku_unique');
            
            // Additional indexes for performance
            $table->index(['platform', 'is_active']);
            $table->index(['brand', 'is_active']);
            $table->index(['last_scraped_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laptops');
    }
};

