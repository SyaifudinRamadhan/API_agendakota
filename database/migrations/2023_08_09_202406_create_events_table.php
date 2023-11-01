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
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->references('id')->on('organizations')->onDelete('cascade')->onUpdate('cascade');
            $table->string('slug');
            $table->string('name');
            $table->string('category');
            $table->string('topics');
            $table->string('logo');
            $table->text('desc');
            $table->string('snk');
            $table->string('exe_type');
            $table->text('location');
            $table->string('province');
            $table->string('city');
            $table->date('start_date');
            $table->date('end_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('is_publish');
            $table->string('instagram', 212)->nullable();
            $table->string('twitter', 212)->nullable();
            $table->string('website', 212)->nullable();
            $table->string('twn_url')->nullable();
            $table->string('custom_fields')->nullable();
            $table->string('seat_map')->nullable();
            $table->boolean('single_trx')->default(false);
            $table->integer('deleted');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
