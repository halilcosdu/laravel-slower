<?php

namespace HalilCosdu\Slower\Database\Factories;

use HalilCosdu\Slower\Models\SlowLog;
use HalilCosdu\Slower\Support\SqlFingerprinter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlowLog>
 */
class SlowLogFactory extends Factory
{
    protected $model = SlowLog::class;

    public function definition(): array
    {
        $sql = 'select * from users where id = ?';

        return [
            'is_analyzed' => false,
            'bindings' => [],
            'sql' => $sql,
            'time' => 15000.0,
            'connection' => 'Illuminate\Database\SQLiteConnection',
            'connection_name' => 'testing',
            'raw_sql' => 'select * from users where id = 1',
            'recommendation' => null,
            'fingerprint' => (new SqlFingerprinter)->fingerprint($sql),
            'fingerprint_version' => SqlFingerprinter::VERSION,
            'origin' => null,
        ];
    }
}
