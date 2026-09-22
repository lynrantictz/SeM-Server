<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('value');
            $table->string('value_type')->default('string');
            $table->string('description')->nullable();
            $table->uuid('uuid')->unique();
            $table->timestamps();
        });

        Schema::create('system_setting_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('system_setting_id');
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('previous_value')->nullable();
            $table->string('new_value');
            $table->string('reason')->nullable();
            $table->uuid('uuid')->unique();
            $table->timestamps();

            $table->index(['system_setting_id', 'created_at']);
        });

        DB::transaction(function (): void {
            DB::table('system_settings')->insert([
                'key' => 'payments.default_commission_rate',
                'value' => '2.00',
                'value_type' => 'decimal',
                'description' => 'Default Paperstic commission percentage for a successful gateway payment.',
                'uuid' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::statement('ALTER TABLE business_payment_settings ALTER COLUMN commission_rate DROP DEFAULT');
            DB::statement('ALTER TABLE business_payment_settings ALTER COLUMN commission_rate DROP NOT NULL');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_setting_audits');
        Schema::dropIfExists('system_settings');
    }
};
