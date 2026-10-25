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
        Schema::create('business_commission_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete();
            $table->decimal('rate', 5, 2);
            $table->string('basis')->default('subtotal_excluding_tax');
            $table->string('state')->default('draft');
            $table->string('source')->default('business_agreement');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('reason');
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'state', 'starts_at']);
        });
        Schema::table('order_platform_fees', function (Blueprint $table) {
            $table->foreignId('business_commission_policy_id')->nullable()->constrained('business_commission_policies')->restrictOnDelete();
        });
        // Establish the agreement at cutover only. Never invent links for older fees.
        DB::transaction(function () {
            $at = now();
            DB::table('businesses')->orderBy('id')->chunkById(200, function ($businesses) use ($at) {
                foreach ($businesses as $business) {
                    $rate = DB::table('business_payment_settings')->where('business_id', $business->id)->value('commission_rate');
                    $source = $rate === null ? 'system_default' : 'business_override';
                    $rate ??= DB::table('system_settings')->where('key', 'payments.default_commission_rate')->value('value');
                    if ($rate === null || ! is_numeric($rate) || $rate < 0 || $rate > 100) {
                        throw new RuntimeException('Configure a valid commission rate before migrating business '.$business->id);
                    }
                    DB::table('business_commission_policies')->insert([
                        'uuid' => (string) Str::uuid(), 'business_id' => $business->id, 'rate' => $rate,
                        'basis' => 'subtotal_excluding_tax', 'state' => 'published', 'source' => $source,
                        'starts_at' => $at, 'approved_at' => $at,
                        'reason' => 'Initial agreement copied from payment configuration at commission-policy cutover. Historical fee snapshots remain unchanged.',
                        'created_at' => $at, 'updated_at' => $at,
                    ]);
                }
            });
        });
    }

    public function down(): void
    {
        Schema::table('order_platform_fees', function (Blueprint $table) {
            $table->dropForeign(['business_commission_policy_id']);
            $table->dropColumn('business_commission_policy_id');
        });
        Schema::dropIfExists('business_commission_policies');
    }
};
