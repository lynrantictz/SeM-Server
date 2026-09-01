<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_staff_roles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('business_user', function (Blueprint $table) {
            $table->foreignId('business_staff_role_id')
                ->nullable()
                ->after('business_role')
                ->constrained('business_staff_roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_user', function (Blueprint $table) {
            $table->dropForeign(['business_staff_role_id']);
            $table->dropColumn('business_staff_role_id');
        });

        Schema::dropIfExists('business_staff_roles');
    }
};
