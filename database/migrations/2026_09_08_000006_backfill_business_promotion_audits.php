<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('business_promotions')->orderBy('id')->each(function ($promotion) {
            $exists = DB::table('business_promotion_audits')->where('promotion_id', $promotion->id)->exists();
            if ($exists) return;

            $values = (array) $promotion;
            DB::table('business_promotion_audits')->insert([
                'business_id' => $promotion->business_id,
                'promotion_id' => $promotion->id,
                'user_id' => null,
                'action' => 'imported',
                'previous_values' => null,
                'new_values' => json_encode($values),
                'reason' => 'Promotion existed before promotion history tracking was enabled.',
                'created_at' => $promotion->created_at,
                'updated_at' => $promotion->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('business_promotion_audits')->where('action', 'imported')->delete();
    }
};
