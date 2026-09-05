<?php

namespace NoRewrite\UnusedEagerLoads\Concerns;

use Illuminate\Container\Container;
use NoRewrite\UnusedEagerLoads\RelationUsageTracker;
use Throwable;

trait TracksRelationshipUsage
{
    public function getRelationValue($key)
    {
        $tracker = $this->unusedEagerLoadsTracker();

        if ($tracker === null || ! $tracker->isActive()) {
            return parent::getRelationValue($key);
        }

        if ($this->relationLoaded($key)) {
            $tracker->recordAccess($this, (string) $key);

            return parent::getRelationValue($key);
        }

        // Anything resolved because application code asked for an unloaded
        // relation is lazy/on-demand work. v1 intentionally ignores it,
        // including nested eager loads triggered while resolving that request.
        $tracker->beginLazyResolution();

        try {
            return parent::getRelationValue($key);
        } finally {
            $tracker->endLazyResolution();
        }
    }

    public function setRelation($relation, $value)
    {
        $result = parent::setRelation($relation, $value);
        $tracker = $this->unusedEagerLoadsTracker();

        if ($tracker !== null
            && $tracker->isActive()
            && $tracker->shouldTrackEagerAssignment()) {
            $tracker->recordEagerLoad($this, (string) $relation, $value);
        }

        return $result;
    }

    public function toArray()
    {
        $tracker = $this->unusedEagerLoadsTracker();

        if ($tracker === null || ! $tracker->isActive()) {
            return parent::toArray();
        }

        $tracker->beginSerialization();

        try {
            // Only relations that Eloquent itself considers arrayable count as
            // serialized. Hidden relations therefore remain genuinely unused.
            foreach (array_keys($this->getArrayableRelations()) as $relation) {
                $tracker->recordSerialized($this, (string) $relation);
            }

            return parent::toArray();
        } finally {
            $tracker->endSerialization();
        }
    }

    private function unusedEagerLoadsTracker(): ?RelationUsageTracker
    {
        try {
            $container = Container::getInstance();

            if (! $container->bound(RelationUsageTracker::class)) {
                return null;
            }

            return $container->make(RelationUsageTracker::class);
        } catch (Throwable) {
            // Instrumentation must never break the host application.
            return null;
        }
    }
}
