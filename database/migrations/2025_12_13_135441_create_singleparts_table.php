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
        Schema::create('singleparts', function (Blueprint $table) {
            $table->id();
            $table->string('part_number')->unique();
            $table->string('part_name');
            $table->string('customer_code');
            $table->string('supplier_code')->nullable();
            $table->string('model')->nullable();
            $table->string('variant')->nullable();
            $table->unsignedInteger('standard_packing');
            $table->unsignedInteger('stock')->default(0);
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('part_number');
            $table->index('customer_code');
            $table->index('is_active');
            $table->index('supplier_code'); // Added: supplier_code index
            $table->index(['is_active', 'stock']); // Added: composite index for is_active, stock
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('singleparts');
    }
};
