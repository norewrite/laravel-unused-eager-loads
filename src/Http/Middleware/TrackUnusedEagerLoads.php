<?php

namespace NoRewrite\UnusedEagerLoads\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use NoRewrite\UnusedEagerLoads\RelationUsageTracker;
use NoRewrite\UnusedEagerLoads\Reporting\RelationUsageReporter;
use Throwable;

final class TrackUnusedEagerLoads
{
    private RelationUsageTracker $tracker;
    private RelationUsageReporter $reporter;
    private array $config;

    public function __construct(RelationUsageTracker $tracker, RelationUsageReporter $reporter)
    {
        $this->tracker = $tracker;
        $this->reporter = $reporter;
        $this->config = (array) config('unused-eager-loads', []);
    }

    public function handle(Request $request, Closure $next)
    {
        $this->tracker->start();

        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            if ($this->shouldReportResponse($response)) {
                $this->reporter->report($this->tracker, $request);
            }
        } catch (Throwable $reportingException) {
            // Diagnostic instrumentation must never affect the host request.
            report($reportingException);
        } finally {
            $this->tracker->stop();
            $this->tracker->reset();
        }
    }

    private function shouldReportResponse($response): bool
    {
        if ((bool) ($this->config['reporting']['report_on_error_responses'] ?? false)) {
            return true;
        }

        if (is_object($response) && method_exists($response, 'getStatusCode')) {
            return $response->getStatusCode() < 500;
        }

        return true;
    }
}
