<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A booking keeps a snapshot of its price (integer cents, excl. VAT unless named otherwise),
        // so later price changes do not alter existing bookings.
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique('bookings_reference_uq');
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_class_id')->constrained()->restrictOnDelete();
            $table->string('fuel_type', 30);
            $table->string('transmission', 30);
            $table->foreignId('pickup_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('return_branch_id')->constrained('branches')->restrictOnDelete();
            $table->dateTime('pickup_at');
            $table->dateTime('return_at');
            $table->string('status', 20)->default('unconfirmed');
            $table->boolean('own_risk_reduced')->default(false);
            $table->unsignedInteger('extra_km')->default(0);
            $table->unsignedInteger('rental_cents');
            $table->unsignedInteger('own_risk_reduction_cents')->default(0);
            $table->unsignedInteger('extras_cents')->default(0);
            $table->unsignedInteger('extra_km_cents')->default(0);
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('deposit_cents')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index('status', 'bookings_status_idx');
            $table->index('pickup_at', 'bookings_pickup_at_idx');
        });

        Schema::create('booking_extras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('title', 191);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('price_unit', 30)->nullable();
            $table->unsignedInteger('cents');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_extras');
        Schema::dropIfExists('bookings');
    }
};
