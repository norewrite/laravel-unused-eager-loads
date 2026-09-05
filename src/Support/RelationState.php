<?php

namespace NoRewrite\UnusedEagerLoads\Support;

final class RelationState
{
    public string $name;
    public bool $accessed = false;
    public bool $serialized = false;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
