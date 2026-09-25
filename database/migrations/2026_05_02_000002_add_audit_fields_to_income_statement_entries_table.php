<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('income_statement_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('is_active');
            $table->unsignedBigInteger('deleted_by')->nullable()->after('created_by');
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deleted_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('income_statement_entries', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['deleted_by']);
            $table->dropSoftDeletes();
            $table->dropColumn(['created_by', 'deleted_by']);
        });
    }
};
