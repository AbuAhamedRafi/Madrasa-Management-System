<?php

namespace App\Services\UserProfile;

use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\AuditTrail\AuditLogger;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ProfileUpdateService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected AuditLogger $auditLogger
    ) {}

    public function updateProfile(
        User $user,
        array $data,
        ?string $ip = null,
        ?string $requestId = null
    ): User {
        // Guard against privilege self-escalation attempt
        if (isset($data['role']) || isset($data['roles']) || isset($data['status']) || isset($data['permissions'])) {
            $this->auditLogger->record(
                'AUTHZ_SELF_ESCALATION_ATTEMPT',
                $user->id,
                $user->id,
                null,
                ['attempted_payload' => array_intersect_key($data, array_flip(['role', 'roles', 'status', 'permissions']))],
                $ip,
                $requestId
            );

            throw new AccessDeniedHttpException('AUTHZ_DENIED: You cannot elevate your own role or status.');
        }

        $beforeState = [
            'name_bn' => $user->name_bn,
            'name_en' => $user->name_en,
            'email' => $user->email,
            'phone' => $user->phone,
            'preferred_locale' => $user->preferred_locale?->value,
        ];

        $fillable = array_intersect_key($data, array_flip([
            'name_bn', 'name_en', 'email', 'phone', 'preferred_locale'
        ]));

        $this->userRepository->update($user, $fillable);

        $this->auditLogger->record(
            'PROFILE_UPDATED',
            $user->id,
            $user->id,
            $beforeState,
            $fillable,
            $ip,
            $requestId
        );

        return $user->fresh(['roles.permissions', 'permissionGrants']);
    }
}

