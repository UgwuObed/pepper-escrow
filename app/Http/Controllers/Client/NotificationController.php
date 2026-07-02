<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Services\NotificationService;
use App\Services\TenantService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    protected TenantService $tenantService;
    protected NotificationService $notificationService;

    public function __construct(TenantService $tenantService, NotificationService $notificationService)
    {
        $this->tenantService = $tenantService;
        $this->notificationService = $notificationService;
    }

    public function logs(Request $request): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $logs = NotificationLog::byMerchant($merchant->id)
                ->when($request->input('event'), fn($q, $e) => $q->byEvent($e))
                ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
                ->orderBy('created_at', 'desc')
                ->paginate($request->input('per_page', 30));

            return response()->json([
                'status' => 'success',
                'data' => $logs->items(),
                'meta' => [
                    'current_page' => $logs->currentPage(),
                    'per_page' => $logs->perPage(),
                    'total' => $logs->total(),
                    'last_page' => $logs->lastPage(),
                ],
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function testWebhook(Request $request): JsonResponse
    {
        try {
            $merchant = $this->tenantService->getMerchantFromRequest($request);
            if (!$merchant) {
                return response()->json(['status' => 'error', 'message' => 'Merchant not found.'], 404);
            }

            $log = $this->notificationService->testWebhook($merchant);

            return response()->json([
                'status' => 'success',
                'data' => $log,
                'message' => $log && $log->status === 'sent'
                    ? 'Webhook delivered successfully.'
                    : 'Webhook delivery failed. Check your endpoint.',
            ]);
        } catch (Exception $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function events(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => NotificationService::EVENTS,
        ]);
    }
}
