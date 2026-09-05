<?php

namespace NoRewrite\UnusedEagerLoads\Tests;

use Illuminate\Http\Request;
use NoRewrite\UnusedEagerLoads\Reporting\RelationUsageReporter;
use NoRewrite\UnusedEagerLoads\Tests\Fixtures\Article;

final class RelationUsageReporterTest extends TestCase
{
    public function test_unused_relation_is_logged_as_warning(): void
    {
        $path = $this->freshLogPath('unused');
        $reporter = $this->fileReporter($path);
        $tracker = $this->tracker();
        $tracker->start();

        Article::query()->with('comments')->get();

        $reporter->report($tracker, Request::create('/articles', 'GET'));
        $log = file_get_contents($path);

        $this->assertStringContainsString('WARNING', strtoupper($log));
        $this->assertStringContainsString('Unused eager-loaded relationship', $log);
        $this->assertStringContainsString('comments', $log);
        $this->assertStringContainsString('"classification":"unused"', $log);
    }

    public function test_serialization_only_relation_is_logged_as_info_not_unused_warning(): void
    {
        $path = $this->freshLogPath('serialization');
        $reporter = $this->fileReporter($path);
        $tracker = $this->tracker();
        $tracker->start();

        $article = Article::query()->with('comments')->firstOrFail();
        $article->toArray();

        $reporter->report($tracker, Request::create('/articles/1', 'GET'));
        $log = file_get_contents($path);

        $this->assertStringContainsString('INFO', strtoupper($log));
        $this->assertStringContainsString('Serialization-only eager-loaded relationship', $log);
        $this->assertStringNotContainsString('Unused eager-loaded relationship:', $log);
        $this->assertStringContainsString('"classification":"serialization_only"', $log);
    }

    public function test_partial_relation_does_not_warn_by_default(): void
    {
        $path = $this->freshLogPath('partial');
        $reporter = $this->fileReporter($path);
        $tracker = $this->tracker();
        $tracker->start();

        $articles = Article::query()->with('comments')->orderBy('id')->get();
        $articles->first()->comments->count();

        $entry = $this->find('comments', $tracker->summary());
        $this->assertSame('partial', $entry['classification']);

        $reporter->report($tracker, Request::create('/articles', 'GET'));

        $this->assertFileDoesNotExist($path);
    }

    private function fileReporter(string $path): RelationUsageReporter
    {
        $channel = 'unused_eager_loads_test_'.md5($path);
        $this->app['config']->set('logging.channels.'.$channel, [
            'driver' => 'single',
            'path' => $path,
            'level' => 'debug',
        ]);

        return new RelationUsageReporter($this->app->make('log'), [
            'reporting' => [
                'channel' => $channel,
                'unused_level' => 'warning',
                'serialization_only_level' => 'info',
                'partial_level' => 'debug',
                'report_serialization_only' => true,
                'report_partial' => false,
                'minimum_loaded' => 1,
            ],
        ]);
    }

    private function freshLogPath(string $suffix): string
    {
        $path = sys_get_temp_dir().'/unused-eager-loads-'.$suffix.'-'.uniqid('', true).'.log';
        @unlink($path);

        return $path;
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
