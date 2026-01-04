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
        Schema::create('messages', function (Blueprint $table) {
            $table->id(); // bigint(20) auto_increment primary key
            // Other columns
            $table->string('msg_id', 255)
                  ->nullable()
                  ->default(null);
            // Foreign keys
            $table->bigInteger('module_id')
                  ->nullable();
            $table->string('module_type', 255);
                  
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
                  
            $table->text('message');
            $table->string('phone_number', 50);
            
            $table->date('date');
            $table->time('time');
            
            $table->string('status',50);
            $table->tinyInteger('is_sent')->default(0);
            $table->tinyInteger('is_read')->default(0);
            
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // Indexes
            $table->index('module_id');
            $table->index('module_type');
            $table->index('user_id');
            $table->index('msg_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};