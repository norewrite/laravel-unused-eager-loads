# No/Rewrite Laravel Unused Eager Loads

A development-time Laravel package that detects Eloquent relationships which were **eager loaded but never consumed by PHP / Blade** during the request.

v1 is intentionally server-side only. There is **no JavaScript instrumentation yet**.

## What v1 tracks

- Eloquent eager loads performed by Laravel's eager-loading pipeline (`with`, model `$with`, `load`, `loadMissing`, nested eager loads).
- Normal PHP / Blade relation property access such as `$boat->serviceLocations`.
- Serialization separately from direct PHP / Blade usage.
- Nested eager-load paths such as `comments.author`.
- Lazy/on-demand loads are ignored.
- Only a relation with **zero direct accesses and zero serialization usage across all tracked instances** is classified as `unused` and warning-eligible.

A relation accessed on only part of a result set is classified as `partial`, not wholly unused. Partial reporting is available but disabled by default.

## Why serialization is separate

If an eager-loaded relation is included in `toArray()`, `toJson()`, or normal Eloquent JSON serialization, v1 cannot know whether browser JavaScript later consumes that relation. Treating it as unused would create a false positive.

Therefore:

- direct PHP / Blade access => `used` / `partial`;
- serialization without direct access => `serialization_only`;
- neither direct access nor serialization => `unused`.

JS consumption tracking is intentionally deferred to a later version.

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13

Laravel 13 itself requires PHP 8.3+. The package keeps PHP 8.1 compatibility so the same package can also support Laravel 10.

## Install

For a local path repository while developing the package:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-unused-eager-loads"
        }
    ]
}
```

Then:

```bash
composer require --dev norewrite/laravel-unused-eager-loads:@dev
php artisan vendor:publish --tag=unused-eager-loads-config
```

Enable it in `.env`:

```dotenv
UNUSED_EAGER_LOADS_ENABLED=true
```

Laravel package discovery registers the service provider automatically.

## Add the tracking trait

The package must intercept Eloquent relation property access. PHP does not provide a safe way for a package to inject that interception into every existing model class at runtime, so the host application's model hierarchy needs the trait.

If your application has a shared base model, add it **once** there:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use NoRewrite\UnusedEagerLoads\Concerns\TracksRelationshipUsage;

abstract class BaseModel extends Model
{
    use TracksRelationshipUsage;
}
```

All models extending `BaseModel` are then instrumented.

If models extend `Illuminate\Database\Eloquent\Model` directly, add the trait to each model you want tracked.

## Configuration

Published config: `config/unused-eager-loads.php`.

Important defaults:

```php
'enabled' => false,

'middleware' => [
    'auto_register' => true,
    'groups' => ['web'],
],

'reporting' => [
    'unused_level' => 'warning',
    'serialization_only_level' => 'info',
    'partial_level' => 'debug',
    'report_serialization_only' => true,
    'report_partial' => false,
    'minimum_loaded' => 1,
    'report_on_error_responses' => false,
],
```

`web` is the default group because v1 is focused on Laravel / Blade requests. You may add another middleware group if you deliberately want to inspect it.

## Example output

Wholly unused:

```text
[unused-eager-loads] Unused eager-loaded relationship: App\Models\Boat::serviceLocations
```

Context contains:

```text
method=GET
path=boats/grid
route=boat.grid
model=App\Models\Boat
root_model=App\Models\Boat
relation=serviceLocations
loaded=5
accessed=0
serialized=0
untouched=5
usage_percent=0.0
serialization_percent=0.0
classification=unused
```

Serialization-only:

```text
[unused-eager-loads] Serialization-only eager-loaded relationship: App\Models\Boat::serviceLocations
```

This is logged at `info` by default, **not warning**.

Nested eager load:

```text
[unused-eager-loads] Unused eager-loaded relationship: App\Models\Article::comments.author
```

The context also contains `model=App\Models\Comment` and `leaf_relation=author`.

## Ignore rules

Patterns use Laravel-style `*` wildcards:

```php
'ignore' => [
    'models' => [
        'App\\Models\\Audit*',
    ],
    'relations' => [
        'pivot',
        'media',
    ],
    'paths' => [
        '*.internalMetadata',
    ],
],
```

`pivot` is ignored by default because Eloquent creates pivot relations internally for many-to-many relationships.

## How it works

1. A terminable request middleware is prepended to the configured group and starts a scoped tracker.
2. The model trait observes `setRelation()` calls.
3. A short backtrace confirms the assignment happened inside Eloquent's eager-loading pipeline, so arbitrary manual `setRelation()` calls are not treated as eager loads.
4. `getRelationValue()` records normal already-loaded relation property access.
5. If an unloaded relation is requested, the tracker enters a lazy-resolution scope. The lazy relation and eager loads caused underneath that lazy request are ignored.
6. `toArray()` marks only Eloquent's arrayable relations as serialized; hidden relations do not get serialization credit.
7. Parent/child model links reconstruct nested paths such as `comments.author`.
8. After the response is sent, the terminable middleware aggregates results and writes warnings/info according to classification. HTTP 5xx responses are skipped by default because an interrupted request cannot prove that a relation was genuinely unused.

## Known v1 boundary

The package detects normal Eloquent property access (`$model->relation`) used by PHP and Blade. Direct low-level reads such as `$model->getRelation('relation')` intentionally are not instrumented in v1 because Eloquent itself uses `getRelation()` internally while matching eager loads; instrumenting it naively creates false positives.

There is no JavaScript payload-consumption tracking in v1. A relation that is serialized but never consumed in the browser will remain `serialization_only` until JS instrumentation is added in a later version.

## Tests

```bash
composer install
composer test
```

The suite covers:

- wholly unused eager loads;
- Blade property access;
- ignored lazy loads;
- serialization-only classification;
- nested eager-load paths;
- partial usage being non-warning;
- hidden relations not receiving false serialization credit.

## Production

This is diagnostic instrumentation. Keep it disabled in normal production traffic unless you are deliberately profiling a controlled environment:

```dotenv
UNUSED_EAGER_LOADS_ENABLED=false
```

The trait is fail-open: if the tracker is unavailable, ordinary Eloquent behavior continues.
