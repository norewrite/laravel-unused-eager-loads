# No/Rewrite Laravel Unused Eager Loads

[![Tests](https://github.com/norewrite/laravel-unused-eager-loads/actions/workflows/tests.yml/badge.svg)](https://github.com/norewrite/laravel-unused-eager-loads/actions)
[![Latest Stable Version](https://poser.pugx.org/norewrite/laravel-unused-eager-loads/v/stable)](https://packagist.org/packages/norewrite/laravel-unused-eager-loads)
[![License](https://poser.pugx.org/norewrite/laravel-unused-eager-loads/license)](https://packagist.org/packages/norewrite/laravel-unused-eager-loads)

A development-time Laravel package that detects Eloquent relationships which were **eager loaded but never consumed by PHP / Blade** during the request.

It is designed to find unnecessary eager loading without confusing genuine relation usage, lazy loading, or serialization with waste.

Version 1 is intentionally server-side only. JavaScript consumption tracking is planned for a later version.

## What it detects

The package tracks eager-loaded Eloquent relationships created through normal Laravel mechanisms, including:

* `with()`
* model `$with`
* `load()`
* `loadMissing()`
* nested eager loads such as `comments.author`

It then observes how those relations are used during the request.

A relation can be classified as:

| Classification       | Meaning                                                                   |
| -------------------- | ------------------------------------------------------------------------- |
| `used`               | Every tracked eager-loaded instance was accessed directly by PHP / Blade. |
| `partial`            | Some instances were accessed, but others were not.                        |
| `serialization_only` | The relation was serialized but never accessed directly by PHP / Blade.   |
| `unused`             | The relation was neither accessed nor serialized.                         |

Only genuinely `unused` relations generate warnings by default.

Lazy-loaded relations are ignored.

## Why serialization is tracked separately

Consider:

```php
$boats = Boat::with('serviceLocations')->get();

return response()->json($boats);
```

PHP never directly accesses:

```php
$boat->serviceLocations
```

but the relation is part of the JSON response.

The server cannot know whether browser-side JavaScript later consumes that data.

Reporting this as unused would therefore be misleading.

Instead, the package reports it separately:

```text
[unused-eager-loads] Serialization-only eager-loaded relationship: App\Models\Boat::serviceLocations
```

Serialization-only relationships are logged at `info` level by default and are **not treated as unused warnings**.

JavaScript consumption tracking is intentionally deferred to a later release.

## Requirements

PHP 8.1 or newer.

Supported Laravel versions:

```text
Laravel 10
Laravel 11
Laravel 12
Laravel 13
```

Laravel 13 itself requires a newer PHP version, but the package retains PHP 8.1 compatibility for applications running supported earlier Laravel releases.

## Installation

Install the package as a development dependency:

```bash
composer require --dev norewrite/laravel-unused-eager-loads
```

Laravel package discovery registers the service provider automatically.

Publish the configuration:

```bash
php artisan vendor:publish --tag=unused-eager-loads-config
```

Then enable the detector in your local `.env`:

```dotenv
UNUSED_EAGER_LOADS_ENABLED=true
```

The detector is disabled by default.

## Add the tracking trait

The package needs to observe normal Eloquent relationship property access.

PHP does not provide a safe way for a package to transparently inject this behavior into every existing Eloquent model, so the application's model hierarchy needs to use the supplied trait.

If your application has a shared base model, add it once:

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

All models extending that base model are then tracked.

If your application models extend `Illuminate\Database\Eloquent\Model` directly, add the trait to each model you want the detector to inspect:

```php
use NoRewrite\UnusedEagerLoads\Concerns\TracksRelationshipUsage;

class Boat extends Model
{
    use TracksRelationshipUsage;
}
```

## Basic example

Consider:

```php
$boats = Boat::with('serviceLocations')->get();

return view('boats.index', compact('boats'));
```

If the Blade view never accesses:

```php
$boat->serviceLocations
```

the package reports:

```text
[unused-eager-loads] Unused eager-loaded relationship: App\Models\Boat::serviceLocations
```

The structured log context contains information such as:

```text
method=GET
path=boats
route=boats.index
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

If Blade uses the relationship:

```blade
@foreach ($boat->serviceLocations as $location)
    {{ $location->name }}
@endforeach
```

it is counted as consumed and no unused warning is generated.

## Partial usage

Suppose ten models eagerly load the same relation:

```php
$boats = Boat::with('serviceLocations')->get();
```

but only one model's relationship is accessed.

That relation is classified as:

```text
partial
```

rather than:

```text
unused
```

This avoids claiming that the eager load was wholly unused when some of the loaded data was genuinely consumed.

Partial reporting is disabled by default but can be enabled through configuration.

## Nested eager loads

Nested eager loads are tracked independently.

For example:

```php
$articles = Article::with('comments.author')->get();
```

If Blade uses:

```php
$article->comments
```

but never uses:

```php
$comment->author
```

the package can report:

```text
[unused-eager-loads] Unused eager-loaded relationship: App\Models\Article::comments.author
```

The report retains both the root model and the model owning the nested relationship.

## Lazy loads are ignored

This package is specifically an **unused eager-load detector**.

A relationship loaded on demand:

```php
$post = Post::first();

echo $post->author->name;
```

is therefore not treated as an eager-load candidate.

The detector also suppresses eager loads that happen internally underneath a lazy-loaded relationship, including relationships automatically loaded through a nested model's `$with` property.

## Serialization

Normal Eloquent serialization is tracked separately from PHP / Blade access.

This includes operations such as:

```php
$model->toArray();
$model->toJson();

return response()->json($model);
```

and Blade output such as:

```blade
@json($model)
```

Hidden relationships do not receive serialization credit when Eloquent excludes them from the serialized representation.

## Configuration

The published configuration file is:

```text
config/unused-eager-loads.php
```

Typical reporting defaults are:

```php
'enabled' => env('UNUSED_EAGER_LOADS_ENABLED', false),

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

The package attaches its request tracker to Laravel's HTTP lifecycle automatically when enabled.

HTTP 5xx responses are ignored by default because an interrupted request cannot reliably prove that a relationship would have remained unused.

## Ignore rules

Models, relations, and nested relation paths can be excluded.

Patterns support Laravel-style `*` wildcards.

Example:

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

The `pivot` relation is ignored by default because Eloquent creates pivot relationships internally for many-to-many relationships.

## Logging

Unused relationships are logged at `warning` level by default:

```text
[unused-eager-loads] Unused eager-loaded relationship: App\Models\Boat::serviceLocations
```

Serialization-only relationships default to `info`:

```text
[unused-eager-loads] Serialization-only eager-loaded relationship: App\Models\Boat::serviceLocations
```

Partial relationships default to `debug` when partial reporting is enabled:

```text
[unused-eager-loads] Partially used eager-loaded relationship: App\Models\Boat::serviceLocations
```

Structured context is attached to each report so normal Laravel logging infrastructure can route or process the results.

## How it works

During an enabled HTTP request, the package starts a request-scoped relationship usage tracker.

The model trait observes Eloquent's `setRelation()` calls. A short backtrace is used to determine whether a relation assignment originated from Laravel's eager-loading pipeline rather than an arbitrary manual `setRelation()` call.

When PHP or Blade accesses an already-loaded relationship through normal Eloquent property syntax:

```php
$model->relation
```

the trait records that relationship as consumed.

If an unloaded relationship is requested, the tracker enters a lazy-resolution scope so that the lazy relation itself — and eager loads triggered underneath it — are not treated as original eager-load candidates.

When a model is serialized, the package records only relations that Eloquent actually includes in its arrayable relation output.

Parent/child model links are retained so nested paths such as:

```text
comments.author
```

can be reconstructed.

At the end of the HTTP lifecycle, tracked instances are aggregated and classified before reports are written.

## Known limitations

### Direct `getRelation()` access

Normal Eloquent property access is tracked:

```php
$model->author;
```

Direct low-level access is not currently considered consumption:

```php
$model->getRelation('author');
```

Eloquent itself uses `getRelation()` internally while assembling eager-loaded graphs, so treating every direct call as application consumption would introduce false positives in the opposite direction.

This remains a known v1 boundary.

### JavaScript usage

Version 1 does not instrument browser-side JavaScript.

If an eager-loaded relation is serialized into a response but only JavaScript consumes it, the package reports:

```text
serialization_only
```

rather than:

```text
unused
```

Future JavaScript instrumentation can build on this distinction without changing the server-side eager-load tracking model.

## Testing the package

Clone the repository and install dependencies:

```bash
composer install
```

Run the package test suite:

```bash
composer test
```

or:

```bash
vendor/bin/phpunit --testdox
```

The test suite covers eager-load detection, PHP / Blade access, serialization, hidden relations, partial usage, lazy loading, nested eager loads, manual relation assignment, and reporting behavior.

The package is also tested against a full Laravel integration application containing a broader matrix of real controller, Blade, JSON, nested relationship, `$with`, `load()`, `loadMissing()`, empty relationship, nullable relationship, many-to-many, and error-response scenarios.

## Production usage

This package performs diagnostic instrumentation and is primarily intended for development, testing, staging, and controlled profiling.

Keep it disabled during normal production traffic:

```dotenv
UNUSED_EAGER_LOADS_ENABLED=false
```

The tracking trait is fail-open. If the package tracker is unavailable or disabled, normal Eloquent behavior continues unchanged.

## License

No/Rewrite Laravel Unused Eager Loads is open-source software licensed under the MIT license.
