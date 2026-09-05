<?php

namespace NoRewrite\UnusedEagerLoads\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use NoRewrite\UnusedEagerLoads\Concerns\TracksRelationshipUsage;

class Article extends Model
{
    use TracksRelationshipUsage;

    protected $guarded = [];

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}
