<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSaleNotesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sale_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sales_notes_uid')->nullable();
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('user_id');
            $table->longText('sale_note');
            $table->tinyInteger('status')->default(1);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Foreign keys
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Optional indexes for better performance
            $table->index('user_id');
            $table->index('sale_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_notes');
    }
}
