<?php

namespace App\Repositories\Customer;

use App\Models\Customer\Customer;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerRepository extends BaseRepository
{
    const MODEL = Customer::class;

    public function getQuery()
    {
        return $this->query();
    }

    public function getCustomerByPhone($phone)
    {
        return $this->checkIfCustomerIsRegistered($phone);
    }

    public function checkIfCustomerIsRegistered($phone)
    {
        $customer = $this->query()->wherePhone($phone)->first();
        if (!$customer) {
            $customer = $this->store($phone);
        }
        Log::info($customer);
        return $customer;
    }

    public function store($phone)
    {
        return DB::transaction(function () use ($phone) {
            return $this->query()->create([
                'phone' => $phone
            ]);
        });
    }
}
