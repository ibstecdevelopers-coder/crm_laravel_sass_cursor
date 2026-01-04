<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIpAddressesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id(); // Big integer auto-incrementing primary key
            $table->foreignId('user_id')->constrained('users'); // Foreign key referencing the 'users' table
            $table->string('ip_address', 100); // IP address field (varchar(100))
            $table->string('mac_address', 100)->nullable()->default('Null'); // MAC address (nullable, default 'Null')
            $table->string('device_type', 50)->nullable()->default('Null'); // Device type (nullable, default 'Null')
            $table->boolean('status')->default(1); // Status (tinyint(1) default '1')

            $table->softDeletes();

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
        Schema::dropIfExists('ip_addresses');
    }
}
