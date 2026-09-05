<?php

namespace NoRewrite\UnusedEagerLoads\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use NoRewrite\UnusedEagerLoads\Concerns\TracksRelationshipUsage;

class Author extends Model
{
    use TracksRelationshipUsage;

    protected $guarded = [];
}
