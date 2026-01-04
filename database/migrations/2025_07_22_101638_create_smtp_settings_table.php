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
       Schema::create('smtp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('from_name')->nullable();              // Label like 'Default SMTP'
            $table->string('from_address')->nullable();              // Label like 'Default SMTP'
            $table->string('mailer');
            $table->string('host');
            $table->integer('port');
            $table->string('username');
            $table->string('password');
            $table->string('encryption')->nullable();        // ssl / tls / null
            $table->boolean('is_active')->default(true);    // For switching
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};
