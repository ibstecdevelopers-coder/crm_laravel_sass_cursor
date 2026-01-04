<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateLoginDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('login_details', function (Blueprint $table) {
            $table->id(); // Automatically creates an auto-incrementing id column
            $table->foreignId('user_id')->constrained('users'); // Foreign key referencing 'id' in 'users' table
            $table->string('ip_address');
            $table->time('login_at')->useCurrent(); // Set default to CURRENT_TIMESTAMP, but no on update
            $table->time('logout_at')->nullable();

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('login_details');
    }
}
