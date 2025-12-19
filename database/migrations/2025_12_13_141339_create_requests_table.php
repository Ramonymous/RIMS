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
        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->string('destination');
            $table->string('status')->default('pending');
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('requested_at');
            $table->timestamps();
            $table->index(['status', 'requested_at']); // Added: composite index for status, requested_at
            $table->index('destination'); // Added: destination index
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
