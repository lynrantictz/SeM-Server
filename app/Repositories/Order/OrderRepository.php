<?php

namespace App\Repositories\Order;

use App\Models\Order\Order;
use App\Models\Section\Code;
use App\Repositories\BaseRepository;
use Illuminate\Support\Facades\DB;

class OrderRepository extends BaseRepository
{
    const MODEL = Order::class;

    public function inputManipulator(Code $code, $inputs) : array {
        return [
            'business_id' => $code->codable->business_id,
            // ''
        ];
    }

    /**
     * Store new order
     */
    public function store(Code $code, $inputs)
    {
        return DB::transaction(function() use($code, $inputs){
            // $order = $code->orders()->create([
                
            // ])
        });
    }
}
