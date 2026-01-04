<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCvNotesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cv_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('cv_uid')->nullable()->default('Null');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('sale_id')->constrained('sales')->onDelete('cascade');
            $table->foreignId('applicant_id')->constrained('applicants')->onDelete('cascade');
            $table->longText('details');
            $table->tinyInteger('status')->default(1)->comment('0=Inactive, 1=Active, 2=Paid');
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Optional indexes for better performance
            $table->index('applicant_id');
            $table->index('user_id');
            $table->index('sale_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cv_notes');
    }
}
