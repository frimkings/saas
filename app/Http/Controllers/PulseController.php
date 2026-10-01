<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Doctor\ClearanceNoticeController;
use App\Models\AppNotification;
use App\Models\StaffMessage;
use App\Services\ClinicAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything an open page polls for, in one request: the navbar badge counts plus the
 * notices this user handles. Pages ask only for the parts they show (?parts=a,b).
 * Polls don't count as user activity, so an idle tab still logs out (LogoutInactiveUsers).
 */
class PulseController extends Controller
{
    private const PARTS = ['notifications', 'messages', 'discount', 'clearance'];

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $parts = array_intersect(explode(',', (string) $request->query('parts', '')), self::PARTS);
        $data = [];

        if (in_array('notifications', $parts, true)) {
            $data['notifications'] = AppNotification::forUser($user->id)->unread()->count();
        }
        if (in_array('messages', $parts, true)) {
            $data['messages'] = StaffMessage::where('recipient_id', $user->id)->whereNull('read_at')->count();
        }

        // A locked clinic's staff are shut out of these pages, so they get no notices either.
        $wantsNotices = array_intersect($parts, ['discount', 'clearance']) !== [];
        if ($wantsNotices && ! app(ClinicAccessService::class)->stage()?->blocksStaff()) {
            if (in_array('discount', $parts, true) && DiscountApprovalNoticeController::mayPoll($user)) {
                $data['discount'] = app(DiscountApprovalNoticeController::class)->pendingNotice();
            }
            if (in_array('clearance', $parts, true) && ClearanceNoticeController::mayPoll($user)) {
                $data['clearance'] = app(ClearanceNoticeController::class)->notice();
            }
        }

        return response()->json($data);
    }
}
