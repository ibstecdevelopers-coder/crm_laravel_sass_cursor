<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSmsTemplatesTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('title')->unique();               // e.g. "welcome_sms"
            $table->string('slug')->unique();               // e.g. "welcome_sms"
            $table->longText('template');                        // SMS body text with placeholders

            $table->tinyInteger('status')->default(0)->comment('0 = inactive, 1 = active');

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
}
