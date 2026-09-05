<?php

namespace NoRewrite\UnusedEagerLoads;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use NoRewrite\UnusedEagerLoads\Support\ModelState;
use NoRewrite\UnusedEagerLoads\Support\ParentLink;
use SplObjectStorage;
use Traversable;

final class RelationUsageTracker
{
    /** @var SplObjectStorage<Model, ModelState> */
    private SplObjectStorage $states;

    private bool $active = false;
    private int $lazyDepth = 0;
    private int $serializationDepth = 0;
    private array $ignoredModels;
    private array $ignoredRelations;
    private array $ignoredPaths;
    private int $backtraceFrames;

    public function __construct(array $config = [])
    {
        $this->states = new SplObjectStorage();
        $this->ignoredModels = (array) ($config['ignore']['models'] ?? []);
        $this->ignoredRelations = (array) ($config['ignore']['relations'] ?? ['pivot']);
        $this->ignoredPaths = (array) ($config['ignore']['paths'] ?? []);
        $this->backtraceFrames = max(8, (int) ($config['backtrace_frames'] ?? 32));
    }

    public function start(): void
    {
        $this->reset();
        $this->active = true;
    }

    public function stop(): void
    {
        $this->active = false;
        $this->lazyDepth = 0;
        $this->serializationDepth = 0;
    }

    public function reset(): void
    {
        $this->states = new SplObjectStorage();
        $this->lazyDepth = 0;
        $this->serializationDepth = 0;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function beginLazyResolution(): void
    {
        if ($this->active) {
            $this->lazyDepth++;
        }
    }

    public function endLazyResolution(): void
    {
        if ($this->lazyDepth > 0) {
            $this->lazyDepth--;
        }
    }

    public function beginSerialization(): void
    {
        if ($this->active) {
            $this->serializationDepth++;
        }
    }

    public function endSerialization(): void
    {
        if ($this->serializationDepth > 0) {
            $this->serializationDepth--;
        }
    }

    public function isSerializing(): bool
    {
        return $this->serializationDepth > 0;
    }

    public function shouldTrackEagerAssignment(): bool
    {
        if (! $this->active || $this->lazyDepth > 0) {
            return false;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->backtraceFrames);

        foreach ($trace as $frame) {
            $class = $frame['class'] ?? null;
            $function = $frame['function'] ?? null;

            if ($class === 'Illuminate\\Database\\Eloquent\\Builder'
                && ($function === 'eagerLoadRelation' || $function === 'eagerLoadRelations')) {
                return true;
            }
        }

        return false;
    }

    public function recordEagerLoad(Model $model, string $relation, mixed $value): void
    {
        if (! $this->active || $this->shouldIgnoreModel($model) || $this->matches($relation, $this->ignoredRelations)) {
            return;
        }

        $state = $this->stateFor($model);
        $state->relation($relation);

        foreach ($this->extractModels($value) as $child) {
            if ($child === $model) {
                continue;
            }

            $childState = $this->stateFor($child);
            $this->addParentLink($childState, $state, $relation);
        }
    }

    public function recordAccess(Model $model, string $relation): void
    {
        if (! $this->active || ! isset($this->states[$model])) {
            return;
        }

        $state = $this->states[$model];

        if (! $state->hasRelation($relation)) {
            return;
        }

        if ($this->isSerializing()) {
            $state->relations[$relation]->serialized = true;
            return;
        }

        $state->relations[$relation]->accessed = true;
    }

    public function recordSerialized(Model $model, string $relation): void
    {
        if (! $this->active || ! isset($this->states[$model])) {
            return;
        }

        $state = $this->states[$model];

        if ($state->hasRelation($relation)) {
            $state->relations[$relation]->serialized = true;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summary(): array
    {
        $groups = [];

        foreach ($this->states as $model) {
            $modelState = $this->states[$model];

            foreach ($modelState->relations as $relationName => $relationState) {
                [$rootModel, $path] = $this->canonicalPath($modelState, $relationName);

                if ($this->matches($path, $this->ignoredPaths)) {
                    continue;
                }

                $key = $rootModel.'|'.get_class($model).'|'.$path;

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'root_model' => $rootModel,
                        'model' => get_class($model),
                        'relation' => $path,
                        'leaf_relation' => $relationName,
                        'loaded' => 0,
                        'accessed' => 0,
                        'serialized' => 0,
                        'untouched' => 0,
                    ];
                }

                $groups[$key]['loaded']++;

                if ($relationState->accessed) {
                    $groups[$key]['accessed']++;
                }

                if ($relationState->serialized) {
                    $groups[$key]['serialized']++;
                }

                if (! $relationState->accessed && ! $relationState->serialized) {
                    $groups[$key]['untouched']++;
                }
            }
        }

        foreach ($groups as &$group) {
            $loaded = max(1, $group['loaded']);
            $group['usage_percent'] = round(($group['accessed'] / $loaded) * 100, 1);
            $group['serialization_percent'] = round(($group['serialized'] / $loaded) * 100, 1);
            $group['untouched_percent'] = round(($group['untouched'] / $loaded) * 100, 1);
            $group['classification'] = $this->classification($group);
        }
        unset($group);

        usort($groups, static function (array $left, array $right): int {
            return [$left['root_model'], $left['relation'], $left['model']]
                <=> [$right['root_model'], $right['relation'], $right['model']];
        });

        return array_values($groups);
    }

    private function classification(array $group): string
    {
        if ($group['accessed'] === 0 && $group['serialized'] === 0) {
            return 'unused';
        }

        if ($group['accessed'] === 0 && $group['serialized'] > 0) {
            return 'serialization_only';
        }

        if ($group['untouched'] > 0) {
            return 'partial';
        }

        return 'used';
    }

    private function stateFor(Model $model): ModelState
    {
        if (! isset($this->states[$model])) {
            $this->states[$model] = new ModelState($model);
        }

        return $this->states[$model];
    }

    private function addParentLink(ModelState $child, ModelState $parent, string $relation): void
    {
        foreach ($child->parents as $link) {
            if ($link->parent === $parent && $link->relation === $relation) {
                return;
            }
        }

        $child->parents[] = new ParentLink($parent, $relation);
    }

    /**
     * @return array{0: class-string<Model>, 1: string}
     */
    private function canonicalPath(ModelState $state, string $leafRelation): array
    {
        $paths = $this->pathsTo($state, [], 0);

        if ($paths === []) {
            return [get_class($state->model), $leafRelation];
        }

        $candidates = [];

        foreach ($paths as $path) {
            $relationPath = $path['path'] === '' ? $leafRelation : $path['path'].'.'.$leafRelation;
            $candidates[] = [$path['root'], $relationPath];
        }

        usort($candidates, static function (array $left, array $right): int {
            $leftDepth = substr_count($left[1], '.');
            $rightDepth = substr_count($right[1], '.');

            return [$leftDepth, $left[0], $left[1]] <=> [$rightDepth, $right[0], $right[1]];
        });

        return $candidates[0];
    }

    /**
     * @param array<int, true> $visited
     * @return list<array{root: class-string<Model>, path: string}>
     */
    private function pathsTo(ModelState $state, array $visited, int $depth): array
    {
        $id = spl_object_id($state->model);

        if ($depth > 20 || isset($visited[$id])) {
            return [];
        }

        $visited[$id] = true;

        if ($state->parents === []) {
            return [[
                'root' => get_class($state->model),
                'path' => '',
            ]];
        }

        $paths = [];

        foreach ($state->parents as $link) {
            $parentPaths = $this->pathsTo($link->parent, $visited, $depth + 1);

            foreach ($parentPaths as $parentPath) {
                $paths[] = [
                    'root' => $parentPath['root'],
                    'path' => $parentPath['path'] === ''
                        ? $link->relation
                        : $parentPath['path'].'.'.$link->relation,
                ];
            }
        }

        return $paths;
    }

    /**
     * @return list<Model>
     */
    private function extractModels(mixed $value): array
    {
        if ($value instanceof Model) {
            return [$value];
        }

        if ($value instanceof SupportCollection || $value instanceof Traversable || is_array($value)) {
            $models = [];

            foreach ($value as $item) {
                if ($item instanceof Model) {
                    $models[] = $item;
                }
            }

            return $models;
        }

        return [];
    }

    private function shouldIgnoreModel(Model $model): bool
    {
        return $this->matches(get_class($model), $this->ignoredModels);
    }

    private function matches(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is((string) $pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}
