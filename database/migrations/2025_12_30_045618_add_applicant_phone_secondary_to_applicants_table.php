<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('applicant_phone_secondary', 50)
                ->nullable()
                ->after('applicant_phone')
                ->index('idx_applicant_phone_secondary');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropIndex('idx_applicant_phone_secondary');
            $table->dropColumn('applicant_phone_secondary');
        });
    }
};

