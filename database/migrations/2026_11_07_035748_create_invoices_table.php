<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('invoice_no', 40);
            $table->date('invoice_date');
            $table->decimal('total', 12, 2);
            $table->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $table->enum('match_status', ['pending', 'matched', 'exception', 'resolved'])->default('pending');
            $table->text('resolution_comment')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['vendor_id', 'invoice_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};