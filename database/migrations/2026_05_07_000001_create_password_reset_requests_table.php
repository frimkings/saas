<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePasswordResetRequestsTable extends Migration
{
    public function up()
    {
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('password_reset_requests');
    }
}
