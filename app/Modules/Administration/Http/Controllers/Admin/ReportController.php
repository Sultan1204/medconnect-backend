<?php

namespace App\Modules\Administration\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Operational reports: aggregate counts and money only, no personal or
 * clinical data. Dates are interpreted as UTC days.
 *
 * @tags Admin - Reports
 */
class ReportController extends Controller
{
    /**
     * Platform overview for a date range (default: last 30 days).
     */
    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $roles = DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->select('roles.name', DB::raw('count(*) as total'))->groupBy('roles.name')->pluck('total', 'name');

        return response()->json(['data' => [
            'range' => $this->rangeOut($from, $to),
            'users_by_role' => $roles,
            'new_users' => DB::table('users')->whereBetween('created_at', [$from, $to])->count(),
            'appointments_by_status' => DB::table('appointments')->whereBetween('starts_at', [$from, $to])
                ->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
            'consultations_completed' => DB::table('consultations')->where('status', 'completed')->whereBetween('updated_at', [$from, $to])->count(),
            'open_complaints' => DB::table('complaints')->whereIn('status', ['open', 'under_review'])->count(),
            'reviews_pending_moderation' => DB::table('reviews')->where('status', 'pending')->count(),
        ]]);
    }

    /**
     * Appointments per day and status.
     */
    public function appointments(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $rows = DB::table('appointments')->whereBetween('starts_at', [$from, $to])
            ->select(DB::raw("to_char(starts_at AT TIME ZONE 'UTC', 'YYYY-MM-DD') as day"), 'status', DB::raw('count(*) as total'))
            ->groupBy('day', 'status')->orderBy('day')->get();

        $days = [];
        foreach ($rows as $row) {
            $days[$row->day][$row->status] = (int) $row->total;
        }

        return response()->json(['data' => [
            'range' => $this->rangeOut($from, $to),
            'days' => collect($days)->map(fn ($by, $day) => ['date' => $day, 'by_status' => $by, 'total' => array_sum($by)])->values(),
        ]]);
    }

    /**
     * Money movement in minor units (SAR): captured, refunded, net, platform commission and payouts.
     */
    public function financial(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $captured = (int) DB::table('payments')->whereBetween('captured_at', [$from, $to])->sum('amount');
        $refunded = (int) DB::table('refunds')->where('status', 'succeeded')->whereBetween('succeeded_at', [$from, $to])->sum('amount');
        $commission = (int) DB::table('settlement_entries')->where('account', 'platform_revenue')
            ->whereIn('type', ['platform_commission', 'commission_reversal'])->whereBetween('created_at', [$from, $to])->sum('amount');
        $payouts = (int) DB::table('settlement_entries')->where('account', 'doctor_payable')
            ->whereIn('type', ['payout', 'payout_reversal'])->whereBetween('created_at', [$from, $to])->sum('amount');

        return response()->json(['data' => [
            'range' => $this->rangeOut($from, $to),
            'currency' => config('medconnect.currency'),
            'captured' => $captured,
            'refunded' => $refunded,
            'net' => $captured - $refunded,
            'platform_commission' => $commission,
            'payouts' => abs($payouts),
        ]]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $f = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);

        $to = isset($f['to']) ? Carbon::parse($f['to'], 'UTC')->endOfDay() : now('UTC')->endOfDay();
        $from = isset($f['from']) ? Carbon::parse($f['from'], 'UTC')->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        abort_if($from->diffInDays($to) > 366, 422, 'The date range cannot exceed 366 days.');

        return [$from, $to];
    }

    /** @return array<string, string> */
    private function rangeOut(Carbon $from, Carbon $to): array
    {
        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
