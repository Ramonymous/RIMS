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
        Schema::create('outgoings', function (Blueprint $table) {
            $table->id();
            $table->string('outgoing_number');
            $table->foreignId('part_id')->constrained('singleparts')->onDelete('cascade');
            $table->unsignedInteger('quantity');
            $table->foreignId('dispatched_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('dispatched_at');
            $table->timestamps();

            // Composite unique: each part can only appear once per outgoing batch
            $table->unique(['outgoing_number', 'part_id']);
            
            $table->index('part_id');
            $table->index('dispatched_by');
            $table->index('dispatched_at');
            $table->index('outgoing_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outgoings');
    }
};
