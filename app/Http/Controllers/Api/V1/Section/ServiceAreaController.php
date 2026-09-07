<?php

namespace App\Http\Controllers\Api\V1\Section;

use App\Http\Controllers\Api\BaseController;
use App\Models\Business\Business;
use App\Models\Section\Code;
use App\Models\Section\Section;
use App\Models\Section\ServicePoint;
use App\Models\Section\SubSection;
use App\Services\CodeGenerator;
use Illuminate\Http\Request;

class ServiceAreaController extends BaseController
{
    public function index(Business $business)
    {
        $this->authorizeManage($business);

        return $this->sendResponse([
            'sections' => Section::query()
                ->where('business_id', $business->id)
                ->with([
                    'subs' => fn ($query) => $query->withCount('servicePoints')->orderBy('name'),
                ])
                ->withCount(['servicePoints', 'subs'])
                ->orderBy('name')->get(),
        ], 'Service areas retrieved successfully.');
    }

    public function servicePoints(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'in:10,25,50'],
        ]);
        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));

        $paginator = ServicePoint::query()
            ->where('business_id', $business->id)
            ->select(['id', 'uuid', 'business_id', 'section_id', 'sub_section_id', 'type', 'label', 'display_name', 'capacity', 'is_active', 'created_at'])
            ->with([
                'section:id,uuid,name',
                'subSection:id,uuid,name',
                'activeCode:id,codable_id,codable_type,code,is_active',
            ])
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';
                $query->where(function ($searchQuery) use ($term) {
                    $searchQuery
                        ->whereRaw('LOWER(display_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(label) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(type) LIKE ?', [$term])
                        ->orWhereHas('section', fn ($sectionQuery) => $sectionQuery->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('subSection', fn ($subQuery) => $subQuery->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        return $this->sendResponse([
            'service_points' => $this->paginatorData($paginator),
        ], 'Service points retrieved successfully.');
    }

    public function sections(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'in:10,25,50']]);
        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));
        $paginator = Section::query()
            ->where('business_id', $business->id)
            ->select(['id', 'uuid', 'name', 'is_active', 'created_at'])
            ->withCount(['subs', 'servicePoints'])
            ->when($search !== '', fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%' . $search . '%']))
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();
        return $this->sendResponse(['sections' => $this->paginatorData($paginator)], 'Sections retrieved successfully.');
    }

    public function subSections(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'in:10,25,50']]);
        $search = mb_strtolower(trim((string) ($validated['search'] ?? '')));
        $paginator = SubSection::query()
            ->where('business_id', $business->id)
            ->select(['id', 'uuid', 'section_id', 'name', 'is_active', 'created_at'])
            ->with(['section:id,uuid,name'])
            ->withCount('servicePoints')
            ->when($search !== '', function ($query) use ($search) {
                $term = '%' . $search . '%';
                $query->where(fn ($searchQuery) => $searchQuery->whereRaw('LOWER(name) LIKE ?', [$term])->orWhereHas('section', fn ($sectionQuery) => $sectionQuery->whereRaw('LOWER(name) LIKE ?', [$term])));
            })
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 10)
            ->withQueryString();
        return $this->sendResponse(['sub_sections' => $this->paginatorData($paginator)], 'Subsections retrieved successfully.');
    }

    public function storeSection(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string'], 'is_active' => ['sometimes', 'boolean']]);
        $data['description'] = $data['description'] ?? null;
        $section = Section::query()->create($data + ['business_id' => $business->id, 'user_id' => auth()->id()]);
        return $this->sendResponse(['section' => $section], 'Section created successfully.', HTTP_CREATED);
    }

    public function updateSection(Request $request, Business $business, Section $section)
    {
        $this->authorizeSection($business, $section);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'description' => ['nullable', 'string'], 'is_active' => ['sometimes', 'boolean']]);
        $section->update($data);
        return $this->sendResponse(['section' => $section->fresh()], 'Section updated successfully.');
    }

    public function storeSubSection(Request $request, Business $business, Section $section)
    {
        $this->authorizeSection($business, $section);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string'], 'is_active' => ['sometimes', 'boolean']]);
        $data['description'] = $data['description'] ?? null;
        $subSection = SubSection::query()->create($data + ['business_id' => $business->id, 'section_id' => $section->id, 'user_id' => auth()->id()]);
        return $this->sendResponse(['sub_section' => $subSection], 'Subsection created successfully.', HTTP_CREATED);
    }

    public function updateSubSection(Request $request, Business $business, SubSection $subSection)
    {
        $this->authorizeSubSection($business, $subSection);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'description' => ['nullable', 'string'], 'is_active' => ['sometimes', 'boolean']]);
        $subSection->update($data);
        return $this->sendResponse(['sub_section' => $subSection->fresh()], 'Subsection updated successfully.');
    }

    public function storeServicePoint(Request $request, Business $business)
    {
        $this->authorizeManage($business);
        $data = $this->servicePointData($request, $business);
        $duplicate = ServicePoint::query()
            ->where('business_id', $business->id)
            ->where('section_id', $data['section_id'])
            ->when($data['sub_section_id'] ?? null, fn ($query, $subSectionId) => $query->where('sub_section_id', $subSectionId), fn ($query) => $query->whereNull('sub_section_id'))
            ->where('label', $data['label'])
            ->exists();
        if ($duplicate) {
            return $this->sendError('That service-point label already exists in the selected area. Choose a different label.', ['label' => ['The label has already been used in this area.']], HTTP_UNPROCESSABLE_ENTITY);
        }
        $servicePoint = ServicePoint::query()->create($data);
        $this->createCode($servicePoint);
        return $this->sendResponse(['service_point' => $servicePoint->load(['section', 'subSection', 'activeCode'])], 'Service point and QR code created successfully.', HTTP_CREATED);
    }

    public function updateServicePoint(Request $request, Business $business, ServicePoint $servicePoint)
    {
        abort_unless($servicePoint->business_id === $business->id, HTTP_NOT_FOUND);
        $this->authorizeManage($business);
        $servicePoint->update($this->servicePointData($request, $business, true));
        return $this->sendResponse(['service_point' => $servicePoint->fresh()->load(['section', 'subSection', 'activeCode'])], 'Service point updated successfully.');
    }

    public function rotateCode(Business $business, ServicePoint $servicePoint)
    {
        abort_unless($servicePoint->business_id === $business->id, HTTP_NOT_FOUND);
        $this->authorizeManage($business);
        $servicePoint->codes()->where('is_active', true)->update(['is_active' => false]);
        $code = $this->createCode($servicePoint);
        return $this->sendResponse(['code' => $code], 'A new QR code was created and the previous code was disabled.');
    }

    private function servicePointData(Request $request, Business $business, bool $partial = false): array
    {
        $rules = [
            'section_id' => [$partial ? 'sometimes' : 'required', 'integer', 'exists:sections,id'],
            'sub_section_id' => ['nullable', 'integer', 'exists:sub_sections,id'],
            'type' => [$partial ? 'sometimes' : 'required', 'in:table,room,counter,seat,pickup,zone'],
            'label' => [$partial ? 'sometimes' : 'required', 'string', 'max:80'],
            'display_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:140'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        $data = $request->validate($rules);
        if ($partial && !array_key_exists('section_id', $data)) {
            return $data + ['business_id' => $business->id];
        }
        $sectionId = $data['section_id'] ?? $request->input('section_id');
        $section = Section::query()->whereKey($sectionId)->where('business_id', $business->id)->firstOrFail();
        if (!empty($data['sub_section_id'])) {
            SubSection::query()->whereKey($data['sub_section_id'])->where('section_id', $section->id)->where('business_id', $business->id)->firstOrFail();
        }
        return $data + ['business_id' => $business->id];
    }

    private function createCode(ServicePoint $servicePoint): Code
    {
        return $servicePoint->codes()->create(['code' => app(CodeGenerator::class)->generate(8), 'is_active' => true]);
    }

    private function paginatorData($paginator): array
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function authorizeSection(Business $business, Section $section): void { abort_unless($section->business_id === $business->id, HTTP_NOT_FOUND); $this->authorizeManage($business); }
    private function authorizeSubSection(Business $business, SubSection $subSection): void { abort_unless($subSection->business_id === $business->id, HTTP_NOT_FOUND); $this->authorizeManage($business); }

    private function authorizeManage(Business $business): void
    {
        $user = auth()->user();
        $vendorMembership = $user->vendors()->whereKey($business->vendor_id)->first();
        if ($vendorMembership?->pivot->is_primary || ($vendorMembership?->pivot->is_active && $vendorMembership?->pivot->role === 'manager')) return;
        abort_unless(
            $user->businesses()
                ->whereKey($business->id)
                ->wherePivot('is_active', true)
                ->wherePivot('business_role', 'business_manager')
                ->exists(),
            HTTP_FORBIDDEN,
            'Only vendor owners, active vendor managers, and the assigned business manager can manage service areas.'
        );
    }
}
