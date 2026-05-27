<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogTopupRequest
{
    private float $startTime;
    private ?array $requestData = null;

    public function handle(Request $request, Closure $next): Response
    {
        $this->startTime = microtime(true);
        $this->requestData = $request->only(['supervisor_id', 'amount']);

        $context = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'supervisor_id' => $this->requestData['supervisor_id'] ?? null,
            'amount' => $this->requestData['amount'] ?? null,
        ];

        Log::info('[TOPUP] Request received', $context);

        try {
            app(ActivityLogService::class)->log('topup.request_received', 'success', $context);
        } catch (\Exception $e) {
            Log::warning('[TOPUP] Failed to log request_received to database', ['error' => $e->getMessage()]);
        }

        $response = $next($request);

        $duration = round((microtime(true) - $this->startTime) * 1000);
        $statusCode = $response->getStatusCode();
        $status = $statusCode < 400 ? 'success' : 'fail';

        $context = [
            'user_id' => $request->user()?->id,
            'status_code' => $statusCode,
            'duration_ms' => $duration,
            'supervisor_id' => $this->requestData['supervisor_id'] ?? null,
            'amount' => $this->requestData['amount'] ?? null,
        ];

        if ($status === 'fail') {
            Log::error('[TOPUP] Request failed', $context);
        } else {
            Log::info('[TOPUP] Request completed', $context);
        }

        try {
            app(ActivityLogService::class)->log('topup.request_completed', $status, $context);
        } catch (\Exception $e) {
            Log::warning('[TOPUP] Failed to log request_completed to database', ['error' => $e->getMessage()]);
        }

        return $response;
    }
}
