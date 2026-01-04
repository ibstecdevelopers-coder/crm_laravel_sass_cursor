<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('job_titles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('related_titles')->nullable();
            $table->string('type', 50);
            $table->unsignedBigInteger('job_category_id')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Timestamps with default values and automatic update on change
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('job_category_id')->references('id')->on('job_categories')->onDelete('set null'); // Changed from 'cascade'

            // Optional indexes for better performance
            $table->index('name');
            $table->index('job_category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_titles');
    }
};
