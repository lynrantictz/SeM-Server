<?php

namespace App\Http\Controllers\Api\V1\Menu;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\OrderingChannel;
use App\Models\Menu\Category;
use App\Models\Menu\Item;
use App\Models\Business\BusinessPromotion;
use App\Models\Business\BusinessPromotionAudit;
use App\Services\MenuAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuManagementController extends BaseController
{
    public function index(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
            'channel' => ['nullable', 'string', 'max:24'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:all,active,hidden,sold_out'],
        ]);
        $orderingChannels = $business->orderingChannels()
            ->where('ordering_channels.is_active', true)
            ->wherePivot('is_enabled', true)
            ->orderBy('ordering_channels.sort_order')
            ->get(['ordering_channels.slug', 'ordering_channels.name', 'ordering_channels.description']);
        $channel = (string) ($data['channel'] ?? $orderingChannels->first()?->slug ?? 'dine_in');
        abort_unless($orderingChannels->contains('slug', $channel), HTTP_UNPROCESSABLE_ENTITY, 'The selected ordering channel is not enabled for this business.');
        $term = trim((string) ($data['search'] ?? ''));
        $items = Item::query()->whereHas('category', function ($query) use ($business, $data) {
                $query->where('business_id', $business->id)
                    ->when(isset($data['category_id']), fn ($category) => $category->whereKey($data['category_id']));
            })
            ->when(($data['status'] ?? 'all') === 'active', fn ($query) => $query->where('is_active', true)->where('is_sold_out', false))
            ->when(($data['status'] ?? 'all') === 'hidden', fn ($query) => $query->where('is_active', false))
            ->when(($data['status'] ?? 'all') === 'sold_out', fn ($query) => $query->where('is_sold_out', true))
            ->with(['category:id,uuid,name,is_active', 'discountRules', 'availabilityRules.days', 'optionGroups.options'])
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($term) . '%'])->orWhereHas('category', fn ($category) => $category->whereRaw('LOWER(name) LIKE ?', ['%' . mb_strtolower($term) . '%']))))
            ->orderByDesc('created_at')->paginate($data['per_page'] ?? 10);
        $availability = app(MenuAvailabilityService::class);
        $business->load(['promotions' => fn ($query) => $query->where('is_active', true)]);
        $items->getCollection()->transform(function (Item $item) use ($availability, $business, $channel) {
            $pricing = $availability->itemPricing($item, $business, $channel);
            $status = $availability->itemStatus($item, $business, $channel);
            $rules = $item->availabilityRules->where('is_active', true)->where('channel', $channel)->values();
            $days = $rules->flatMap(fn ($rule) => $rule->days->pluck('day_of_week'))->unique()->sort()->values()->all();
            $firstRule = $rules->first();
            $item->setAttribute('pricing', $pricing + ['channel' => $channel]);
            $item->setAttribute('availability_summary', [
                'has_schedule' => $rules->isNotEmpty(),
                'days' => $days,
                'from_date' => $firstRule?->available_from_date?->toDateString(),
                'to_date' => $firstRule?->available_to_date?->toDateString(),
                'starts_at' => $firstRule?->starts_at,
                'ends_at' => $firstRule?->ends_at,
                'is_available_now' => $status['is_available_now'],
                'reason' => $status['reason'],
            ]);
            return $item;
        });
        return $this->sendResponse([
            'categories' => Category::query()->where('business_id', $business->id)->with('availabilityRules.days')->orderBy('name')->get(['id','uuid','name','is_active']),
            'ordering_channels' => $orderingChannels->values(),
            'items' => $this->paginator($items),
            'promotion' => $business->promotions()->where('is_active', true)->orderByDesc('priority')->first(),
        ], 'Menu retrieved successfully.');
    }

    public function storeCategory(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $data = $request->validate($this->categoryRules());
        $category = Category::query()->create(collect($data)->except('availability')->all() + ['business_id' => $business->id]);
        $this->syncCategoryAvailability($category, $data['availability'] ?? null);
        return $this->sendResponse(['category' => $category], 'Category created successfully.', HTTP_CREATED);
    }

    public function updateCategory(Request $request, Business $business, Category $category)
    {
        abort_unless($category->business_id === $business->id, HTTP_NOT_FOUND);
        $this->authorizeManage($business);
        $data = $request->validate($this->categoryRules(true));
        $category->update(collect($data)->except('availability')->all());
        if (array_key_exists('availability', $data)) $this->syncCategoryAvailability($category, $data['availability']);
        return $this->sendResponse(['category' => $category->fresh()->load('availabilityRules.days')], 'Category updated successfully.');
    }

    public function storeItem(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $data = $this->itemData($request);
        $category = Category::query()->whereKey($data['category_id'])->where('business_id', $business->id)->firstOrFail();
        $discountRules = $data['channel_discounts'] ?? null;
        $availability = $data['availability'] ?? null;
        $options = $data['option_groups'] ?? null;
        unset($data['channel_discounts']);
        unset($data['availability']);
        unset($data['option_groups']);
        $item = Item::query()->create($data + ['category_id' => $category->id, 'final_price' => $this->finalPrice($data)]);
        $this->syncItemDiscounts($item, $discountRules);
        $this->syncItemAvailability($item, $availability);
        $this->syncItemOptions($item, $options);
        return $this->sendResponse(['item' => $item->load('category')], 'Menu item created successfully.', HTTP_CREATED);
    }

    public function updateItem(Request $request, Business $business, Item $item)
    {
        abort_unless($item->category()->where('business_id', $business->id)->exists(), HTTP_NOT_FOUND);
        $this->authorizeManage($business);
        $data = $this->itemData($request, true);
        $discountRules = $data['channel_discounts'] ?? null;
        $availability = $data['availability'] ?? null;
        $options = $data['option_groups'] ?? null;
        unset($data['channel_discounts']);
        unset($data['availability']);
        unset($data['option_groups']);
        if (isset($data['category_id'])) Category::query()->whereKey($data['category_id'])->where('business_id', $business->id)->firstOrFail();
        $merged = array_merge($item->only(['price', 'discount']), $data);
        $item->update($data + ['final_price' => $this->finalPrice($merged)]);
        if ($discountRules !== null) $this->syncItemDiscounts($item, $discountRules);
        if (array_key_exists('availability', $request->all())) $this->syncItemAvailability($item, $availability);
        if (array_key_exists('option_groups', $request->all())) $this->syncItemOptions($item, $options);
        return $this->sendResponse(['item' => $item->fresh()->load('category')], 'Menu item updated successfully.');
    }

    public function storePromotion(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $promotion = $business->promotions()->create($this->promotionData($request) + ['created_by_user_id' => auth()->id(), 'updated_by_user_id' => auth()->id()]);
        $this->auditPromotion($promotion, 'created', null, $promotion->toArray());
        return $this->sendResponse(['promotion' => $promotion], 'Business promotion created successfully.', HTTP_CREATED);
    }

    public function updatePromotion(Request $request, Business $business, BusinessPromotion $promotion)
    {
        abort_unless($promotion->business_id === $business->id, HTTP_NOT_FOUND);
        $this->authorizeManage($business);
        $previous = $promotion->toArray();
        $promotion->update($this->promotionData($request, true) + ['updated_by_user_id' => auth()->id()]);
        $action = $previous['is_active'] ?? false;
        $action = $action !== (bool) $promotion->is_active ? ($promotion->is_active ? 'activated' : 'deactivated') : 'updated';
        $this->auditPromotion($promotion, $action, $previous, $promotion->fresh()->toArray());
        return $this->sendResponse(['promotion' => $promotion->fresh()], 'Business promotion updated successfully.');
    }

    public function promotionHistory(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'in:10,25,50']]);
        $term = trim((string) ($data['search'] ?? ''));
        $history = BusinessPromotionAudit::query()->where('business_id', $business->id)
            ->with(['promotion:id,uuid,name,discount_percentage,is_active,ends_at,created_by_user_id,updated_by_user_id', 'promotion.createdBy:id,name,email', 'promotion.updatedBy:id,name,email', 'user:id,name,email'])
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('action', 'ilike', "%{$term}%")->orWhereHas('promotion', fn ($promotion) => $promotion->where('name', 'ilike', "%{$term}%"))))
            ->latest()->paginate($data['per_page'] ?? 10);
        return $this->sendResponse(['history' => $this->paginator($history)], 'Promotion history retrieved successfully.');
    }

    public function reactivatePromotion(Business $business, BusinessPromotion $promotion)
    {
        abort_unless($promotion->business_id === $business->id, HTTP_NOT_FOUND);
        $this->authorizeManage($business);
        abort_if($promotion->ends_at && $promotion->ends_at->isPast(), HTTP_UNPROCESSABLE_ENTITY, 'This promotion has expired and cannot be reactivated.');
        $previous = $promotion->toArray();
        $promotion->update(['is_active' => true, 'updated_by_user_id' => auth()->id()]);
        $this->auditPromotion($promotion, 'reactivated', $previous, $promotion->fresh()->toArray());
        return $this->sendResponse(['promotion' => $promotion->fresh()], 'Promotion reactivated successfully.');
    }

    private function itemData(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'category_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:categories,id'],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'],
            'description' => [$partial ? 'sometimes' : 'required', 'string', 'max:2000'],
            'price' => [$partial ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'between:0,100'],
            'discount_percentage' => ['nullable', 'numeric', 'between:0,100'],
            'channel_discounts' => ['nullable', 'array'],
            'channel_discounts.*.channel' => ['required_with:channel_discounts', 'distinct', Rule::in(OrderingChannel::activeSlugs())],
            'channel_discounts.*.discount_percentage' => ['required_with:channel_discounts', 'numeric', 'between:0,100'],
            'channel_discounts.*.starts_at' => ['nullable', 'date'],
            'channel_discounts.*.ends_at' => ['nullable', 'date', 'after_or_equal:channel_discounts.*.starts_at'],
            'channel_discounts.*.is_active' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
            'is_sold_out' => ['sometimes', 'boolean'],
            'option_groups' => ['nullable', 'array'],
            'option_groups.*.name' => ['required', 'string', 'max:120'],
            'option_groups.*.selection_type' => ['required', Rule::in(['single', 'multiple'])],
            'option_groups.*.is_required' => ['sometimes', 'boolean'],
            'option_groups.*.min_selections' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'option_groups.*.max_selections' => ['nullable', 'integer', 'min:1', 'max:50'],
            'option_groups.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'option_groups.*.is_active' => ['sometimes', 'boolean'],
            'option_groups.*.options' => ['required', 'array', 'min:1'],
            'option_groups.*.options.*.name' => ['required', 'string', 'max:120'],
            'option_groups.*.options.*.price_adjustment' => ['sometimes', 'numeric', 'min:0'],
            'option_groups.*.options.*.sort_order' => ['sometimes', 'integer', 'min:0'],
            'option_groups.*.options.*.is_active' => ['sometimes', 'boolean'],
            'availability' => ['nullable', 'array'],
            'availability.channel' => ['nullable', Rule::in(OrderingChannel::activeSlugs())],
            'availability.channels' => ['nullable', 'array', 'min:1'],
            'availability.channels.*' => ['distinct', Rule::in(OrderingChannel::activeSlugs())],
            'availability.from_date' => ['nullable', 'date'],
            'availability.to_date' => ['nullable', 'date', 'after_or_equal:availability.from_date'],
            'availability.ends_at' => ['nullable', 'date_format:H:i', 'required_with:availability.starts_at'],
            'availability.starts_at' => ['nullable', 'date_format:H:i', 'required_with:availability.ends_at'],
            'availability.days' => ['nullable', 'array'],
            'availability.days.*' => ['integer', 'between:0,6'],
        ]);
        $percentage = (float) ($data['discount_percentage'] ?? $data['discount'] ?? 0);
        $data['discount_percentage'] = $percentage;
        // Keep the legacy field synchronized while existing clients migrate.
        $data['discount'] = $percentage;
        return $data;
    }

    private function syncItemDiscounts(Item $item, ?array $discountRules): void
    {
        $item->discountRules()->delete();
        foreach ($discountRules ?? [] as $rule) {
            $item->discountRules()->create([
                'channel' => $rule['channel'],
                'discount_percentage' => $rule['discount_percentage'],
                'starts_at' => $rule['starts_at'] ?? null,
                'ends_at' => $rule['ends_at'] ?? null,
                'is_active' => $rule['is_active'] ?? true,
            ]);
        }
    }

    private function syncItemOptions(Item $item, ?array $groups): void
    {
        $item->optionGroups()->with('options')->get()->each(fn ($group) => $group->options()->delete());
        $item->optionGroups()->delete();
        foreach ($groups ?? [] as $groupIndex => $groupData) {
            $group = $item->optionGroups()->create([
                'name' => $groupData['name'],
                'selection_type' => $groupData['selection_type'],
                'is_required' => $groupData['is_required'] ?? false,
                'min_selections' => $groupData['min_selections'] ?? (($groupData['is_required'] ?? false) ? 1 : 0),
                'max_selections' => $groupData['max_selections'] ?? ($groupData['selection_type'] === 'single' ? 1 : null),
                'sort_order' => $groupData['sort_order'] ?? $groupIndex,
                'is_active' => $groupData['is_active'] ?? true,
            ]);
            foreach ($groupData['options'] as $optionIndex => $optionData) {
                $group->options()->create([
                    'name' => $optionData['name'],
                    'price_adjustment' => $optionData['price_adjustment'] ?? 0,
                    'sort_order' => $optionData['sort_order'] ?? $optionIndex,
                    'is_active' => $optionData['is_active'] ?? true,
                ]);
            }
        }
    }

    private function categoryRules(bool $partial = false): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'availability' => ['nullable', 'array'],
            'availability.channel' => ['nullable', Rule::in(OrderingChannel::activeSlugs())],
            'availability.channels' => ['nullable', 'array', 'min:1'],
            'availability.channels.*' => ['distinct', Rule::in(OrderingChannel::activeSlugs())],
            'availability.from_date' => ['nullable', 'date'],
            'availability.to_date' => ['nullable', 'date', 'after_or_equal:availability.from_date'],
            'availability.ends_at' => ['nullable', 'date_format:H:i', 'required_with:availability.starts_at'],
            'availability.starts_at' => ['nullable', 'date_format:H:i', 'required_with:availability.ends_at'],
            'availability.days' => ['nullable', 'array'],
            'availability.days.*' => ['integer', 'between:0,6'],
        ];
    }

    private function promotionData(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'],
            'discount_percentage' => [$partial ? 'sometimes' : 'required', 'numeric', 'between:0,100'],
            'channel' => ['nullable', Rule::in(OrderingChannel::activeSlugs())],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ]);
    }

    private function auditPromotion(BusinessPromotion $promotion, string $action, ?array $previous, ?array $new, ?string $reason = null): void
    {
        BusinessPromotionAudit::query()->create([
            'business_id' => $promotion->business_id,
            'promotion_id' => $promotion->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'previous_values' => $previous,
            'new_values' => $new,
            'reason' => $reason,
        ]);
    }

    private function syncCategoryAvailability(Category $category, ?array $availability): void
    {
        $category->availabilityRules()->delete();
        if (!$availability) return;
        $channels = array_unique($availability['channels'] ?? (isset($availability['channel']) ? [$availability['channel']] : ['dine_in']));
        foreach ($channels as $channel) {
            $rule = $category->availabilityRules()->create([
                'channel' => $channel,
                'available_from_date' => $availability['from_date'] ?? null,
                'available_to_date' => $availability['to_date'] ?? null,
                'starts_at' => $availability['starts_at'] ?? null,
                'ends_at' => $availability['ends_at'] ?? null,
                'is_active' => true,
            ]);
            foreach (array_unique($availability['days'] ?? []) as $day) {
                $rule->days()->create(['day_of_week' => $day]);
            }
        }
    }

    private function syncItemAvailability(Item $item, ?array $availability): void
    {
        $item->availabilityRules()->delete();
        if (!$availability) return;
        $channels = array_unique($availability['channels'] ?? (isset($availability['channel']) ? [$availability['channel']] : ['dine_in']));
        foreach ($channels as $channel) {
            $rule = $item->availabilityRules()->create([
                'channel' => $channel,
                'available_from_date' => $availability['from_date'] ?? null,
                'available_to_date' => $availability['to_date'] ?? null,
                'starts_at' => $availability['starts_at'] ?? null,
                'ends_at' => $availability['ends_at'] ?? null,
                'is_active' => true,
            ]);
            foreach (array_unique($availability['days'] ?? []) as $day) {
                $rule->days()->create(['day_of_week' => $day]);
            }
        }
    }

    private function finalPrice(array $data): float
    {
        $price = (float) ($data['price'] ?? 0);
        $percentage = (float) ($data['discount_percentage'] ?? $data['discount'] ?? 0);
        return round(max(0, $price - ($price * $percentage / 100)), 2);
    }
    private function paginator($paginator): array { return ['data' => $paginator->items(), 'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()]]; }
    private function authorizeManage(Business $business): void { $user = auth()->user(); $membership = $user->vendors()->whereKey($business->vendor_id)->first(); if ($membership?->pivot->is_primary || ($membership?->pivot->is_active && $membership?->pivot->role === 'manager')) return; abort_unless($user->businesses()->whereKey($business->id)->wherePivot('is_active', true)->wherePivot('business_role', 'business_manager')->exists(), HTTP_FORBIDDEN); }
}
