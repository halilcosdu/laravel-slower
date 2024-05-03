# Laravel Slower: Optimize Your DB Queries with AI

[![Latest Version on Packagist](https://img.shields.io/packagist/v/halilcosdu/laravel-slower.svg?style=flat-square)](https://packagist.org/packages/halilcosdu/laravel-slower)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/halilcosdu/laravel-slower/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/halilcosdu/laravel-slower/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/halilcosdu/laravel-slower/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/halilcosdu/laravel-slower/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/halilcosdu/laravel-slower.svg?style=flat-square)](https://packagist.org/packages/halilcosdu/laravel-slower)

Laravel Slower is a powerful package designed for Laravel developers who want to enhance the performance of their applications. It intelligently identifies slow database queries and leverages AI to suggest optimal indexing strategies and other performance improvements. Whether you're debugging or routinely monitoring your application, Laravel Slower provides actionable insights to streamline database interactions.

## Installation

You can install the package via composer:

```bash
composer require halilcosdu/laravel-slower
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="slower-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="slower-config"
```

This is the contents of the published config file:

```php
    'enabled' => env('SLOWER_ENABLED', true),
    'threshold' => env('SLOWER_THRESHOLD', 10000),
    'resources' => [
        'table_name' => SlowLog::getTable(),
        'model' => SlowLog::class,
    ],
    'ai_recommendation' => env('SLOWER_AI_RECOMMENDATION', true), // You can completely disable AI, and then the package will only log slow queries to the database.
    'open_ai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'request_timeout' => env('OPENAI_TIMEOUT'),
    ],
    'prompt' => env('SLOWER_PROMPT',"As a distinguished database optimization expert, your expertise is invaluable for refining SQL queries to achieve maximum efficiency. Please examine the SQL statement provided below. Based on your analysis, could you recommend sophisticated indexing techniques or query modifications that could significantly improve performance and scalability?")
];
```

## Usage
You can register the commands with your scheduler.
```php
php artisan slower-clean /*{days=15}  Delete records older than 15 days.*/
php artisan slower-analyze /*Analyze the records where is_analyzed=false*/
```
```php
$model = \HalilCosdu\Slower\Models\SlowLog::first();

\HalilCosdu\Slower\Facades\Slower::analyze($model): Model;

dd($model->raw_sql); /*select count(*) as aggregate from "product_prices" where "product_id" = '1' and "price" = '0' and "discount_total" > '0'*/

dd($model->recommendation);
```
### Example Recommendation
In order to improve database performance and scalability, here are some suggestions below:

1. Indexing: Effective database indexing can significantly speed up query performance. For your query, consider adding a combined (composite) index on `product_id`, `price`, and `discount_total`. This index would work well because the where clause covers all these columns.

```sql
CREATE INDEX idx_product_prices
ON product_prices (product_id, price, discount_total);
```
(Note: The order of the columns in the index might depend on the selectivity of the columns and the data distribution. Therefore, you might have to reorder them depending on your specific situation.)

2. Data Types: Ensure that the values being compared are of appropriate data types. Comparing or converting inappropriate data types at run time will slow down the search. It appears that you're using string comparisons ('1') for `product_id`, `price`, and `discount_total` which are likely numerical columns. Remove the quotes for these where clause conditions.

Updated Query:
```sql
SELECT COUNT(*) AS aggregate
FROM product_prices
WHERE product_id = 1
AND price = 0
AND discount_total > 0;
```
3. ANALYZE: Another practice to improve query performance could be running the `ANALYZE` command. This command collects statistics about the contents of tables in the database, and stores the results in the pg_statistic system catalog. Subsequently, the query planner uses these statistics to help determine the most efficient execution plans for queries.

```sql
ANALYZE product_prices;
```

Remember to periodically maintain your index to keep up with the CRUD operations that could lead to index fragmentation. Depending on your DBMS, you might want to REBUILD or REORGANIZE your indices.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Halil Cosdu](https://github.com/halilcosdu)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
