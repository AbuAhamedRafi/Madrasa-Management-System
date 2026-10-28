<?php

namespace App\Services\UserManagement;

use App\Enums\AccountStatus;
use App\Enums\SupportedLocale;
use App\Models\Permission;
use App\Models\User;
use App\Repositories\IdSequenceRepository;
use App\Repositories\RolePermissionRepository;
use App\Repositories\UserRepository;
use App\Services\AuditTrail\AuditLogger;
use App\Services\Authentication\InvitationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountProvisioner
{
    public function __construct(
        protected UserRepository $userRepository,
        protected IdSequenceRepository $idSequenceRepository,
        protected RolePermissionRepository $rolePermissionRepository,
        protected InvitationService $invitationService,
        protected AuditLogger $auditLogger
    ) {}

    public function provision(array $data, ?User $actor = null, ?string $ip = null, ?string $requestId = null): array
    {
        $role = $this->rolePermissionRepository->findRoleByKey($data['role']);
        if (! $role) {
            throw ValidationException::withMessages(['role' => ['Selected role does not exist.']]);
        }

        return DB::transaction(function () use ($data, $role, $actor, $ip, $requestId) {
            $publicId = $this->idSequenceRepository->nextPublicId('user_public_id', 'usr_', 3);

            $user = $this->userRepository->create([
                'public_id' => $publicId,
                'name_bn' => $data['name_bn'],
                'name_en' => $data['name_en'],
                'username' => strtolower(trim($data['username'])),
                'email' => ! empty($data['email']) ? strtolower(trim($data['email'])) : null,
                'phone' => $data['phone'] ?? null,
                'status' => AccountStatus::INVITED,
                'preferred_locale' => SupportedLocale::default(),
            ]);

            // Assign initial role
            $user->roles()->attach($role->id, [
                'assigned_by' => $actor?->id,
                'created_at' => now(),
            ]);

            // Assign manual direct permission flags if supplied
            if (! empty($data['permissions'])) {
                $permissionIds = Permission::whereIn('key', $data['permissions'])->pluck('id');
                $syncData = [];
                foreach ($permissionIds as $permId) {
                    $syncData[$permId] = [
                        'granted_by' => $actor?->id,
                        'created_at' => now(),
                    ];
                }
                $user->permissionGrants()->sync($syncData);
            }

            // Generate one-time invitation token
            $invitationData = $this->invitationService->generateToken($user->id, $actor?->id);

            // Audit creation
            $this->auditLogger->record(
                'USER_CREATED',
                $actor?->id,
                $user->id,
                null,
                ['username' => $user->username, 'role' => $role->key, 'public_id' => $user->public_id],
                $ip,
                $requestId
            );

            return [
                'user' => $user->fresh(['roles.permissions']),
                'invitation' => [
                    'activation_token' => $invitationData['raw_token'],
                    'expires_at' => $invitationData['expires_at'],
                    'activation_url' => config('app.frontend_url', 'http://localhost:5173') . '/activate/' . $invitationData['raw_token'],
                    'delivery' => 'MANUAL_HANDOFF',
                ],
            ];
        });
    }
}

