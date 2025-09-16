<?php

namespace App\Services;

use App\Models\Section\Code;
use Illuminate\Support\Str;

class CodeGenerator
{
    /**
     * Generate a unique code.
     *
     * @param int $length Length of the code.
     * @return string
     */
    public function generate(int $length = 8): string
    {
        // Generate a random alphanumeric code
        $code = Str::upper(Str::random($length));

        // Check if the code already exists
        if ($this->codeExists($code)) {
            // Recursively generate a new code if it exists
            return $this->generate($length);
        }

        return $code;
    }

    /**
     * Check if the code already exists in the `codes` table.
     *
     * @param string $code
     * @return bool
     */
    protected function codeExists(string $code): bool
    {
        return Code::where('code', $code)->exists();
    }
}
