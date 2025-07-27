<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 10, 2);
            $table->decimal('price_yearly', 10, 2);
            $table->string('stripe_price_id_monthly')->nullable();
            $table->string('stripe_price_id_yearly')->nullable();
            $table->json('features');
            $table->integer('upload_limit')->default(8); // -1 for unlimited
            $table->boolean('is_popular')->default(false);
            $table->boolean('coming_soon')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_plan_id')->constrained()->onDelete('cascade');
            $table->string('stripe_subscription_id')->unique();
            $table->string('stripe_customer_id');
            $table->enum('status', ['active', 'cancelled', 'past_due', 'trialing', 'incomplete']);
            $table->enum('billing_interval', ['monthly', 'yearly']);
            $table->datetime('current_period_start');
            $table->datetime('current_period_end');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->datetime('trial_start')->nullable();
            $table->datetime('trial_end')->nullable();
            $table->datetime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Update users table to add stripe_customer_id
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('user_tier');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('stripe_customer_id');
        });
        
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};