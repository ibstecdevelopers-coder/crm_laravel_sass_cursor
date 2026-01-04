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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('contact_name');
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('contact_landline', 50)->nullable();
            $table->string('contact_note', 255)->nullable();
            $table->string('contactable_id');
            $table->string('contactable_type');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Optional indexes for better performance
            $table->index('contact_name');
            $table->index('contact_email');
            $table->index('contact_phone');
            $table->index('contact_landline');
            $table->index('contactable_id');
            $table->index('contactable_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
