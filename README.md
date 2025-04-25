# Laravel Redis Sentinel Retry

[![Tests](https://github.com/ignashevroman/laravel-redis-sentinel-retry/workflows/Tests/badge.svg)](https://github.com/ignashevroman/laravel-redis-sentinel-retry/actions?query=workflow%3ATests)
[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=ignashevroman_laravel-redis-sentinel-retry&metric=alert_status)](https://sonarcloud.io/dashboard?id=ignashevroman_laravel-redis-sentinel-retry)
[![Maintainability Rating](https://sonarcloud.io/api/project_badges/measure?project=ignashevroman_laravel-redis-sentinel-retry&metric=sqale_rating)](https://sonarcloud.io/dashboard?id=ignashevroman_laravel-redis-sentinel-retry)
[![Reliability Rating](https://sonarcloud.io/api/project_badges/measure?project=ignashevroman_laravel-redis-sentinel-retry&metric=reliability_rating)](https://sonarcloud.io/dashboard?id=ignashevroman_laravel-redis-sentinel-retry)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=ignashevroman_laravel-redis-sentinel-retry&metric=security_rating)](https://sonarcloud.io/dashboard?id=ignashevroman_laravel-redis-sentinel-retry)
[![Vulnerabilities](https://sonarcloud.io/api/project_badges/measure?project=ignashevroman_laravel-redis-sentinel-retry&metric=vulnerabilities)](https://sonarcloud.io/dashboard?id=ignashevroman_laravel-redis-sentinel-retry)
[![codecov](https://codecov.io/gh/ignashevroman/laravel-redis-sentinel-retry/branch/main/graph/badge.svg?token=Z3DASZGD6M)](https://codecov.io/gh/ignashevroman/laravel-redis-sentinel-retry)

## Overview

This package extends the standard Redis integration in Laravel, enabling it to work with Redis Sentinel while providing automatic failover handling and retry logic for transient errors.

It is based on the [Namoshek/laravel-redis-sentinel](https://github.com/Namoshek/laravel-redis-sentinel) package, with enhancements for retries and failover handling.

### Features:
- Connects to Redis through Sentinel.
- Retries on connection failure and failover.
- Uses `PhpRedis` extension for Redis connections.
- Simple configuration with Laravel built-in Redis system.

### Differences in Implementation:

1. **Retry Logic (Retries)**
    - This implementation introduces **retry logic** to handle transient errors such as **temporary connection failures** or **readonly states**. This allows automatic retries of requests if Redis becomes operational again after a failure.
    - In the case of an error like "READONLY" or a connection loss, the client will automatically retry the connection multiple times, improving the system's resilience.

2. **Integration with Redis Sentinel:**
    - This implementation enhances the **automatic failover mechanism** with Redis Sentinel. When the primary Redis server fails, the system automatically switches to a new master, if possible.
    - The **new master** is selected via **Redis Sentinel**, and if the current client loses connection or becomes "readonly", it tries to reconnect to the new master, taking into account settings for **retry attempts and delays**.

3. **Configuration Parameters:**
    - Two new configuration parameters were added to this package:
        - `sentinel_max_retries` — the maximum number of reconnection attempts.
        - `sentinel_retry_delay` — the delay between reconnection attempts.
    - These parameters provide flexibility in configuring the number of retries and delays for reconnection in case of failures.

4. **RedisWrapper Reconnection Logic:**
    - The **RedisWrapper** class manages the Redis client and encapsulates the retry logic, error checking, and failover handling.
    - If the connection is lost or becomes readonly, RedisWrapper automatically retries the connection for a set number of attempts, improving Redis availability.

5. **Error Handling in RedisWrapper:**
    - When an error occurs, such as a lost connection or readonly error, RedisWrapper attempts to **reconnect** through **handleFailover** (a method that manages reconnection).
    - If Redis does not recover after several attempts, an exception is thrown, just like in a standard Redis error scenario.

### Technical Implementation:
1. **`__call()` Method in RedisWrapper:**
    - The `__call()` method handles Redis client method calls. It includes retry logic: if an error occurs, it checks if Redis can reconnect using the `handleFailover` method. If this method returns `true`, the client will attempt the operation again.

2. **PhpRedisSentinelConnection Class:**
    - A class that interacts with Redis Sentinel. It extends the standard Redis connection to add support for Sentinel and the ability to restart the connection in the event of errors or failover situations.

3. **Automatic Redis Master Failover:**
    - When the current Redis server becomes unavailable, the system automatically switches to the new server designated by Sentinel, without user intervention.

In general, this implementation focuses on improving fault tolerance and automating Redis connection management with Redis Sentinel, along with additional settings for controlling retry attempts and delay between reconnections.

## Configuration

In your `config/database.php`, add a new connection entry under the `redis` array, which uses the `phpredis-sentinel` client.

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis-sentinel'),

    'default' => [
        'sentinel_host' => env('REDIS_SENTINEL_HOST', '127.0.0.1'),
        'sentinel_port' => (int) env('REDIS_SENTINEL_PORT', 26379),
        'sentinel_service' => env('REDIS_SENTINEL_SERVICE', 'mymaster'),
        'sentinel_timeout' => (float) env('REDIS_SENTINEL_TIMEOUT', 0),
        'sentinel_persistent' => env('REDIS_SENTINEL_PERSISTENT'),
        'sentinel_retry_interval' => (int) env('REDIS_SENTINEL_RETRY_INTERVAL', 0),
        'sentinel_read_timeout' => (float) env('REDIS_SENTINEL_READ_TIMEOUT', 0),
        'sentinel_username' => env('REDIS_SENTINEL_USERNAME'),
        'sentinel_password' => env('REDIS_SENTINEL_PASSWORD'),
        'password' => env('REDIS_PASSWORD'),
        'database' => (int) env('REDIS_DB', 0),
        
        // Additional options for retry functionality
        'sentinel_max_retries' => env('REDIS_SENTINEL_MAX_RETRIES', 3),
        'sentinel_retry_delay' => env('REDIS_SENTINEL_RETRY_DELAY', 100_000),
    ]
],
```

> **Note:** The configuration options are the same as in [namoshek/laravel-redis-sentinel](https://github.com/Namoshek/laravel-redis-sentinel), with two additional options for retry functionality:
- `sentinel_max_retries`: The number of retry attempts for Redis operations (default: 3).
- `sentinel_retry_delay`: The delay between retries in microseconds (default: 100,000).

## License

This package is licensed under the MIT License. See the [LICENSE](LICENSE.md) file for more information.

## Acknowledgments

A special thanks to the Namoshek team for their excellent work on the original [laravel-redis-sentinel](https://github.com/Namoshek/laravel-redis-sentinel) package, which served as the foundation for this package. This package extends their work by adding automatic retry functionality for Redis operations during failover scenarios.
