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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('plan_id')->constrained()->onDelete('cascade');
            $table->string('source')->default('web')->after('status');

            $table->string('status')->default('pending');

            // نوع پرداخت (card, crypto)
            $table->string('payment_method')->nullable()->after('status');
            // اطلاعات پرداخت کارت به کارت
            $table->string('card_payment_receipt')->nullable()->after('payment_method');
            // اطلاعات درگاه ارز دیجیتال
            $table->string('nowpayments_payment_id')->nullable()->after('card_payment_receipt');

            $table->string('card_payment_receipt')->nullable()->change();
            $table->foreignId('plan_id')->nullable(false)->change();
            $table->decimal('amount', 15, 0)->nullable()->after('plan_id');

            $table->text('config_details')->nullable()->after('card_payment_receipt');

            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
