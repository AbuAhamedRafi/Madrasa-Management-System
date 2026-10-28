<?php

namespace App\Http\Controllers\UserProfile;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserProfile\ChangePasswordRequest;
use App\Http\Requests\UserProfile\UpdateProfileRequest;
use App\Http\Resources\Authentication\CurrentUserResource;
use App\Services\UserProfile\PasswordChangeService;
use App\Services\UserProfile\ProfileUpdateService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProfileController extends Controller
{
    public function __construct(
        protected ProfileUpdateService $profileUpdateService,
        protected PasswordChangeService $passwordChangeService
    ) {}

    public function me(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($request->user()->load(['roles.permissions', 'permissionGrants']));
    }

    public function update(UpdateProfileRequest $request): CurrentUserResource
    {
        $updatedUser = $this->profileUpdateService->updateProfile(
            $request->user(),
            $request->all(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return new CurrentUserResource($updatedUser);
    }

    public function changePassword(ChangePasswordRequest $request): Response
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        $this->passwordChangeService->changePassword(
            $request->user(),
            $request->input('current_password'),
            $request->input('password'),
            $sessionId,
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->noContent();
    }
}
