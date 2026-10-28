<?php

namespace App\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authentication\ActivateAccountRequest;
use App\Http\Requests\Authentication\LoginRequest;
use App\Http\Resources\Authentication\CurrentUserResource;
use App\Repositories\UserRepository;
use App\Services\AuditTrail\AuditLogger;
use App\Services\Authentication\AccountActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function __construct(
        protected UserRepository $userRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function login(LoginRequest $request): Response|JsonResponse
    {
        $throttleKey = Str::transliterate(Str::lower($request->input('login')) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'error' => [
                    'code' => 'RATE_LIMITED',
                    'message' => "অতিরিক্ত লগইন চেষ্টা। অনুগ্রহ করে {$seconds} সেকেন্ড পর পুনরায় চেষ্টা করুন।",
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 429);
        }

        $user = $this->userRepository->findByIdentifier($request->input('login'));

        if (! $user || ! Hash::check($request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            $this->auditLogger->record(
                'AUTH_LOGIN_FAILED',
                null,
                $user?->id,
                null,
                ['identifier' => $request->input('login')],
                $request->ip(),
                $request->attributes->get('request_id')
            );

            // Generic non-enumerating error
            return response()->json([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'ইউজারনেম অথবা পাসওয়ার্ড ভুল হয়েছে।',
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 401);
        }

        if (! $user->status->canSignIn()) {
            return response()->json([
                'error' => [
                    'code' => 'ACCOUNT_DISABLED',
                    'message' => 'আপনার অ্যাকাউন্টটি প্রবেশযোগ্য নয়। অনুগ্রহ করে অ্যাডমিনের সাথে যোগাযোগ করুন।',
                    'request_id' => $request->attributes->get('request_id'),
                ],
            ], 401);
        }

        RateLimiter::clear($throttleKey);

        // Regenerate session to prevent session fixation
        $request->session()->regenerate();
        Auth::guard('web')->login($user, ! $request->boolean('do_not_remember'));

        $this->userRepository->update($user, ['last_login_at' => now()]);

        $this->auditLogger->record(
            'AUTH_LOGIN_SUCCESS',
            $user->id,
            $user->id,
            null,
            null,
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->noContent();
    }

    public function logout(Request $request): Response
    {
        $user = $request->user();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            $this->auditLogger->record(
                'AUTH_LOGOUT',
                $user->id,
                $user->id,
                null,
                null,
                $request->ip(),
                $request->attributes->get('request_id')
            );
        }

        return response()->noContent();
    }
}

