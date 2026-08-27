<?php

namespace Database\Seeders;

use App\Models\Business\Business;
use App\Models\Business\Vendor;
use App\Services\OrderPrefixService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class VendorBusinessSeeder extends Seeder
{
    /**
     *
     * Run the database seeds.
     */
    public function run(): void
    {
        $vendors = [
            [
                'country_id' => 1,
                'name' => 'AX Hotels',
                'email' => 'ceo@seashellshotel.co.tz',
                'email_verified_at' => now(),
                'phone' => '255712000000',
                'address' => 'Kaunda drive, Oysterbay',
                'business' => [
                    // Oyster Bay is in Kinondoni, Dar es Salaam.
                    'district_id' => 9,
                    'business_type_id' => 7,
                    'tin' => '0000-000-000',
                    'name' => 'Seashells Millennium Hotel',
                    'location' => 'Millennium Towers, Bagamoyo Road',
                    'google_location' => 'https://maps.app.goo.gl/58b8vecTTGqKbTtA7',
                    'latitude' => -6.7461370,
                    'longitude' => 39.2458290,
                    'location_verified_at' => now(),
                    'is_active' => true,
                ]
            ]
        ];

        foreach ($vendors as $vendorData) {
            $businessData = $vendorData['business'];
            unset($vendorData['business']);

            $vendor = Vendor::firstOrCreate(
                ['email' => $vendorData['email']],
                $vendorData
            );

            $vendor->businesses()->updateOrCreate(
                ['name' => $businessData['name'], 'vendor_id' => $vendor->id],
                array_merge($businessData, [
                    'order_prefix' => (new OrderPrefixService())->generate($businessData['name']),
                    'current_order_number' => 0,
                ])
            );
        }
    }
}
