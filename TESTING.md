# Laravel Slower - Comprehensive Test Suite

This document describes the comprehensive test suite for the Laravel Slower package.

## Test Coverage Overview

**Total Tests: 153 test cases across 11 test files**

### Test Files

1. **tests/SlowerTest.php** - Core Slower facade tests (4 tests)
2. **tests/SlowerServiceProviderTest.php** - Service provider and binding normalization tests (7 tests)
3. **tests/ConfigTest.php** - Configuration validation tests (11 tests)
4. **tests/CommandsTest.php** - Basic command structure tests (4 tests)
5. **tests/RecommendationServiceTest.php** - AI recommendation service tests (13 tests)
6. **tests/EdgeCases/SqlParsingEdgeCasesTest.php** - SQL parsing edge cases (13 tests)
7. **tests/Unit/AiDriverTest.php** - AI driver and OpenAI integration tests (20 tests)
8. **tests/Unit/SlowLogModelTest.php** - Model behavior and edge cases (29 tests)
9. **tests/Integration/DatabaseListenerIntegrationTest.php** - Database listener integration tests (18 tests)
10. **tests/Integration/CommandsIntegrationTest.php** - Command integration tests (17 tests)
11. **tests/Feature/SlowerPackageFeatureTest.php** - End-to-end feature tests (17 tests)

## Test Categories

### 1. Unit Tests (66 tests)

#### SlowLog Model Tests (29 tests)
- ✅ Field validation and casting
- ✅ Timestamp handling
- ✅ Mass assignment protection
- ✅ Query scopes
- ✅ Edge cases:
  - Empty bindings
  - Very long SQL queries
  - Special characters (quotes, backslashes, unicode, emojis)
  - Newlines and tabs
  - Zero and very large execution times
  - Complex nested bindings
  - Different database connection types

#### AI Driver Tests (20 tests)
- ✅ OpenAI API integration
- ✅ Response handling
- ✅ Configuration usage
- ✅ Error handling:
  - API rate limits
  - Network timeouts
  - Authentication errors
  - Malformed responses
- ✅ Edge cases:
  - Extremely long messages
  - Unicode characters
  - Empty messages
  - Special JSON characters
  - Newlines and tabs

#### RecommendationService Tests (13 tests)
- ✅ Basic recommendation generation
- ✅ Null AI response handling
- ✅ EXPLAIN ANALYSE integration
- ✅ Multiple table extraction
- ✅ Different query types:
  - UPDATE queries
  - INSERT INTO queries
  - Queries with table aliases
  - Queries with backticks/double quotes
  - Subqueries
  - LEFT/RIGHT/INNER JOINs

#### Service Provider Tests (4 tests)
- ✅ Database listener registration
- ✅ Binding normalization (array, null, string, integer, empty array)

### 2. Integration Tests (35 tests)

#### Database Listener Integration (18 tests)
- ✅ Automatic query capturing
- ✅ Threshold respect
- ✅ EXPLAIN query filtering
- ✅ INSERT query filtering
- ✅ Metadata storage
- ✅ Circular dependency prevention (slow_logs table)
- ✅ Concurrent query handling
- ✅ Binding type normalization
- ✅ Raw SQL with substituted bindings
- ✅ Edge cases:
  - Database connection failures
  - Extremely long queries
  - Special characters
  - Unicode characters
  - NULL bindings
  - Disabled configuration

#### Command Integration (17 tests)
- ✅ AnalyzeQuery command:
  - Successful analysis
  - Multiple record handling
  - Skipping analyzed records
  - Warning when disabled
  - Large batch chunking (1000 records)
  - AI service failure handling
- ✅ SlowLogCleaner command:
  - Deletion by days parameter
  - Default days handling
  - Custom days parameter
  - Zero records handling
  - Large batch chunking
  - String/negative/zero days handling
  - Configuration error handling

### 3. Feature Tests (17 tests)

#### End-to-End Workflows (2 tests)
- ✅ Complete lifecycle: capture → analyze → clean
- ✅ Multiple query lifecycle handling

#### Real-World Query Patterns (5 tests)
- ✅ Complex JOIN queries
- ✅ Aggregate queries (GROUP BY, HAVING)
- ✅ Subqueries
- ✅ LIKE queries
- ✅ ORDER BY with LIMIT

#### Configuration Scenarios (3 tests)
- ✅ Threshold configuration
- ✅ Enable/disable toggling
- ✅ AI recommendation toggling

#### Performance Under Load (2 tests)
- ✅ Rapid consecutive queries (50 queries)
- ✅ Concurrent different query types

#### Edge Case Scenarios (5 tests)
- ✅ NULL values
- ✅ IN clauses
- ✅ BETWEEN clauses
- ✅ Raw SQL expressions
- ✅ Infinite loop prevention

### 4. Edge Case Tests (35 tests)

#### SQL Parsing Edge Cases (13 tests)
- ✅ Empty SQL queries
- ✅ Unicode characters and emojis
- ✅ Newlines and tabs
- ✅ Extremely long queries (1000+ values in IN clause)
- ✅ Special characters (quotes, backslashes, ampersands)
- ✅ Case-insensitive keywords
- ✅ Multiple JOINs
- ✅ UNION queries
- ✅ Complex WHERE conditions
- ✅ GROUP BY and HAVING
- ✅ Table names with schema prefix
- ✅ Table names with numbers
- ✅ Table names with underscores

## Test Execution

### Run All Tests
```bash
composer test
```

### Run Specific Test Files
```bash
vendor/bin/pest tests/Unit/AiDriverTest.php
vendor/bin/pest tests/Integration/DatabaseListenerIntegrationTest.php
vendor/bin/pest tests/Feature/SlowerPackageFeatureTest.php
```

### Run Tests by Category
```bash
# Unit tests only
vendor/bin/pest tests/Unit/

# Integration tests only
vendor/bin/pest tests/Integration/

# Feature tests only
vendor/bin/pest tests/Feature/

# Edge case tests only
vendor/bin/pest tests/EdgeCases/
```

### Run Specific Test
```bash
vendor/bin/pest --filter="handles empty SQL query"
vendor/bin/pest --filter="captures slow queries automatically"
```

### Run with Coverage
```bash
composer test-coverage
```

## Important Notes

### Database Requirements
- Tests require SQLite PHP extension for database operations
- Some integration and feature tests create temporary tables
- All tests clean up after themselves (afterEach hooks)

### Skipped Tests
Some performance tests are skipped by default to reduce test execution time:
- Large batch tests (1500+ records)
- These can be enabled by removing the `->skip()` modifier

### Mock Usage
- AI service calls are mocked to avoid actual API calls
- Database operations use the test database
- No external dependencies required for testing

## Coverage Areas

### ✅ Fully Covered
1. Model behavior and attributes
2. AI driver integration and error handling
3. Database listener functionality
4. Command execution and parameters
5. Configuration handling
6. SQL parsing and table name extraction
7. Binding normalization
8. Error recovery
9. Performance under load
10. Edge cases and special characters

### 🔍 Areas for Future Enhancement
1. Browser/UI tests (N/A - this is a backend package)
2. Multi-database type tests (MySQL, PostgreSQL, SQL Server)
3. Cache layer tests (if caching is added)
4. Performance benchmarks
5. Stress tests with 10,000+ concurrent queries

## Test Quality Metrics

- **Assertion Coverage**: Every test includes meaningful assertions
- **Edge Case Coverage**: Comprehensive edge case testing for all major components
- **Error Handling**: All error scenarios are tested
- **Integration Testing**: Full workflow tests from capture to analysis to cleanup
- **Mock Strategy**: Strategic use of mocks to isolate units while maintaining integration tests
- **Cleanup**: All tests properly clean up database state

## Running Tests in CI/CD

The tests are designed to run in GitHub Actions:

```yaml
- name: Run Tests
  run: composer test

- name: Run Static Analysis
  run: composer analyse

- name: Check Code Style
  run: composer format -- --test
```

## Contributing

When adding new features, please:
1. Add unit tests for the new functionality
2. Add integration tests for workflows
3. Add edge case tests for unusual inputs
4. Update this document with test counts
5. Ensure all tests pass before submitting PR

## Test Philosophy

This test suite follows these principles:
1. **Comprehensive**: Test happy paths, error paths, and edge cases
2. **Isolated**: Unit tests don't depend on external services
3. **Fast**: Most tests run in milliseconds
4. **Readable**: Test names clearly describe what is being tested
5. **Maintainable**: Tests are organized by concern and easy to update
6. **Realistic**: Integration and feature tests use real database operations
