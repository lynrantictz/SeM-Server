<?php

namespace Database\Seeders;

use App\Models\Business\Business;
use App\Models\Business\BusinessType;
use App\Models\Business\Vendor;
use App\Models\Location\District;
use App\Models\Menu\Category;
use App\Models\Menu\Item;
use Illuminate\Database\Seeder;

class DiscoveryVenueSeeder extends Seeder
{
    public function run(): void
    {
        $vendor = Vendor::firstOrCreate(
            ['email' => 'discovery-demo@paperstick.co.tz'],
            ['country_id' => 1, 'name' => 'Paperstick Discovery Demo', 'phone' => '255700000100', 'address' => 'Dar es Salaam, Tanzania']
        );

        $types = BusinessType::query()->whereIn('name', ['Restaurant', 'Coffee Shop', 'Hotel'])->pluck('id', 'name');
        $districts = District::query()->with('city')->get()->groupBy(fn (District $district) => $district->city?->name);

        $locations = [
            ['city' => 'Dar es Salaam', 'latitude' => -6.7924, 'longitude' => 39.2083],
            ['city' => 'Mwanza', 'latitude' => -2.5164, 'longitude' => 32.9175],
            ['city' => 'Arusha', 'latitude' => -3.3869, 'longitude' => 36.6830],
            ['city' => 'Dodoma', 'latitude' => -6.1630, 'longitude' => 35.7516],
            ['city' => 'Zanzibar', 'latitude' => -6.1659, 'longitude' => 39.2026],
        ];
        $names = ['Savannah', 'Coastal', 'Kilimanjaro', 'Lake', 'Spice', 'Boma', 'Harbour', 'Acacia', 'Mango', 'Palm', 'Baobab', 'Kivukoni', 'Golden', 'Serengeti', 'Jambo', 'Soko', 'Terrace', 'Saffron', 'Rooftop', 'Sunset'];
        $suffixes = ['Kitchen', 'Grill', 'Coffee House', 'Table', 'Bistro', 'Lodge', 'Eatery', 'Terrace', 'Café', 'Hotel'];
        $images = [
            'https://images.unsplash.com/photo-1517248135467-4c7edcad34c4?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1504674900247-0877df9cc836?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1552566626-52f8b828add9?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1515003197210-e0cd71810b5f?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1482049016688-2d3e1b311543?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1555396273-367ea4eb4db5?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1528605248644-14dd04022da1?auto=format&fit=crop&w=1200&q=85',
            'https://images.unsplash.com/photo-1565299507177-b0ac66763828?auto=format&fit=crop&w=1200&q=85',
        ];
        $categorySets = [
            ['African', 'BBQ', 'Drinks'], ['Seafood', 'Desserts', 'Drinks'], ['Breakfast', 'Coffee', 'Desserts'], ['Indian', 'Grill', 'Drinks'], ['Burgers', 'Fast Food', 'Drinks'],
        ];

        for ($index = 1; $index <= 100; $index++) {
            $location = $locations[($index - 1) % count($locations)];
            $district = $districts->get($location['city'])?->values()->get(($index - 1) % max(1, $districts->get($location['city'])?->count() ?? 1));
            if (!$district) {
                continue;
            }
            $typeName = ['Restaurant', 'Coffee Shop', 'Hotel'][($index - 1) % 3];
            $name = $names[($index - 1) % count($names)] . ' ' . $suffixes[(int) floor(($index - 1) / count($names)) % count($suffixes)] . ' ' . $index;
            $business = Business::updateOrCreate(
                ['vendor_id' => $vendor->id, 'name' => $name],
                [
                    'district_id' => $district->id, 'business_type_id' => $types[$typeName], 'tin' => 'DEMO-' . str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                    'location' => $district->name . ', ' . $location['city'], 'google_location' => null,
                    // Stable pseudo-random spread: each venue has a distinct, realistic point near its city centre.
                    'latitude' => round($location['latitude'] + (sin($index * 2.17) * 0.075) + ((($index * 17) % 11) - 5) * 0.002, 7),
                    'longitude' => round($location['longitude'] + (cos($index * 1.73) * 0.09) + ((($index * 29) % 13) - 6) * 0.002, 7),
                    'location_verified_at' => now(), 'is_active' => true, 'tax_allowed' => false,
                    'order_prefix' => 'ORD-DV' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'code_prefix' => 'DV' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                    'current_order_number' => 0,
                    'image_url' => $images[($index - 1) % count($images)], 'discovery_description' => "A welcoming {$typeName} serving guests in {$location['city']}.",
                    'rating' => 4 + (($index % 10) / 10), 'review_count' => 12 + ($index * 7), 'opening_hours' => $typeName === 'Hotel' ? 'Open 24 hours' : 'Open until 10:00 pm', 'price_range' => 'TSh ' . (5000 + (($index % 4) * 5000)) . '–' . (20000 + (($index % 5) * 5000)),
                ]
            );

            foreach ($categorySets[($index - 1) % count($categorySets)] as $categoryName) {
                $category = Category::firstOrCreate(['business_id' => $business->id, 'name' => $categoryName], ['is_active' => true]);
                Item::firstOrCreate(['category_id' => $category->id, 'name' => "{$categoryName} special"], ['description' => "A popular {$categoryName} choice.", 'price' => 8000 + ($index * 100), 'currency' => 'TZS', 'discount' => 0, 'final_price' => 8000 + ($index * 100), 'is_active' => true]);
            }
        }
    }
}
