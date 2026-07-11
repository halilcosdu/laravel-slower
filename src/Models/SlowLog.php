<?php

namespace HalilCosdu\Slower\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $is_analyzed
 * @property string $bindings
 * @property string $connection_name
 * @property string $raw_sql
 * @property string $sql
 * @property float $time
 * @property string $connection
 * @property string|null $fingerprint
 * @property int|null $fingerprint_version
 * @property array|null $origin
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
        'fingerprint',
        'fingerprint_version',
        'origin',
    ];

    public function casts(): array
    {
        return [
            'is_analyzed' => 'boolean',
            'bindings' => 'array',
            'time' => 'float',
            'fingerprint_version' => 'integer',
            'origin' => 'array',
        ];
    }
}
