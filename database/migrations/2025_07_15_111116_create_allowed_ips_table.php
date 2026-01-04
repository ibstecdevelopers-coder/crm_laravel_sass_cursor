<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowed_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip_prefix', 15); // e.g., '192.168.1'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowed_ips');
    }
};