<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\OnboardingPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnboardingPackageController extends BaseController
{
    public function index(): JsonResponse
    {
        $packages = OnboardingPackage::query()
            ->orderBy('sort_order')
            ->orderBy('key')
            ->orderByDesc('version')
            ->get(['id', 'key', 'version', 'name', 'price', 'currency', 'is_active', 'sort_order', 'activated_at', 'deactivated_at']);

        return $this->sendResponse(['packages' => $packages], 'Onboarding package price history retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'in:start,business,premium'],
            'price' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
        ]);

        $package = DB::transaction(function () use ($validated): OnboardingPackage {
            $versions = OnboardingPackage::query()
                ->where('key', $validated['key'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($versions->where('is_active', true) as $activeVersion) {
                $activeVersion->forceFill(['is_active' => false, 'deactivated_at' => $now])->save();
            }

            $names = [
                'start' => 'Paperstic Starter',
                'business' => 'Paperstic Business',
                'premium' => 'Paperstic Premium',
            ];
            $sortOrders = ['start' => 10, 'business' => 20, 'premium' => 30];

            return OnboardingPackage::query()->create([
                'key' => $validated['key'],
                'version' => (int) ($versions->max('version') ?? 0) + 1,
                'name' => $names[$validated['key']],
                'price' => $validated['price'],
                'currency' => 'TZS',
                'is_active' => true,
                'sort_order' => $versions->first()?->sort_order ?? $sortOrders[$validated['key']],
                'activated_at' => $now,
            ]);
        });

        return $this->sendResponse(['package' => $package], 'New onboarding package price version created and activated.', 201);
    }

    public function setActive(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $package = DB::transaction(function () use ($id, $validated): OnboardingPackage {
            $selected = OnboardingPackage::query()->findOrFail($id);
            $versions = OnboardingPackage::query()
                ->where('key', $selected->key)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $package = $versions->firstWhere('id', $selected->id);
            abort_unless($package, 404);
            $now = now();

            if ($validated['is_active']) {
                foreach ($versions->where('is_active', true)->where('id', '!=', $package->id) as $activeVersion) {
                    $activeVersion->forceFill(['is_active' => false, 'deactivated_at' => $now])->save();
                }
                $package->forceFill(['is_active' => true, 'activated_at' => $now, 'deactivated_at' => null])->save();
            } else {
                $package->forceFill(['is_active' => false, 'deactivated_at' => $now])->save();
            }

            return $package->refresh();
        });

        return $this->sendResponse(['package' => $package], $package->is_active ? 'Package price version activated.' : 'Package price version deactivated.');
    }
}
