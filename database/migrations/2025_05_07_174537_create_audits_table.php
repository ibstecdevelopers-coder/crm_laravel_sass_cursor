<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditsTable extends Migration
{
    public function up()
    {
        Schema::create('audits', function (Blueprint $table) {
            $table->id(); // auto-incrementing id as primary key
            $table->foreignId('user_id')->constrained('users'); // foreign key reference to 'users' table
            $table->longText('data');
            $table->string('message', 255);
            $table->bigInteger('auditable_id');
            $table->string('auditable_type', 50);
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Optional indexes for better performance
            $table->index('auditable_id');
            $table->index('auditable_type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('audits');
    }
}
