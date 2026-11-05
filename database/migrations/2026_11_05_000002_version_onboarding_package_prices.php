<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_packages', function (Blueprint $table) {
            $table->dropUnique('onboarding_packages_key_unique');
            $table->unsignedInteger('version')->default(1)->after('key');
            $table->timestamp('activated_at')->nullable()->after('is_active');
            $table->timestamp('deactivated_at')->nullable()->after('activated_at');
            $table->unique(['key', 'version'], 'onboarding_packages_key_version_unique');
        });

        $catalog = [
            'start' => ['name' => 'Paperstic Starter', 'price' => 750000, 'sort_order' => 10],
            'business' => ['name' => 'Paperstic Business', 'price' => 1500000, 'sort_order' => 20],
            'premium' => ['name' => 'Paperstic Premium', 'price' => 3000000, 'sort_order' => 30],
        ];

        DB::transaction(function () use ($catalog): void {
            $now = now();
            DB::table('onboarding_packages')->where('is_active', true)->whereNull('activated_at')->update(['activated_at' => $now]);

            foreach ($catalog as $key => $package) {
                // A clean database starts at v1; existing catalog rows are normalized in place.
                DB::table('onboarding_packages')->where('key', $key)->update([
                    'version' => 1,
                    'name' => $package['name'],
                    'price' => $package['price'],
                    'currency' => 'TZS',
                    'is_active' => true,
                    'activated_at' => $now,
                    'deactivated_at' => null,
                    'sort_order' => $package['sort_order'],
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Price-version rollback would discard catalog history and can invalidate
        // onboarding payment references, so require a deliberate forward repair.
        throw new RuntimeException('Onboarding package price history cannot be safely rolled back.');
    }
};
