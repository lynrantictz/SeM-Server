<?php

namespace Database\Seeders;

use App\Models\Business\Timezone;
use App\Models\Business\TimezoneGroup;
use Illuminate\Database\Seeder;

class TimezoneCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('timezones.groups', []) as $groupData) {
            $group = TimezoneGroup::updateOrCreate(
                ['name' => $groupData['name']],
                ['is_active' => true, 'sort_order' => $groupData['sort_order']],
            );

            foreach ($groupData['zones'] as $zone) {
                Timezone::updateOrCreate(
                    ['identifier' => $zone['identifier']],
                    [
                        'timezone_group_id' => $group->id,
                        'name' => $zone['name'],
                        'is_active' => true,
                        'sort_order' => $zone['sort_order'],
                    ],
                );
            }
        }
    }
}
