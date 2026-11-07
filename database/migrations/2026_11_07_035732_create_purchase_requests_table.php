<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 20)->unique();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['draft', 'in_approval', 'approved', 'rejected', 'returned', 'ordered', 'received', 'closed', 'cancelled'])->default('draft');
            $table->decimal('total_estimate', 12, 2)->default(0);
            $table->date('needed_by')->nullable();
            $table->text('justification')->nullable();
            $table->boolean('duplicate_flag')->default(false);
            $table->text('duplicate_justification')->nullable();
            $table->boolean('budget_exception')->default(false);
            $table->unsignedTinyInteger('current_level')->default(0);
            $table->string('attachment_path')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_requests');
    }
};