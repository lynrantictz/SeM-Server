<?php

namespace App\Http\Controllers\Api\V1\Menu;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\OrderingChannel;
use App\Http\Controllers\Controller;
use App\Models\Order\Order;
use App\Models\Section\Code;
use App\Repositories\Menu\CategoryRepository;
use App\Services\MenuAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CategoryController extends BaseController
{
    protected CategoryRepository $categories;

    public function __construct(CategoryRepository $categories)
    {
        $this->categories = $categories;
    }

    public function getMenu(Request $request)
    {
        $channel = $request->query('channel', 'dine_in');
        abort_unless(in_array($channel, OrderingChannel::activeSlugs(), true), HTTP_UNPROCESSABLE_ENTITY, 'The ordering channel is invalid.');
        $codeParam = $request->query('c');
        //check if code is valid
        $code = Code::query()->where('code', $codeParam)->first();
        // if not valid return error
        if (!$code) {
            return $this->sendError('Invalid code', [], HTTP_NOT_FOUND);
        }
        if (!$code->is_active) {
            return $this->sendError('This QR code is no longer active.', [], HTTP_NOT_FOUND);
        }

        $relationships = [
            'business',
            'business.type',
            'business.vendor',
            'business.district',
            'business.district.city',
            'business.district.city.country',
            'business.promotions'
        ];

        if ($code->codable instanceof \App\Models\Section\ServicePoint) {
            if (!$code->codable->is_active) {
                return $this->sendError('This table, room, or service point is not currently accepting orders.', [], HTTP_NOT_FOUND);
            }
            array_unshift($relationships, 'section', 'subSection');
        } elseif ($code->codable instanceof \App\Models\Section\SubSection) {
            array_unshift($relationships, 'section');
        }

        $codable = $code->codable->load($relationships);
        if (!$codable->business->is_active) {
            return $this->sendError('This business is not currently accepting orders.', [], HTTP_NOT_FOUND);
        }
        $business = $code->codable->business;
        if (!$this->channelEnabled($business, $channel)) {
            return $this->sendError('This ordering channel is not enabled for this business.', ['channel' => $channel], HTTP_UNPROCESSABLE_ENTITY);
        }
        $availability = app(MenuAvailabilityService::class);
        $businessStatus = $availability->businessStatus($business);
        if (!$businessStatus['is_open_now']) {
            return $this->sendError($businessStatus['reason'], ['menu_status' => $businessStatus], HTTP_UNPROCESSABLE_ENTITY);
        }

        $menu = $business->categories()->with([
            'availabilityRules.days',
            'items' => function ($query) {
                $query->where('is_active', true)->where('is_sold_out', false)
                    ->with([
                        'availabilityRules.days',
                        'optionGroups' => fn ($groups) => $groups->where('is_active', true)->with(['options' => fn ($options) => $options->where('is_active', true)]),
                    ]);
            },
        ])
            ->where('is_active', true)
            ->orderBy('categories.name', 'ASC')
            ->get()
            ->map(function ($category) use ($availability, $business, $channel) {
                $categoryStatus = $availability->categoryStatus($category, $business, $channel);
                $category->setRelation('items', $category->items->filter(function ($item) use ($availability, $business, $channel) {
                    $status = $availability->itemStatus($item, $business, $channel);
                    $pricing = $availability->itemPricing($item, $business, $channel);
                    $item->setAttribute('discount', $pricing['discount_percentage']);
                    $item->setAttribute('discount_percentage', $pricing['discount_percentage']);
                    $item->setAttribute('final_price', $pricing['final_price']);
                    $item->setAttribute('discount_amount', $pricing['discount_amount']);
                    $item->setAttribute('availability', $status);
                    return $status['is_available_now'];
                })->values());
                $category->setAttribute('availability', $categoryStatus);
                return $category;
            })
            ->filter(fn ($category) => $category->getAttribute('availability')['is_available_now'] && $category->items->isNotEmpty())
            ->values();

        $data['code'] = $codable;
        $data['menu'] = $menu;
        $data['menu_status'] = $businessStatus;
        $data['channel'] = $channel;

        return $this->sendResponse($data, 'Menu retrieved successfully', HTTP_OK);
    }

    private function channelEnabled($business, string $channel): bool
    {
        $setting = $business->orderingChannels()->where('slug', $channel)->where('ordering_channels.is_active', true)->first();
        return $setting ? (bool) $setting->pivot->is_enabled : false;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
