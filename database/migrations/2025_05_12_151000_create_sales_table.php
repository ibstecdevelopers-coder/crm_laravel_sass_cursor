<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSalesTable extends Migration
{
    public function up()
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('sale_uid', 255)->nullable()->default(null);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('office_id');
            $table->unsignedBigInteger('unit_id');
            $table->unsignedBigInteger('job_category_id');
            $table->unsignedBigInteger('job_title_id');
            $table->string('sale_postcode', 50);
            $table->string('position_type', 50);
            $table->string('job_type', 50);
            $table->longText('timing', 255)->nullable();
            $table->longText('salary', 255)->nullable();
            $table->longText('experience')->nullable();
            $table->longText('qualification', 255)->nullable();
            $table->longText('benefits', 255)->nullable();
            $table->float('lat', 15, 6)->nullable()->default(null);
            $table->float('lng', 15, 6)->nullable()->default(null);
            $table->longText('job_description')->nullable();
            $table->tinyInteger('is_on_hold')->default(0)->comment('0=Not On Hold, 1=On Hold, 2=Pending');
            $table->tinyInteger('is_re_open')->default(0)->comment('0=No, 1=Yes, 2=Requested');
            $table->tinyInteger('cv_limit')->default(8);
            $table->longText('sale_notes')->nullable();
            $table->tinyInteger('status')->default(2)->comment('0=Inactive/deleted, 1=Active, 2=Pending, 3=Rejected'); // 'pending' assumed as 2

            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->softDeletes(); // This adds the 'deleted_at' column for soft deletes

            // Foreign key constraints
            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('office_id')->references('id')->on('offices');
            $table->foreign('unit_id')->references('id')->on('units');
            $table->foreign('job_category_id')->references('id')->on('job_categories');
            $table->foreign('job_title_id')->references('id')->on('job_titles');

            // Optional indexes for better performance
            $table->index('office_id');
            $table->index('user_id');
            $table->index('unit_id');
            $table->index('job_category_id');
            $table->index('job_title_id');
            $table->index('sale_postcode');
        });
    }

    public function down()
    {
        Schema::dropIfExists('sales');
    }
}
