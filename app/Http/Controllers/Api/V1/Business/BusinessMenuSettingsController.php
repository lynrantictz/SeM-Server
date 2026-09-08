<?php

namespace App\Http\Controllers\Api\V1\Business;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Business\Timezone;
use App\Models\Business\OrderingChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessMenuSettingsController extends BaseController
{
    public function show(Business $business)
    {
        $this->authorizeManage($business);
        return $this->sendResponse(['settings' => $this->settings($business)], 'Menu settings retrieved successfully.');
    }

    public function update(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $data = $request->validate([
            'timezone_id' => ['nullable', 'integer', 'exists:timezones,id'],
            'timezone' => ['nullable', 'timezone'],
            'dine_in_enabled' => ['required', 'boolean'],
            'online_ordering_enabled' => ['required', 'boolean'],
            'pickup_enabled' => ['required', 'boolean'],
            'delivery_enabled' => ['required', 'boolean'],
            'opening_hours' => ['required', 'array', 'min:1'],
            'opening_hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'opening_hours.*.opens_at' => ['nullable', 'date_format:H:i'],
            'opening_hours.*.closes_at' => ['nullable', 'date_format:H:i'],
            'opening_hours.*.is_closed' => ['required', 'boolean'],
        ]);

        $timezone = !empty($data['timezone_id'])
            ? Timezone::query()->where('is_active', true)->find($data['timezone_id'])
            : Timezone::query()->where('is_active', true)->where('identifier', $data['timezone'] ?? null)->first();

        if (!$timezone) {
            return $this->sendError('Please choose an active business timezone.', ['timezone_id' => ['The selected timezone is not available.']], HTTP_UNPROCESSABLE_ENTITY);
        }

        $data['timezone_id'] = $timezone->id;
        $data['timezone'] = $timezone->identifier;

        foreach ($data['opening_hours'] as $hour) {
            if (!$hour['is_closed'] && (!$hour['opens_at'] || !$hour['closes_at'])) {
                return $this->sendError('Each open day needs an opening and closing time.', ['opening_hours' => ['Open days require both times.']], HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        DB::transaction(function () use ($business, $data) {
            $business->update(collect($data)->except('opening_hours')->all());
            $channelIds = OrderingChannel::query()->pluck('id', 'slug');
            foreach ([
                'dine_in' => 'dine_in_enabled',
                'online' => 'online_ordering_enabled',
                'pickup' => 'pickup_enabled',
                'delivery' => 'delivery_enabled',
            ] as $slug => $field) {
                if (isset($channelIds[$slug])) {
                    $business->orderingChannels()->syncWithoutDetaching([$channelIds[$slug] => ['is_enabled' => (bool) $data[$field]]]);
                }
            }
            $business->openingHours()->delete();
            $business->openingHours()->createMany(array_map(fn ($hour, $index) => $hour + ['sort_order' => $index], $data['opening_hours'], array_keys($data['opening_hours'])));
        });

        return $this->sendResponse(['settings' => $this->settings($business->fresh())], 'Menu settings updated successfully.');
    }

    private function settings(Business $business): array
    {
        return [
            'timezone_id' => $business->timezone_id ?: $business->timezoneDefinition?->id,
            'timezone' => $business->timezoneDefinition?->identifier ?: ($business->timezone ?: config('app.timezone', 'UTC')),
            'timezone_option' => $business->timezoneDefinition ? [
                'id' => $business->timezoneDefinition->id,
                'identifier' => $business->timezoneDefinition->identifier,
                'name' => $business->timezoneDefinition->name,
                'group_name' => $business->timezoneDefinition->group?->name,
            ] : null,
            'dine_in_enabled' => (bool) $business->dine_in_enabled,
            'online_ordering_enabled' => (bool) $business->online_ordering_enabled,
            'pickup_enabled' => (bool) $business->pickup_enabled,
            'delivery_enabled' => (bool) $business->delivery_enabled,
            'ordering_channels' => $business->orderingChannels()->where('ordering_channels.is_active', true)->get(['ordering_channels.id', 'ordering_channels.uuid', 'ordering_channels.slug', 'ordering_channels.name', 'ordering_channels.description'])->map(fn ($channel) => [
                'slug' => $channel->slug,
                'name' => $channel->name,
                'description' => $channel->description,
                'is_enabled' => (bool) $channel->pivot->is_enabled,
            ])->values(),
            'opening_hours' => $business->openingHours()
                ->orderBy('day_of_week')
                ->orderBy('sort_order')
                ->get(['day_of_week', 'opens_at', 'closes_at', 'is_closed'])
                ->map(fn ($hour) => [
                    'day_of_week' => $hour->day_of_week,
                    'opens_at' => $hour->opens_at ? substr((string) $hour->opens_at, 0, 5) : null,
                    'closes_at' => $hour->closes_at ? substr((string) $hour->closes_at, 0, 5) : null,
                    'is_closed' => (bool) $hour->is_closed,
                ])
                ->values(),
        ];
    }

    private function authorizeManage(Business $business): void
    {
        $user = auth()->user();
        $membership = $user->vendors()->whereKey($business->vendor_id)->first();
        if ($membership?->pivot->is_primary || ($membership?->pivot->is_active && $membership?->pivot->role === 'manager')) return;
        abort_unless($user->businesses()->whereKey($business->id)->wherePivot('is_active', true)->wherePivot('business_role', 'business_manager')->exists(), HTTP_FORBIDDEN);
    }
}
