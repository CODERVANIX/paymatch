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
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_import_id')->constrained('bank_imports');
            $table->string('account_label', 80);
            $table->date('booked_on');
            $table->string('bank_id', 120);
            $table->string('reference', 500)->default('');
            $table->unsignedBigInteger('amount_cents');
            $table->char('fingerprint', 64)->index();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('bank_transactions');
            $table->foreignId('matched_order_id')->nullable()->unique()->constrained('payment_orders');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
