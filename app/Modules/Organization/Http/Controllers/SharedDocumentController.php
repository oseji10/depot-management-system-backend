<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * WhatsApp only lets a web page open a chat with a number and some text; it cannot attach a file. So the PDF the app made
 * (a payslip, a receipt) is stored here behind a long random link, and the WhatsApp message carries that link.
 * The person taps it and the PDF opens. Links stop working after 90 days.
 */
class SharedDocumentController extends Controller
{
    private const DAYS = 90;

    /** Which permission each kind of document needs before it can be shared. */
    private const NEEDS = ['payslip' => 'payroll.view', 'receipt' => 'sales.view', 'invoice' => 'sales.view'];

    public function store(Request $request, AccessScope $access): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(self::NEEDS))],
            'ref_id' => ['nullable', 'integer'],
            'filename' => ['required', 'string', 'max:150'],
            'pdf' => ['required', 'string', 'max:6000000'], // base64, about 4.5 MB
        ]);
        abort_unless($request->user()->can(self::NEEDS[$data['kind']]), 403);

        $bytes = base64_decode(preg_replace('#^data:application/pdf;base64,#', '', $data['pdf']), true);
        abort_unless($bytes !== false && str_starts_with($bytes, '%PDF'), 422, 'That is not a PDF.');

        $storeId = $access->requireStoreId();
        $this->prune($storeId);

        $token = Str::random(40);
        $path = "shared/{$storeId}/{$token}.pdf";
        Storage::disk('local')->put($path, $bytes);

        DB::table('shared_documents')->insert([
            'store_id' => $storeId, 'token' => $token, 'kind' => $data['kind'], 'ref_id' => $data['ref_id'] ?? null,
            'filename' => preg_replace('/[^A-Za-z0-9._-]+/', '-', $data['filename']), 'path' => $path,
            'created_by' => $request->user()->id, 'expires_at' => now()->addDays(self::DAYS), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['url' => route('shared-document', ['token' => $token]), 'expires_in_days' => self::DAYS], 201);
    }

    /** Public: whoever has the link gets the PDF (no sign-in: it is opened from WhatsApp by the staff member or customer). */
    public function show(string $token): Response
    {
        $doc = DB::table('shared_documents')->where('token', $token)->first();
        abort_unless($doc && now()->lt($doc->expires_at) && Storage::disk('local')->exists($doc->path), 404, 'This link has expired. Ask for the document to be sent again.');

        DB::table('shared_documents')->where('id', $doc->id)->increment('views');

        return Storage::disk('local')->response($doc->path, $doc->filename, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$doc->filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function prune(int $storeId): void
    {
        DB::table('shared_documents')->where('store_id', $storeId)->where('expires_at', '<', now())->orderBy('id')->limit(200)->get(['id', 'path'])
            ->each(function ($d) {
                Storage::disk('local')->delete($d->path);
                DB::table('shared_documents')->where('id', $d->id)->delete();
            });
    }
}
