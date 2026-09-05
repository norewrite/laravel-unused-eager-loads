<?php

namespace NoRewrite\UnusedEagerLoads\Support;

use Illuminate\Database\Eloquent\Model;

final class ModelState
{
    public Model $model;

    /** @var array<string, RelationState> */
    public array $relations = [];

    /** @var list<ParentLink> */
    public array $parents = [];

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function relation(string $name): RelationState
    {
        if (! isset($this->relations[$name])) {
            $this->relations[$name] = new RelationState($name);
        }

        return $this->relations[$name];
    }

    public function hasRelation(string $name): bool
    {
        return isset($this->relations[$name]);
    }
}
