<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Store;
use App\Support\Tenancy\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** The signed-in user's own business: its logo (shown on receipts). */
class BusinessController extends Controller
{
    public function __construct(private AccessScope $access)
    {
    }

    private function store(): Store
    {
        return Store::findOrFail($this->access->requireStoreId());
    }

    /** The owner renames their own business. The name appears on receipts and in the menu. */
    public function update(Request $request): JsonResponse
    {
        $store = $this->store();

        $store->update($request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]));

        return response()->json($store->only(['id', 'name', 'address', 'phone']));
    }

    /** The browser shrinks the logo and sends it as a data URL (PNG, JPEG or WebP); it is checked as a real image. */
    public function uploadLogo(Request $request): JsonResponse
    {
        $store = $this->store();
        $data = $request->validate(['image' => ['required', 'string', 'max:2000000']]);

        if (! preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $data['image'], $m)) {
            throw ValidationException::withMessages(['image' => 'Choose a PNG, JPEG or WebP picture.']);
        }

        $bytes = base64_decode($m[2], true);
        $info = $bytes ? @getimagesizefromstring($bytes) : false;

        if (! $bytes || ! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw ValidationException::withMessages(['image' => 'That file is not a valid picture.']);
        }

        if (strlen($bytes) > 1_000_000) {
            throw ValidationException::withMessages(['image' => 'The logo is too large. Use one under 1 MB.']);
        }

        $ext = ['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp'][$m[1]];
        $path = "business-logos/{$store->id}/".Str::uuid().".{$ext}";

        Storage::disk('local')->put($path, $bytes);
        $old = $store->logo_path;
        $store->update(['logo_path' => $path]);

        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return response()->json(['logo_url' => $store->fresh()->logo_url]);
    }

    public function removeLogo(): JsonResponse
    {
        $store = $this->store();

        if ($store->logo_path) {
            Storage::disk('local')->delete($store->logo_path);
            $store->update(['logo_path' => null]);
        }

        return response()->json(['logo_url' => null]);
    }

    public function logo()
    {
        $store = $this->store();
        abort_unless($store->logo_path && Storage::disk('local')->exists($store->logo_path), 404);

        return Storage::disk('local')->response($store->logo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
