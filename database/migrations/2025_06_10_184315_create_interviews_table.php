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
        Schema::create('interviews', function (Blueprint $table) {
            $table->id(); // bigint(20) auto_increment primary key
            
            // Unique identifier
            $table->string('interview_uid', 255)->nullable()->default(null);
            
            // Foreign keys
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('applicant_id')->constrained('applicants');
            $table->foreignId('sale_id')->constrained('sales');
            
            // Scheduling columns
            $table->string('schedule_time', 50);
            $table->string('schedule_date', 50);
            
            // Status (default to 1)
            $table->tinyInteger('status')->default(1);
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Indexes
            $table->index('interview_uid');
            $table->index(['user_id', 'applicant_id', 'sale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};