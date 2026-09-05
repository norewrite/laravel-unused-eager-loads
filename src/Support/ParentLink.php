<?php

namespace NoRewrite\UnusedEagerLoads\Support;

final class ParentLink
{
    public ModelState $parent;
    public string $relation;

    public function __construct(ModelState $parent, string $relation)
    {
        $this->parent = $parent;
        $this->relation = $relation;
    }
}
