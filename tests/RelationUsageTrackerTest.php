<?php

namespace NoRewrite\UnusedEagerLoads\Tests;

use NoRewrite\UnusedEagerLoads\Tests\Fixtures\Article;

final class RelationUsageTrackerTest extends TestCase
{
    public function test_unused_eager_load_is_reported_as_unused(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        Article::query()->with('comments')->get();

        $entry = $this->find('comments', $tracker->summary());

        $this->assertSame('unused', $entry['classification']);
        $this->assertSame(2, $entry['loaded']);
        $this->assertSame(0, $entry['accessed']);
        $this->assertSame(0, $entry['serialized']);
        $this->assertSame(2, $entry['untouched']);
    }

    public function test_blade_relation_property_access_counts_as_usage(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        $article = Article::query()->with('comments')->firstOrFail();
        view('article', ['article' => $article])->render();

        $entry = $this->find('comments', $tracker->summary());

        $this->assertSame('used', $entry['classification']);
        $this->assertSame(1, $entry['accessed']);
        $this->assertSame(0, $entry['untouched']);
    }

    public function test_lazy_load_is_ignored(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        $article = Article::query()->firstOrFail();
        $article->comments->count();

        $this->assertSame([], $tracker->summary());
    }

    public function test_serialization_is_tracked_separately_and_is_not_unused(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        $article = Article::query()->with('comments')->firstOrFail();
        $article->toArray();

        $entry = $this->find('comments', $tracker->summary());

        $this->assertSame('serialization_only', $entry['classification']);
        $this->assertSame(0, $entry['accessed']);
        $this->assertSame(1, $entry['serialized']);
        $this->assertSame(0, $entry['untouched']);
    }

    public function test_nested_unused_relation_keeps_full_eager_load_path(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        $article = Article::query()->with('comments.author')->firstOrFail();
        $article->comments->count();

        $comments = $this->find('comments', $tracker->summary());
        $author = $this->find('comments.author', $tracker->summary());

        $this->assertSame('used', $comments['classification']);
        $this->assertSame('unused', $author['classification']);
        $this->assertSame('author', $author['leaf_relation']);
    }

    public function test_relation_used_on_only_some_models_is_partial_not_wholly_unused(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        $articles = Article::query()->with('comments')->orderBy('id')->get();
        $articles->first()->comments->count();

        $entry = $this->find('comments', $tracker->summary());

        $this->assertSame('partial', $entry['classification']);
        $this->assertSame(2, $entry['loaded']);
        $this->assertSame(1, $entry['accessed']);
        $this->assertSame(1, $entry['untouched']);
    }


    public function test_manual_set_relation_is_not_mistaken_for_eager_loading(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        $article = Article::query()->firstOrFail();
        $article->setRelation('comments', collect());

        $this->assertSame([], $tracker->summary());
    }

    public function test_explicit_load_is_tracked_as_eager_loading(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        $article = Article::query()->firstOrFail();
        $article->load('comments');

        $entry = $this->find('comments', $tracker->summary());

        $this->assertSame('unused', $entry['classification']);
        $this->assertSame(1, $entry['loaded']);
    }

    public function test_hidden_relation_is_not_mistaken_for_serialized_usage(): void
    {
        $tracker = $this->tracker();
        $tracker->start();

        $article = Article::query()->with('comments')->firstOrFail();
        $article->makeHidden('comments')->toArray();

        $entry = $this->find('comments', $tracker->summary());

        $this->assertSame('unused', $entry['classification']);
        $this->assertSame(0, $entry['serialized']);
    }

    private function find(string $relation, array $summary): array
    {
        foreach ($summary as $entry) {
            if ($entry['relation'] === $relation) {
                return $entry;
            }
        }

        $this->fail('Relation ['.$relation.'] not found in tracker summary.');
    }
}
