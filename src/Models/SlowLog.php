<?php

namespace HalilCosdu\Slower\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $bindings
 * @property string $connection_name
 * @property string $raw_sql
 * @property string $sql
 * @property int $time
 * @property string $connection
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SlowLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_analyzed',
        'bindings',
        'connection_name',
        'raw_sql',
        'sql',
        'time',
        'connection',
        'recommendation',
    ];

    public function casts(): array
    {
        return [
            'is_analyzed' => 'boolean',
            'bindings' => 'array',
            'time' => 'float',
        ];
    }
}
