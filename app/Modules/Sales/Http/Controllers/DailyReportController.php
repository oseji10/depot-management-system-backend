<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Reports\DailyReportSender;
use App\Modules\Sales\Reports\DailySalesReport;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Settings for the automatic daily sales report, a test send, and the PDF for any day. */
class DailyReportController extends Controller
{
    public function __construct(private AccessScope $access, private DailyReportSender $sender)
    {
    }

    public function show(): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $members = DB::table('user_stores')->where('store_id', $storeId)->pluck('user_id');

        return response()->json([
            'settings' => collect($this->sender->settings($storeId))->except('last_sent')->all(),
            'last_sent' => $this->sender->settings($storeId)['last_sent'],
            'recipients' => $this->sender->recipients($storeId),
            'pdf_ready' => class_exists(\Dompdf\Dompdf::class),
            'timezone' => DB::table('stores')->where('id', $storeId)->value('timezone') ?: 'Africa/Lagos',
            'roles' => DB::table('roles')->where('store_id', $storeId)->orderBy('name')->get(['id', 'name']),
            'users' => DB::table('users')->whereIn('id', $members)->where('is_active', true)->whereNotNull('email')->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'time' => ['required', 'date_format:H:i'],
            'day' => ['required', 'in:today,yesterday'],
            'owners' => ['required', 'boolean'],
            'user_ids' => ['array', 'max:100'], 'user_ids.*' => ['integer'],
            'role_ids' => ['array', 'max:50'], 'role_ids.*' => ['integer', Rule::exists('roles', 'id')->where('store_id', $storeId)],
            'emails' => ['array', 'max:30'], 'emails.*' => ['email', 'max:150'],
        ]);

        $this->sender->save($storeId, $data);

        return $this->show();
    }

    /** Send the report now: to everyone set up, or only to the person asking (a test). */
    public function send(Request $request): JsonResponse
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'only_me' => ['sometimes', 'boolean']]);

        $to = ($data['only_me'] ?? false) ? [$request->user()->email] : null;
        $sent = $this->sender->send($storeId, $data['date'] ?? null, $to, false);

        abort_if($sent === [], 422, $to ? 'The email could not be sent. Check the mail settings.' : 'Nobody is set to receive the report, or the emails could not be sent.');

        return response()->json(['sent_to' => $sent]);
    }

    /** The PDF for one day (or the HTML version when the PDF library is not installed yet). */
    public function pdf(Request $request, DailySalesReport $report)
    {
        $storeId = $this->access->requireStoreId();
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']]);
        $r = $report->build($storeId, $this->sender->reportDate($storeId, $data['date'] ?? null));
        $pdf = $this->sender->pdf($r);

        return $pdf !== null
            ? response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="daily-sales-report-'.$r['date'].'.pdf"'])
            : response(view('reports.daily-sales', ['r' => $r])->render(), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
