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
        Schema::table('laptops', function (Blueprint $table) {
            // Additional technical specifications
            $table->string('manufacturer')->nullable()->after('brand');
            $table->string('series')->nullable()->after('model_name');
            $table->string('form_factor')->nullable()->after('color');
            $table->string('screen_resolution')->nullable()->after('screen_size');
            $table->string('package_dimensions')->nullable()->after('screen_resolution');
            $table->string('item_model_number')->nullable()->after('package_dimensions');
            $table->string('processor_brand')->nullable()->after('cpu_model');
            $table->string('processor_type')->nullable()->after('processor_brand');
            $table->string('processor_speed')->nullable()->after('processor_type');
            $table->integer('processor_count')->nullable()->after('processor_speed');
            $table->string('memory_technology')->nullable()->after('ram');
            $table->string('computer_memory_type')->nullable()->after('memory_technology');
            $table->string('maximum_memory_supported')->nullable()->after('computer_memory_type');
            $table->string('hard_disk_description')->nullable()->after('hard_disk');
            $table->string('hard_disk_interface')->nullable()->after('hard_disk_description');
            $table->string('graphics_coprocessor')->nullable()->after('graphics_card');
            $table->string('graphics_chipset_brand')->nullable()->after('graphics_coprocessor');
            $table->string('number_of_usb_ports')->nullable()->after('graphics_chipset_brand');
            $table->string('connectivity_type')->nullable()->after('number_of_usb_ports');
            $table->string('wireless_type')->nullable()->after('connectivity_type');
            $table->string('bluetooth_version')->nullable()->after('wireless_type');
            $table->string('battery_life')->nullable()->after('bluetooth_version');
            $table->string('weight')->nullable()->after('battery_life');
            $table->string('dimensions')->nullable()->after('weight');
            
            // Detailed offers information
            $table->json('detailed_offers')->nullable()->after('offers'); // JSON for structured offers data
            $table->text('cashback_offers')->nullable()->after('detailed_offers');
            $table->text('emi_offers')->nullable()->after('cashback_offers');
            $table->text('bank_offers')->nullable()->after('emi_offers');
            $table->text('partner_offers')->nullable()->after('bank_offers');
            
            // Additional product information
            $table->text('key_features')->nullable()->after('special_features');
            $table->json('technical_details')->nullable()->after('key_features'); // JSON for all technical specs
            $table->string('availability_status')->nullable()->after('inventory_status');
            $table->decimal('mrp_price', 10, 2)->nullable()->after('sale_price');
            $table->integer('discount_percentage')->nullable()->after('mrp_price');
            $table->string('seller_name')->nullable()->after('discount_percentage');
            $table->boolean('amazon_choice')->default(false)->after('seller_name');
            $table->boolean('bestseller')->default(false)->after('amazon_choice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('laptops', function (Blueprint $table) {
            $table->dropColumn([
                'manufacturer', 'series', 'form_factor', 'screen_resolution', 'package_dimensions',
                'item_model_number', 'processor_brand', 'processor_type', 'processor_speed',
                'processor_count', 'memory_technology', 'computer_memory_type', 'maximum_memory_supported',
                'hard_disk_description', 'hard_disk_interface', 'graphics_coprocessor', 'graphics_chipset_brand',
                'number_of_usb_ports', 'connectivity_type', 'wireless_type', 'bluetooth_version',
                'battery_life', 'weight', 'dimensions', 'detailed_offers', 'cashback_offers',
                'emi_offers', 'bank_offers', 'partner_offers', 'key_features', 'technical_details',
                'availability_status', 'mrp_price', 'discount_percentage', 'seller_name',
                'amazon_choice', 'bestseller'
            ]);
        });
    }
};

