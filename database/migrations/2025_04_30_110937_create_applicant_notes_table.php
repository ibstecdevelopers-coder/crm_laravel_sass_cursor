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
        Schema::create('applicant_notes', function (Blueprint $table) {
            $table->id();
            
            $table->string('note_uid', 255)->nullable()->default(null);
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('applicant_id')->constrained('applicants');
            
            $table->longText('details');
            $table->string('moved_tab_to', 50)->nullable();
            $table->tinyInteger('status')->default(1);
            
            // Explicit timestamp definitions with CURRENT_TIMESTAMP
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            
            // Optional indexes for better performance
            $table->index('user_id');
            $table->index('applicant_id');
            $table->index('moved_tab_to');
            
        });

        // Add index for the polymorphic relationship
        Schema::table('applicant_notes', function (Blueprint $table) {
            $table->index(['user_id', 'applicant_id', 'moved_tab_to']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applicant_notes');
    }
};
