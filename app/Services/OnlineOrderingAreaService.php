<?php

namespace App\Services;

use App\Models\Business\Business;
use App\Models\Business\OrderingChannel;
use App\Models\Section\Section;
use App\Models\Section\ServicePoint;
use App\Models\Section\SubSection;
use Illuminate\Support\Facades\DB;

class OnlineOrderingAreaService
{
    public function sync(Business $business, bool $enabled, int $userId): ?ServicePoint
    {
        return DB::transaction(function () use ($business, $enabled, $userId): ?ServicePoint {
            $existing = ServicePoint::query()
                ->where('business_id', $business->id)
                ->where('is_system_managed', true)
                ->where('type', 'online')
                ->first();

            if (!$enabled && !$existing) {
                return null;
            }

            $section = Section::query()->firstOrCreate(
                [
                    'business_id' => $business->id,
                    'is_system_managed' => true,
                    'name' => 'Online',
                ],
                [
                    'user_id' => $userId,
                    'description' => "system:online-section:{$business->id}",
                    'is_active' => $enabled,
                ],
            );

            $section->forceFill([
                'is_active' => $enabled,
                'is_system_managed' => true,
            ])->save();

            $subSection = SubSection::query()->firstOrCreate(
                [
                    'business_id' => $business->id,
                    'section_id' => $section->id,
                    'is_system_managed' => true,
                    'name' => 'Online',
                ],
                [
                    'user_id' => $userId,
                    'description' => "system:online-subsection:{$business->id}",
                    'is_active' => $enabled,
                ],
            );

            $subSection->forceFill([
                'is_active' => $enabled,
                'is_system_managed' => true,
            ])->save();

            $servicePoint = ServicePoint::query()->firstOrCreate(
                [
                    'business_id' => $business->id,
                    'is_system_managed' => true,
                    'type' => 'online',
                ],
                [
                    'section_id' => $section->id,
                    'sub_section_id' => $subSection->id,
                    'label' => 'online',
                    'display_name' => 'Online ordering',
                    'is_active' => $enabled,
                ],
            );

            $servicePoint->forceFill([
                'section_id' => $section->id,
                'sub_section_id' => $subSection->id,
                'type' => 'online',
                'label' => 'online',
                'display_name' => 'Online ordering',
                'is_active' => $enabled,
                'is_system_managed' => true,
            ])->save();

            $onlineChannel = OrderingChannel::query()->where('slug', 'online')->firstOrFail();
            $servicePoint->orderingChannels()->sync([$onlineChannel->id]);

            $code = $servicePoint->codes()->first();
            if (!$code) {
                $code = $servicePoint->codes()->create([
                    'code' => app(CodeGenerator::class)->generate(8),
                    'is_active' => $enabled,
                ]);
            } else {
                $code->update(['is_active' => $enabled]);
            }

            return $servicePoint->fresh(['section', 'subSection', 'activeCode', 'orderingChannels']);
        });
    }
}
