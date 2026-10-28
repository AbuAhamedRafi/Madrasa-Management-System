<?php

namespace App\Http\Controllers\Authentication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Authentication\ActivateAccountRequest;
use App\Services\Authentication\AccountActivationService;
use Illuminate\Http\JsonResponse;

class ActivationController extends Controller
{
    public function __construct(
        protected AccountActivationService $activationService
    ) {}

    public function activate(ActivateAccountRequest $request): JsonResponse
    {
        $user = $this->activationService->activate(
            $request->input('token'),
            $request->input('password'),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => [
                'status' => $user->status->value,
                'message' => 'অ্যাকাউন্ট সফলভাবে সক্রিয় করা হয়েছে। অনুগ্রহ করে লগইন করুন।',
            ],
        ]);
    }
}

