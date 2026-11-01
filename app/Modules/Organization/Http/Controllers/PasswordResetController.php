<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** Forgot / reset password by email. The answer to "forgot" is always the same, so nobody can discover which emails have accounts. */
class PasswordResetController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data['email'])->where('is_active', true)->first();

        if ($user) {
            try {
                $token = Password::broker()->createToken($user);
                $url = config('depot.frontend_url').'/reset-password/?token='.$token.'&email='.urlencode($user->email);
                Mail::to($user->email)->queue(new PasswordResetMail($user->name, $url, (int) config('auth.passwords.users.expire', 60)));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => 'If that email has an account, a reset link is on its way.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker()->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save(); // hashed by the model cast
            $user->tokens()->delete();                           // sign out every device
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'This reset link is invalid or has expired. Ask for a new one.', 'errors' => ['email' => ['This reset link is invalid or has expired.']]], 422);
        }

        return response()->json(['message' => 'Your password has been changed. You can sign in now.']);
    }
}
