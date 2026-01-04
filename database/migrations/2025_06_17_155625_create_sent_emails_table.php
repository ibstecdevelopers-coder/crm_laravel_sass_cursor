<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSentEmailsTable extends Migration
{
    public function up()
    {
        Schema::create('sent_emails', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Foreign keys
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('applicant_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('sale_id')->nullable()->constrained()->onDelete('set null');

            // Email fields
            $table->string('action_name', 191);
            $table->string('sent_from', 191);
            $table->string('sent_to', 191);
            $table->string('cc_emails', 191)->nullable();
            $table->string('subject', 191);
            $table->string('title', 191);
            $table->longText('template');

            // Email status: 0 = unsent, 1 = sent, 2 = failed
            $table->enum('status', ['0', '1', '2'])->default('0')->comment('0=unsent, 1=sent, 2=failed');

            // Timestamps
            $table->timestamps();

            // Indexes for fast queries
            $table->index(['user_id', 'applicant_id', 'sale_id']);
        });

    }

    public function down()
    {
        Schema::dropIfExists('sent_emails');
    }
}


?>