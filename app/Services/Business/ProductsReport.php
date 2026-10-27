<?php

namespace App\Services\Business;

use App\Models\Business\Business;
use App\Support\ExcelWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ProductsReport
{
    private function prepare(Business $business, array $filters): array
    {
        $business->loadMissing(['timezoneDefinition', 'paymentSetting', 'district.city.country']);
        $timezone = $business->timezoneDefinition?->identifier ?: $business->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone);
        $from = $filters['date_from'] ?? $today->subDays(29)->toDateString();
        $to = $filters['date_to'] ?? $today->toDateString();
        $currency = $business->paymentSetting?->currency ?: $business->district?->city?->country?->currency ?: 'TZS';
        $latest = DB::table('payments')->whereIn('order_id', DB::table('orders')->select('id')->where('business_id', $business->id))->selectRaw('order_id, MAX(id) AS id')->groupBy('order_id');
        $query = DB::table('order_items as lines')->join('orders as o', 'o.id', '=', 'lines.order_id')
            ->leftJoin('items as product', 'product.id', '=', 'lines.item_id')
            ->leftJoin('categories as category', function ($join) use ($business) {
                $join->on('category.id', '=', 'product.category_id')->where('category.business_id', $business->id);
            })
            ->leftJoin('service_points as point', function ($join) use ($business) {
                $join->on('point.id', '=', 'o.service_point_id')->where('point.business_id', $business->id);
            })
            ->leftJoin('sections as area', 'area.id', '=', 'point.section_id')->leftJoin('sub_sections as sub', 'sub.id', '=', 'point.sub_section_id')
            ->leftJoin('order_statuses as os', 'os.id', '=', 'o.order_status_id')->leftJoin('payment_statuses as ps', 'ps.id', '=', 'o.payment_status_id')
            ->leftJoin('ordering_channels as channels', 'channels.slug', '=', 'o.channel')
            ->leftJoin('order_platform_fees as fees', function ($join) use ($business) {
                $join->on('fees.order_id', '=', 'o.id')->where('fees.business_id', $business->id);
            })
            ->leftJoinSub($latest, 'latest_payment', 'latest_payment.order_id', '=', 'o.id')->leftJoin('payments as payment', 'payment.id', '=', 'latest_payment.id')
            ->where('o.business_id', $business->id)->where('o.created_at', '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc())
            ->where('o.created_at', '<', CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->utc())
            ->where(fn ($q) => $q->whereNull('os.name')->orWhereNotIn('os.name', ['Cancelled', 'Canceled', 'Refunded']))
            ->where(fn ($q) => $q->whereNull('ps.name')->orWhereNotIn('ps.name', ['Refunded']));
        foreach (['section_id', 'sub_section_id'] as $field) {
            if (! empty($filters[$field])) {
                $query->where('point.'.$field, $filters[$field]);
            }
        }
        $options = [];
        foreach (['channels' => ['o.channel', 'channels.name'], 'order_statuses' => ['o.order_status_id', 'os.name'], 'payment_statuses' => ['o.payment_status_id', 'ps.name']] as $type => [$id, $name]) {
            $options[$type] = (clone $query)->whereNotNull($id)->select($id.' as value', $name.' as label')->distinct()->orderBy($name)->get();
        }
        if (($filters['sales_scope'] ?? 'confirmed') === 'confirmed') {
            $query->whereIn('os.name', ['Completed', 'Served'])->whereIn('ps.name', ['Completed', 'Paid', 'Successful']);
        }
        foreach (['channel' => 'o.channel', 'order_status_id' => 'o.order_status_id', 'payment_status_id' => 'o.payment_status_id'] as $field => $column) {
            if (! empty($filters[$field])) {
                $query->where($column, $filters[$field]);
            }
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->whereLike('product.name', '%'.$search.'%');
        }
        $areaKey = "CASE WHEN point.id IS NULL AND o.channel = 'online' THEN -1 ELSE COALESCE(point.section_id, 0) END";
        $areaName = "CASE WHEN point.id IS NULL AND o.channel = 'online' THEN 'Online' ELSE COALESCE(area.name, 'Not assigned') END";
        $subKey = 'COALESCE(point.sub_section_id, 0)';
        $subName = "COALESCE(sub.name, 'Not assigned')";
        $currencyExpr = 'COALESCE(fees.currency, payment.currency, '.DB::connection()->getPdo()->quote($currency).')';

        return [$query, $timezone, $from, $to, $options, $areaKey, $areaName, $subKey, $subName, $currencyExpr];
    }

    private function groups($base, $areaKey, $areaName, $subKey, $subName, $currency): array
    {
        $sections = (clone $base)->selectRaw("$areaKey AS section_key, $areaName AS section, $currency AS currency, SUM(lines.quantity) AS quantity, SUM(lines.total_amount) AS revenue, COUNT(DISTINCT o.id) AS orders")
            ->groupByRaw("$areaKey, $areaName, $currency")->get();
        $subsections = (clone $base)->selectRaw("$areaKey AS section_key, $subKey AS subsection_key, $subName AS subsection, $currency AS currency, SUM(lines.quantity) AS quantity, SUM(lines.total_amount) AS revenue, COUNT(DISTINCT o.id) AS orders")
            ->groupByRaw("$areaKey, $subKey, $subName, $currency")->get();

        return ['sections' => $sections, 'subsections' => $subsections];
    }

    private function rows($base, array $filters, $areaKey, $areaName, $subKey, $subName, $currency)
    {
        $query = (clone $base)->selectRaw("$areaKey AS section_key, $areaName AS section, $subKey AS subsection_key, $subName AS subsection, $currency AS currency, lines.item_id AS product_id, COALESCE(product.name, 'Menu item') AS name, COALESCE(category.name, 'Not recorded') AS category, SUM(lines.quantity) AS quantity, SUM(lines.total_amount) AS revenue, SUM(COALESCE(lines.discount_amount, 0)) AS discount_amount, COUNT(DISTINCT o.id) AS orders")
            ->groupByRaw("$areaKey, $areaName, $subKey, $subName, $currency, lines.item_id, product.name, category.name");
        $sort = ['section' => 'section', 'subsection' => 'subsection', 'category' => 'category', 'product' => 'name', 'quantity' => 'quantity', 'orders' => 'orders', 'discount' => 'discount_amount', 'revenue' => 'revenue'][$filters['sort'] ?? 'revenue'];

        return $query->orderBy($sort, $filters['direction'] ?? 'desc')->orderBy('section')->orderBy('section_key')->orderBy('currency')->orderBy('subsection')->orderBy('subsection_key')->orderBy('product_id');
    }

    public function forBusiness(Business $business, array $filters, bool $export = false)
    {
        [$base, $timezone, $from, $to, $options, $areaKey, $areaName, $subKey, $subName, $currency] = $this->prepare($business, $filters);
        $groups = $this->groups($base, $areaKey, $areaName, $subKey, $subName, $currency);
        $query = $this->rows($base, $filters, $areaKey, $areaName, $subKey, $subName, $currency);
        if ($export) {
            $rows = (function () use ($query, $groups, $from, $to, $timezone, $filters) {
                $sections = $groups['sections']->keyBy(fn ($row) => $row->section_key.'|'.$row->currency);
                $subs = $groups['subsections']->keyBy(fn ($row) => $row->section_key.'|'.$row->subsection_key.'|'.$row->currency);
                foreach ($query->lazy(500) as $row) {
                    $section = $sections->get($row->section_key.'|'.$row->currency);
                    $sub = $subs->get($row->section_key.'|'.$row->subsection_key.'|'.$row->currency);
                    yield [$from, $to, $timezone, ($filters['sales_scope'] ?? 'confirmed') === 'confirmed' ? 'Completed and paid' : 'All except cancelled / refunded', $row->section, $row->subsection, $row->category, $row->name, $row->currency, (int) $row->quantity, (int) $row->orders, (float) $row->discount_amount, (float) $row->revenue, (float) $sub->revenue, (float) $section->revenue];
                }
            })();

            return ExcelWorkbook::download('Paperstic-products-'.$from.'-to-'.$to.'.xlsx', ['From (local)', 'To (local)', 'Business time zone', 'Sales scope', 'Section (current)', 'Subsection (current)', 'Category (current)', 'Item (current)', 'Currency', 'Quantity', 'Orders containing item', 'Recorded item discount', ($filters['sales_scope'] ?? 'confirmed') === 'confirmed' ? 'Item revenue after discounts before tax' : 'Item value including unpaid orders after discounts before tax', 'Subsection total (repeated)', 'Section total (repeated)'], $rows);
        }
        $page = $query->paginate($filters['per_page'] ?? 10);

        return ['view' => 'products', 'date_from' => $from, 'date_to' => $to, 'timezone' => $timezone, 'options' => $options, 'groups' => $groups,
            'rows' => ['data' => $page->items(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()]]];
    }
}
