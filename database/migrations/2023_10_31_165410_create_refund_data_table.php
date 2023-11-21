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
        Schema::create('refund_data', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('purchase_id')->nullable()->references('id')->on('purchases')->onDelete('set null')->onUpdate('cascade');
            $table->foreignUuid('user_id')->references('id')->on('users')->onDelete('cascade')->onUpdate('cascade');
            $table->string('account_number');
            $table->string('phone_number');
            $table->text('message');
            $table->integer('nominal');
            $table->string('ticket_name');
            $table->string('event_name');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refund_data');
    }
};
