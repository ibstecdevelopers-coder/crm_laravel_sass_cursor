<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateNotesForRangeApplicantsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('notes_for_range_applicants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('range_uid', 255)->nullable()->default(null);
            $table->unsignedBigInteger('applicants_pivot_sales_id');
            $table->longText('reason');
            $table->tinyInteger('status')->default(1);
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Foreign key constraint
            $table->foreign('applicants_pivot_sales_id')
                  ->references('id')
                  ->on('applicants_pivot_sales')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('notes_for_range_applicants');
    }
}