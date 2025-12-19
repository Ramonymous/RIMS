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
        Schema::create('receivings', function (Blueprint $table) {
            $table->id();
            $table->string('receiving_number');
            $table->foreignId('part_id')->constrained('singleparts')->onDelete('cascade');
            $table->unsignedInteger('quantity');
            $table->foreignId('received_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('received_at');
            $table->string('status')->default('draft');
            $table->timestamps();

            $table->unique(['receiving_number', 'part_id']);
            $table->index('receiving_number');
            $table->index('part_id');
            $table->index('received_by');
            $table->index('received_at');
            $table->index(['receiving_number', 'status']); // Added: composite index for receiving_number, status
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receivings');
    }
};
