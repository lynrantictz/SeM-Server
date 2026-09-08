<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->foreignId('timezone_id')->nullable()->after('timezone')->constrained('timezones')->restrictOnDelete();
        });

        $utcId = DB::table('timezones')->where('identifier', 'UTC')->value('id');
        foreach (config('timezones.groups', []) as $groupData) {
            foreach ($groupData['zones'] as $zone) {
                $timezoneId = DB::table('timezones')->where('identifier', $zone['identifier'])->value('id');
                if ($timezoneId) {
                    DB::table('businesses')->where('timezone', $zone['identifier'])->update(['timezone_id' => $timezoneId]);
                }
            }
        }

        if ($utcId) {
            DB::table('businesses')->whereNull('timezone_id')->update(['timezone_id' => $utcId]);
        }
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropForeign(['timezone_id']);
            $table->dropColumn('timezone_id');
        });
    }
};
