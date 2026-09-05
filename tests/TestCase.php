<?php

namespace NoRewrite\UnusedEagerLoads\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NoRewrite\UnusedEagerLoads\RelationUsageTracker;
use NoRewrite\UnusedEagerLoads\UnusedEagerLoadsServiceProvider;
use NoRewrite\UnusedEagerLoads\Tests\Fixtures\Article;
use NoRewrite\UnusedEagerLoads\Tests\Fixtures\Author;
use NoRewrite\UnusedEagerLoads\Tests\Fixtures\Comment;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [UnusedEagerLoadsServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('unused-eager-loads.enabled', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['view']->addLocation(__DIR__.'/views');
        $this->createSchema();
        $this->seedData();
    }

    protected function tracker(): RelationUsageTracker
    {
        return $this->app->make(RelationUsageTracker::class);
    }

    private function createSchema(): void
    {
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id');
            $table->foreignId('author_id');
            $table->string('body');
            $table->timestamps();
        });
    }

    private function seedData(): void
    {
        $authorA = Author::query()->create(['name' => 'A']);
        $authorB = Author::query()->create(['name' => 'B']);

        $articleA = Article::query()->create(['title' => 'First']);
        $articleB = Article::query()->create(['title' => 'Second']);

        Comment::query()->create([
            'article_id' => $articleA->id,
            'author_id' => $authorA->id,
            'body' => 'One',
        ]);

        Comment::query()->create([
            'article_id' => $articleB->id,
            'author_id' => $authorB->id,
            'body' => 'Two',
        ]);
    }
}
