<?php

namespace Workbench\App\AiServiceDrivers;

use HalilCosdu\Slower\AiServiceDrivers\Contracts\AiServiceDriver;

class FakeAiDriver implements AiServiceDriver
{
    public function analyze(string $userMessage): ?string
    {
        return <<<'MD'
## Indexing

Add a **composite index** covering the columns used in the `WHERE` clause:

```sql
CREATE INDEX idx_orders_status_created ON orders (status, created_at);
```

## Query shape

- Avoid `SELECT *` — select only the columns you read.
- Compare *numeric* columns without quotes so the index can be used.
- Consider paginating with a keyset (`WHERE id > ?`) instead of a large `OFFSET`.
MD;
    }
}
