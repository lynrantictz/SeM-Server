<?php

namespace App\Models\Order\Trait\Attribute;

trait OrderAttribute
{
    public function getCreatedAtFormattedAttribute()
    {
        return $this->created_at?->format('d M Y H:i');
    }
}
