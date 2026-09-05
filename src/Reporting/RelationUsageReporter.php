<?php

namespace NoRewrite\UnusedEagerLoads\Reporting;

use Illuminate\Http\Request;
use Illuminate\Log\LogManager;
use NoRewrite\UnusedEagerLoads\RelationUsageTracker;

final class RelationUsageReporter
{
    private LogManager $log;
    private array $config;

    public function __construct(LogManager $log, array $config = [])
    {
        $this->log = $log;
        $this->config = $config;
    }

    public function report(RelationUsageTracker $tracker, Request $request): void
    {
        $reporting = (array) ($this->config['reporting'] ?? []);
        $minimumLoaded = max(1, (int) ($reporting['minimum_loaded'] ?? 1));

        foreach ($tracker->summary() as $entry) {
            if ($entry['loaded'] < $minimumLoaded) {
                continue;
            }

            $classification = $entry['classification'];

            if ($classification === 'unused') {
                $this->write(
                    (string) ($reporting['unused_level'] ?? 'warning'),
                    '[unused-eager-loads] Unused eager-loaded relationship: '.$entry['root_model'].'::'.$entry['relation'],
                    $this->context($request, $entry)
                );

                continue;
            }

            if ($classification === 'serialization_only'
                && (bool) ($reporting['report_serialization_only'] ?? true)) {
                $this->write(
                    (string) ($reporting['serialization_only_level'] ?? 'info'),
                    '[unused-eager-loads] Serialization-only eager-loaded relationship: '.$entry['root_model'].'::'.$entry['relation'],
                    $this->context($request, $entry)
                );

                continue;
            }

            if ($classification === 'partial'
                && (bool) ($reporting['report_partial'] ?? false)) {
                $this->write(
                    (string) ($reporting['partial_level'] ?? 'debug'),
                    '[unused-eager-loads] Partially used eager-loaded relationship: '.$entry['root_model'].'::'.$entry['relation'],
                    $this->context($request, $entry)
                );
            }
        }
    }

    private function context(Request $request, array $entry): array
    {
        $route = $request->route();

        return [
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => is_object($route) && method_exists($route, 'getName') ? $route->getName() : null,
            'model' => $entry['model'],
            'root_model' => $entry['root_model'],
            'relation' => $entry['relation'],
            'leaf_relation' => $entry['leaf_relation'],
            'loaded' => $entry['loaded'],
            'accessed' => $entry['accessed'],
            'serialized' => $entry['serialized'],
            'untouched' => $entry['untouched'],
            'usage_percent' => $entry['usage_percent'],
            'serialization_percent' => $entry['serialization_percent'],
            'untouched_percent' => $entry['untouched_percent'],
            'classification' => $entry['classification'],
        ];
    }

    private function write(string $level, string $message, array $context): void
    {
        $channel = $this->config['reporting']['channel'] ?? null;
        $logger = $channel ? $this->log->channel($channel) : $this->log;

        if (! method_exists($logger, $level)) {
            $level = 'warning';
        }

        $logger->{$level}($message, $context);
    }
}
