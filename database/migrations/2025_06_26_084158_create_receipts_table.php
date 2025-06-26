<?php

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('image_path');
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('store_name')->nullable();
            $table->date('receipt_date')->nullable();
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');
            $table->date('week_of')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'week_of']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
