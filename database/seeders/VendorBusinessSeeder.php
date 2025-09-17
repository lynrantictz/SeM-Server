<?php

namespace Database\Seeders;

use App\Models\Business\Business;
use App\Models\Business\Vendor;
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
        /**
         * country_id
        tin
        name
        email
        email_verified_at
        phone
        address
         *
         *
         * district_id
        business_type_id
        tin
        name
        location
        google_location
         */
        $vendors = [
            [
                'country_id' => 1,
                'name' => 'AX Hotels',
                'email' => 'ceo@seashellshotel.co.tz',
                'email_verified_at' => now(),
                'phone' => '255712000000',
                'address' => 'Kaunda drive, Oysterbay',
                'business' => [
                    'district_id' => 5,
                    'business_type_id' => 7,
                    'tin' => '0000-000-000',
                    'name' => 'Seashells Millennium Hotel',
                    'location' => 'Millennium Towers, Bagamoyo Road',
                    'google_location' => 'https://maps.app.goo.gl/58b8vecTTGqKbTtA7',
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

            $vendor->businesses()->firstOrCreate(
                ['name' => $businessData['name'], 'vendor_id' => $vendor->id],
                $businessData
            );
        }
    }
}
