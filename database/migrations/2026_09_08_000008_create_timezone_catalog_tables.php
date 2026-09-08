<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timezone_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('timezones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timezone_group_id')->constrained('timezone_groups')->cascadeOnDelete();
            $table->string('identifier', 64)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['timezone_group_id', 'is_active', 'sort_order']);
        });

        foreach (config('timezones.groups', []) as $groupData) {
            $groupId = DB::table('timezone_groups')->insertGetId([
                'name' => $groupData['name'],
                'is_active' => true,
                'sort_order' => $groupData['sort_order'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($groupData['zones'] as $zone) {
                DB::table('timezones')->insert([
                    'timezone_group_id' => $groupId,
                    'identifier' => $zone['identifier'],
                    'name' => $zone['name'],
                    'is_active' => true,
                    'sort_order' => $zone['sort_order'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('timezones');
        Schema::dropIfExists('timezone_groups');
    }
};
