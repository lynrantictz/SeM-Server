<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('key', 40)->unique();
            $table->string('name', 100);
            $table->decimal('price', 14, 2);
            $table->string('currency', 10)->default('TZS');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('business_onboarding_payments', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('business_id')->constrained('onboarding_packages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_onboarding_payments', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn('package_id');
        });
        Schema::dropIfExists('onboarding_packages');
    }
};
