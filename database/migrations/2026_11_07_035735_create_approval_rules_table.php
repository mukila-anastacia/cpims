<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('level');
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->decimal('min_value', 12, 2)->default(0);
            $table->decimal('max_value', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_rules');
    }
};