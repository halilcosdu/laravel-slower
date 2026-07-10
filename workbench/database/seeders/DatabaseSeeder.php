<?php

namespace Workbench\Database\Seeders;

use HalilCosdu\Slower\Models\SlowLog;
use Illuminate\Database\Seeder;
use Illuminate\Database\SQLiteConnection;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $recommendation = <<<'MD'
## Indexing

Add a **composite index** so the filter and sort can be served directly:

```sql
CREATE INDEX idx_orders_status_created ON orders (status, created_at);
```

## Query shape

- Avoid `SELECT *` — select only the columns you actually read.
- Compare *numeric* columns without quotes so the index can be used.
MD;

        $samples = [
            [
                'sql' => 'select * from `orders` where `status` = ? and `created_at` >= ? order by `created_at` desc',
                'raw_sql' => "select * from `orders` where `status` = 'pending' and `created_at` >= '2026-06-01 00:00:00' order by `created_at` desc",
                'bindings' => ['pending', '2026-06-01 00:00:00'],
                'time' => 42180.4,
                'connection' => SQLiteConnection::class,
                'connection_name' => 'sqlite',
                'is_analyzed' => true,
                'recommendation' => $recommendation,
                'created_at' => now()->subMinutes(42),
            ],
            [
                'sql' => 'select count(*) as aggregate from `order_items` inner join `products` on `products`.`id` = `order_items`.`product_id` where `products`.`category_id` = ?',
                'raw_sql' => 'select count(*) as aggregate from `order_items` inner join `products` on `products`.`id` = `order_items`.`product_id` where `products`.`category_id` = 7',
                'bindings' => [7],
                'time' => 28442.0,
                'connection' => SQLiteConnection::class,
                'connection_name' => 'sqlite',
                'is_analyzed' => false,
                'created_at' => now()->subHours(2),
            ],
            [
                'sql' => 'select `users`.*, (select count(*) from `logins` where `logins`.`user_id` = `users`.`id`) as `logins_count` from `users` where `last_seen_at` > ?',
                'raw_sql' => "select `users`.*, (select count(*) from `logins` where `logins`.`user_id` = `users`.`id`) as `logins_count` from `users` where `last_seen_at` > '2026-07-01 00:00:00'",
                'bindings' => ['2026-07-01 00:00:00'],
                'time' => 19305.7,
                'connection' => SQLiteConnection::class,
                'connection_name' => 'sqlite',
                'is_analyzed' => true,
                'recommendation' => $recommendation,
                'created_at' => now()->subHours(5),
            ],
            [
                'sql' => 'select * from "invoices" where "due_on" < ? and "settled_at" is null',
                'raw_sql' => 'select * from "invoices" where "due_on" < \'2026-07-10\' and "settled_at" is null',
                'bindings' => ['2026-07-10'],
                'time' => 15990.2,
                'connection' => SQLiteConnection::class,
                'connection_name' => 'sqlite',
                'is_analyzed' => false,
                'created_at' => now()->subHours(9),
            ],
            [
                'sql' => 'select `product_prices`.* from `product_prices` where `product_id` = ? and `price` = ? and `discount_total` > ?',
                'raw_sql' => "select `product_prices`.* from `product_prices` where `product_id` = '1' and `price` = '0' and `discount_total` > '0'",
                'bindings' => ['1', '0', '0'],
                'time' => 14210.9,
                'connection' => SQLiteConnection::class,
                'connection_name' => 'sqlite',
                'is_analyzed' => true,
                'recommendation' => $recommendation,
                'created_at' => now()->subHours(14),
            ],
            [
                'sql' => 'select * from "audit_events" where "payload" like ? order by "id" desc',
                'raw_sql' => 'select * from "audit_events" where "payload" like \'%refund%\' order by "id" desc',
                'bindings' => ['%refund%'],
                'time' => 12844.1,
                'connection' => SQLiteConnection::class,
                'connection_name' => 'sqlite',
                'is_analyzed' => false,
                'created_at' => now()->subDay(),
            ],
            [
                'sql' => 'update `carts` set `abandoned` = ? where `updated_at` < ?',
                'raw_sql' => "update `carts` set `abandoned` = 1 where `updated_at` < '2026-07-04 00:00:00'",
                'bindings' => [1, '2026-07-04 00:00:00'],
                'time' => 11930.6,
                'connection' => SQLiteConnection::class,
                'connection_name' => 'sqlite',
                'is_analyzed' => false,
                'created_at' => now()->subDays(2),
            ],
            [
                'sql' => 'select distinct `sku` from `warehouse_stock` inner join `warehouses` on `warehouses`.`id` = `warehouse_stock`.`warehouse_id` where `warehouses`.`region` = ? and `quantity` < ?',
                'raw_sql' => "select distinct `sku` from `warehouse_stock` inner join `warehouses` on `warehouses`.`id` = `warehouse_stock`.`warehouse_id` where `warehouses`.`region` = 'eu-west' and `quantity` < 10",
                'bindings' => ['eu-west', 10],
                'time' => 10744.3,
                'connection' => SQLiteConnection::class,
                'connection_name' => 'sqlite',
                'is_analyzed' => true,
                'recommendation' => $recommendation,
                'created_at' => now()->subDays(3),
            ],
        ];

        foreach ($samples as $sample) {
            SlowLog::query()->forceCreate($sample);
        }
    }
}
