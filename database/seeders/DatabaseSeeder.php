<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(LocationSeeder::class);
        $this->call(OrderStatusSeeder::class);
        $this->call(PaymentMethodSeeder::class);
        $this->call(PaymentStatusSeeder::class);
        $this->call(MobileMoneyProviderSeeder::class);
        $this->call(BusinessTypeSeeder::class);
        $this->call(TimezoneCatalogSeeder::class);
        $this->call(ComplianceDocumentTypeSeeder::class);
        $this->call(BusinessStaffRoleSeeder::class);
        $this->call(OrderingChannelSeeder::class);
        $this->call(VendorBusinessSeeder::class);
        $this->call(CategoryItemSeeder::class);
        $this->call(DiscoveryVenueSeeder::class);
        $this->call(SectionAndSubsectionAndCodesSeerder::class);
        $this->call(RolePermissionSeeder::class);
    }
}
