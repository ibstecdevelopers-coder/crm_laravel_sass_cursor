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
        Schema::create('crm_rejected_cv', function (Blueprint $table) {
            $table->id();
            $table->string('crm_rejected_cv_uid')->nullable();
            $table->foreignId('applicant_id')->constrained('applicants');
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('crm_note_id')->constrained('crm_notes');
            $table->foreignId('sale_id')->constrained('sales');
            $table->longText('reason');
            $table->string('crm_rejected_cv_note');
            $table->boolean('status')->default(true);
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Optional indexes for better performance
            $table->index('applicant_id');
            $table->index('user_id');
            $table->index('crm_note_id');
            $table->index('sale_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_rejected_cv');
    }
};