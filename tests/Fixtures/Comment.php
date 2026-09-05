<?php

namespace NoRewrite\UnusedEagerLoads\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use NoRewrite\UnusedEagerLoads\Concerns\TracksRelationshipUsage;

class Comment extends Model
{
    use TracksRelationshipUsage;

    protected $guarded = [];

    public function article()
    {
        return $this->belongsTo(Article::class);
    }

    public function author()
    {
        return $this->belongsTo(Author::class);
    }
}
