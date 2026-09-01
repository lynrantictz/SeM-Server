<?php

namespace App\Services;

use App\Models\Business\Business;

class StaffCodePrefixService
{
    /**
     * Creates the namespace used for staff login codes, for example MTI-001.
     */
    public function generate(string $businessName): string
    {
        $base = $this->buildBase($businessName);
        $candidate = $base;
        $number = 2;

        while (Business::where('code_prefix', $candidate)->exists()) {
            $suffix = (string) $number++;
            $candidate = substr($base, 0, max(2, 4 - strlen($suffix))) . $suffix;
        }

        return $candidate;
    }

    private function buildBase(string $businessName): string
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($businessName))));
        $initials = implode('', array_map(fn (string $word) => strtoupper($word[0]), $words));
        $base = substr($initials, 0, 4);

        if (strlen($base) < 3 && $words !== []) {
            $first = strtoupper(preg_replace('/[^A-Z]/i', '', $words[0]));
            $base = substr($first . $base, 0, 4);
        }

        return str_pad(strtoupper($base), 3, 'X');
    }
}
