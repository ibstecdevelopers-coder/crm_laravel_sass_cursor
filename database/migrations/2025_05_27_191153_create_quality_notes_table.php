<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateQualityNotesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('quality_notes', function (Blueprint $table) {
            $table->id();
            $table->string('quality_notes_uid', 255)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('applicant_id');
            $table->unsignedBigInteger('sale_id');
            $table->longText('details');
            $table->string('moved_tab_to', 50);
            $table->boolean('status')->default(1);
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('applicant_id')->references('id')->on('applicants')->onDelete('cascade');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');

            // Optional indexes for better performance
            $table->index('applicant_id');
            $table->index('user_id');
            $table->index('sale_id');
            $table->index('moved_tab_to');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('quality_notes');
    }
}
