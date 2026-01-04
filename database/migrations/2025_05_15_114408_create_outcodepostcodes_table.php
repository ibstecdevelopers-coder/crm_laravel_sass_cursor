<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateoutcodepostcodesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('outcodepostcodes', function (Blueprint $table) {
            $table->id();  // Auto-incrementing primary key
            $table->string('outcode', 10);  // The actual postcode value
            $table->float('lat');  // Latitude coordinate
            $table->float('lng');  // Longitude coordinate
             $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Optional indexes for better performance
            $table->index('outcode');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('outcodepostcodes');
    }
}
