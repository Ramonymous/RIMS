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
        Schema::create('request_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('requests')->onDelete('cascade');
            $table->foreignId('part_id')->constrained('singleparts')->onDelete('cascade');
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_urgent')->default(false);
            $table->string('status')->default('pending');
            $table->index(['status']);
            $table->index(['part_id', 'status']); // Added: composite index for part_id, status
            $table->index('request_id'); // Added: request_id index
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_lists');
    }
};
