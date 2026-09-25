<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddPaymentStatusToSales extends Migration
{
    public function up()
    {
        // Add new columns without ->change()
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('amount_paid', 12, 2)->default(0)->after('total_amount');
            $table->enum('payment_status', ['paid', 'partial', 'unpaid'])->default('paid')->after('amount_paid');
        });

        // Back-fill existing fully-paid sales
        DB::statement('UPDATE sales SET amount_paid = total_amount, payment_status = "paid" WHERE deleted_at IS NULL');
    }

    public function down()
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['amount_paid', 'payment_status']);
        });
    }
}
