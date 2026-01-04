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
        Schema::create('applicants_pivot_sales', function (Blueprint $table) {
            $table->id(); // bigint(20) auto-increment primary key
            
            $table->string('pivot_uid', 255)->nullable()->default(null);
            
            $table->foreignId('applicant_id')
                  ->constrained('applicants')
                  ->onDelete('cascade');
                  
            $table->foreignId('sale_id')
                  ->constrained('sales')
                  ->onDelete('cascade');
                  
            $table->boolean('is_interested')->default(false);
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // Optional: composite index for the pivot relationship
            $table->index(['applicant_id', 'sale_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('applicants_pivot_sales');
    }
};
