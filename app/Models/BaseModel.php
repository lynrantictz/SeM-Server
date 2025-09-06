<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BaseModel extends Model
{

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $guarded = ['uuid'];

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return 'uuid';
    }

    /**
     * Boot function for using with User model events.
     */
    protected static function boot()
    {
        parent::boot();

        // Automatically generate UUID
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });

    }
}
