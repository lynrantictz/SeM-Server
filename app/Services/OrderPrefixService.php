<?php

namespace App\Services;

use App\Models\Business\Business;

class OrderPrefixService
{
    /**
     * Generate a unique order prefix from a business name.
     *
     * Strategy:
     *  1. Take the first letter of each word → up to 4 chars (min 3).
     *  2. If too short, pad with more letters from the first word.
     *  3. If the candidate already exists, try numeric suffixes (e.g. LHT2, LHT3…).
     *
     * @param  string  $businessName
     * @return string  3–4 uppercase chars, guaranteed unique in businesses table
     */
    public function generate(string $businessName): string
    {
        $base = $this->buildBase($businessName);

        // Try the base first, then base+N until unique
        $candidate = $base;
        $n = 2;

        while (Business::where('order_prefix', $candidate)->exists()) {
            // Append numeric suffix; if base is already 4 chars, replace last char with digit
            $suffix  = (string) $n;
            $trimmed = substr($base, 0, max(2, 4 - strlen($suffix)));
            $candidate = $trimmed . $suffix;
            $n++;

            // Safety valve: if we somehow loop forever, add timestamp fragment
            if ($n > 999) {
                $candidate = strtoupper(substr(uniqid(), -4));
                if (!Business::where('order_prefix', $candidate)->exists()) {
                    break;
                }
            }
        }

        return $candidate;
    }

    /**
     * Build the initial 3-4 char base from the business name.
     */
    protected function buildBase(string $businessName): string
    {
        // Sanitise: letters only, split into words
        $words = preg_split('/\s+/', trim($businessName));
        $words = array_filter($words, fn($w) => strlen($w) > 0);
        $words = array_values($words);

        // Step 1: initials of each word, max 4 chars
        $initials = implode('', array_map(fn($w) => strtoupper($w[0]), $words));
        $base = substr($initials, 0, 4);

        // Step 2: if less than 3 chars, pad from letters of the first word
        if (strlen($base) < 3 && count($words) > 0) {
            $first = strtoupper(preg_replace('/[^A-Z]/i', '', $words[0]));
            $base  = substr($first . $base, 0, max(3, strlen($base)));
            $base  = substr($base, 0, 4);
        }

        // Ensure at least 3 uppercase chars
        $base = str_pad(strtoupper($base), 3, 'X');

        return $base;
    }
}
