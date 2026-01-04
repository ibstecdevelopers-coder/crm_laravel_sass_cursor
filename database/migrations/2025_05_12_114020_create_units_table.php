<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUnitsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();  // auto-incrementing BIGINT
            $table->string('unit_uid', 255)->nullable()->default(null);
            $table->foreignId('user_id')->constrained()->onDelete('cascade');  // foreign key to 'users' table
            $table->foreignId('office_id')->constrained()->onDelete('cascade');  // foreign key to 'offices' table
            $table->string('unit_name', 255);
            $table->string('unit_postcode', 50);
            $table->string('unit_website', 255)->nullable();
            $table->longText('unit_notes')->nullable();
            $table->float('lat', 15, 6)->nullable()->default(null);
            $table->float('lng', 15, 6)->nullable()->default(null);
            $table->tinyInteger('status')->default(1);  // assuming default status is active (1)

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->softDeletes(); // This adds the 'deleted_at' column for soft deletes

            // Optional indexes for better performance
            $table->index('user_id');
            $table->index('office_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('units');
    }
}
