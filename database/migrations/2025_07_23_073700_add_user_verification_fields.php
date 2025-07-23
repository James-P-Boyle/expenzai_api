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
        Schema::table('users', function (Blueprint $table) {

            $table->enum('user_tier', ['free', 'basic', 'pro'])->default('basic')->after('email');
            $table->string('email_verification_token')->nullable()->after('email_verified_at');
            
            $table->integer('total_uploads')->default(0)->after('user_tier');
            $table->integer('daily_uploads')->default(0)->after('total_uploads');
            $table->date('last_upload_date')->nullable()->after('daily_uploads');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'user_tier',
                'email_verification_token', 
                'total_uploads',
                'daily_uploads',
                'last_upload_date'
            ]);
        });
    }
};
