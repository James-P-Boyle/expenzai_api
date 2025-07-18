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
        Schema::table('receipts', function (Blueprint $table) {
            $table->string('original_filename')->nullable()->after('image_path');
            $table->bigInteger('file_size')->nullable()->after('original_filename');
            $table->string('storage_disk')->default('public')->after('file_size');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['original_filename', 'file_size', 'storage_disk']);
        });
    }
};
