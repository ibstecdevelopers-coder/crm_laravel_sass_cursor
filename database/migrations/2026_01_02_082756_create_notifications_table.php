<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->unsignedBigInteger('applicant_id')->nullable();

            $table->text('message')->nullable();
            $table->text('type')->nullable();
            $table->string('is_read')->default('0'); // 0: unread, 1: read
            $table->string('notify_by')->nullable();   // 👈 added here

            $table->timestamps();

            $table->index('user_id');
            $table->index('sale_id');
            $table->index('applicant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
