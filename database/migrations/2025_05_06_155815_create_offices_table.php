<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateOfficesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('offices', function (Blueprint $table) {
            $table->id(); // This will create the 'id' column with auto-increment
            $table->string('office_uid', 255)->nullable()->default(null);
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('office_name', 255);
            $table->string('office_postcode', 50);
            $table->string('office_website', 255)->nullable();
            $table->longText('office_notes');
            $table->float('office_lat', 15, 6)->nullable()->default(null);
            $table->float('office_lng', 15, 6)->nullable()->default(null);
            $table->tinyInteger('status')->default(1);

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            $table->softDeletes(); // This adds the 'deleted_at' column for soft deletes

            
            // Optional indexes for better performance
            $table->index('user_id');
            $table->index('office_name');
            $table->index('office_postcode');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('offices');
    }
}
