<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('module_notes', function (Blueprint $table) {
            $table->id(); // bigint(20) primary key auto-increment

            $table->string('module_note_uid', 255)->nullable()->default(null);
            $table->foreignId('user_id')->constrained('users'); // Foreign key to users.id

            // Polymorphic relationship columns
            $table->unsignedBigInteger('module_noteable_id'); // bigint(20)
            $table->string('module_noteable_type', 50); // varchar(50)

            $table->longText('details');
            $table->tinyInteger('status')->default(1); // tinyint(1) with default 1

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Optional indexes for better performance
            $table->index('user_id');
            $table->index('module_noteable_id');
            $table->index('module_noteable_type');
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_notes');
    }
};
