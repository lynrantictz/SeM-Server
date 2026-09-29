<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_inquiries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source', 50)->default('public_pricing');
            $table->string('package_code', 50)->nullable();
            $table->string('package_name', 100)->nullable();
            $table->string('name', 120);
            $table->string('business_name', 160);
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('message');
            $table->string('status', 30)->default('new');
            $table->unsignedBigInteger('assigned_to_user_id')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['package_code', 'created_at']);
            $table->index('assigned_to_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_inquiries');
    }
};
