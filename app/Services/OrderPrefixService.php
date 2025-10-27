<?php

namespace App\Services;

use App\Models\Business\Business;

class OrderPrefixService
{
    /**
     * Generate a unique order prefix from business name
     *
     * @param string $businessName
     * @param int $length Number of letters to use (default 3)
     * @return string
     */
    public function generate(string $businessName, int $length = 3): string
    {
        $words = preg_split("/\s+/", $businessName);
        $prefix = '';

        // Take first letters of words
        foreach ($words as $word) {
            $prefix .= strtoupper(substr($word, 0, 1));
        }

        // Ensure prefix is at least $length letters
        $prefix = substr($prefix, 0, $length);

        // If already exists, try adding next letters from words
        $uniquePrefix = $prefix;
        $counter = 1;

        while (Business::where('order_prefix', $uniquePrefix)->exists()) {
            $uniquePrefix = $this->extendPrefix($words, $counter, $length);
            $counter++;
        }

        return $uniquePrefix;
    }

    /**
     * Extend prefix by taking next letters from words
     */
    protected function extendPrefix(array $words, int $step, int $length)
    {
        $prefix = '';
        foreach ($words as $word) {
            $prefix .= strtoupper(substr($word, 0, $step + 1)); // take more letters
        }

        return substr($prefix, 0, $length);
    }
}
