<?php

namespace App\Http\Controllers\UserManagement;

use App\Enums\AccountStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\AssignUserPermissionsRequest;
use App\Http\Requests\UserManagement\AssignUserRolesRequest;
use App\Http\Requests\UserManagement\CreateUserRequest;
use App\Http\Requests\UserManagement\UpdateUserRequest;
use App\Http\Requests\UserManagement\UpdateUserStatusRequest;
use App\Http\Resources\UserManagement\UserListResource;
use App\Models\Permission;
use App\Repositories\UserRepository;
use App\Services\Authentication\InvitationService;
use App\Services\UserManagement\AccountProvisioner;
use App\Services\UserManagement\AccountStatusService;
use App\Services\UserManagement\RoleAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(
        protected UserRepository $userRepository,
        protected AccountProvisioner $accountProvisioner,
        protected AccountStatusService $accountStatusService,
        protected RoleAssignmentService $roleAssignmentService,
        protected InvitationService $invitationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->userRepository->paginateWithFilters(
            $request->query('search'),
            $request->query('role'),
            $request->query('status'),
            (int) $request->query('page', 1),
            (int) $request->query('page_size', 20)
        );

        return response()->json([
            'data' => UserListResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'page_size' => $paginator->perPage(),
                'total' => $paginator->total(),
                'total_pages' => $paginator->lastPage(),
                'counts' => $this->userRepository->getCounts(),
            ],
        ]);
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $result = $this->accountProvisioner->provision(
            $request->validated(),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => (new UserListResource($result['user']))->toArray($request) + [
                'invitation' => $result['invitation'],
            ],
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $user = $this->userRepository->findByPublicId($id);
        if (! $user && is_numeric($id)) {
            $user = $this->userRepository->findById((int) $id);
        }

        if (! $user) {
            abort(404, 'User not found');
        }

        return response()->json([
            'data' => new UserListResource($user),
        ]);
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->userRepository->findByPublicId($id);
        if (! $user && is_numeric($id)) {
            $user = $this->userRepository->findById((int) $id);
        }

        if (! $user) {
            abort(404, 'User not found');
        }

        $this->userRepository->update($user, $request->validated());

        return response()->json([
            'data' => new UserListResource($user->fresh(['roles'])),
        ]);
    }

    public function updateStatus(UpdateUserStatusRequest $request, string $id): JsonResponse
    {
        $user = $this->userRepository->findByPublicId($id);
        if (! $user && is_numeric($id)) {
            $user = $this->userRepository->findById((int) $id);
        }

        if (! $user) {
            abort(404, 'User not found');
        }

        $updated = $this->accountStatusService->updateStatus(
            $user,
            AccountStatus::from($request->input('status')),
            $request->input('reason'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new UserListResource($updated),
        ]);
    }

    public function assignRoles(AssignUserRolesRequest $request, string $id): JsonResponse
    {
        $user = $this->userRepository->findByPublicId($id);
        if (! $user && is_numeric($id)) {
            $user = $this->userRepository->findById((int) $id);
        }

        if (! $user) {
            abort(404, 'User not found');
        }

        $updated = $this->roleAssignmentService->assignRoles(
            $user,
            $request->input('roles'),
            $request->user(),
            $request->ip(),
            $request->attributes->get('request_id')
        );

        return response()->json([
            'data' => new UserListResource($updated),
        ]);
    }

    public function assignPermissions(AssignUserPermissionsRequest $request, string $id): JsonResponse
    {
        $user = $this->userRepository->findByPublicId($id);
        if (! $user && is_numeric($id)) {
            $user = $this->userRepository->findById((int) $id);
        }

        if (! $user) {
            abort(404, 'User not found');
        }

        $permissionKeys = $request->input('permissions', []);
        $permissionIds = Permission::whereIn('key', $permissionKeys)->pluck('id');

        $syncData = [];
        foreach ($permissionIds as $permId) {
            $syncData[$permId] = [
                'granted_by' => $request->user()->id,
                'created_at' => now(),
            ];
        }

        $user->permissionGrants()->sync($syncData);

        return response()->json([
            'data' => new UserListResource($user->fresh(['roles', 'permissionGrants'])),
        ]);
    }

    public function reissueInvitation(Request $request, string $id): JsonResponse
    {
        $user = $this->userRepository->findByPublicId($id);
        if (! $user && is_numeric($id)) {
            $user = $this->userRepository->findById((int) $id);
        }

        if (! $user) {
            abort(404, 'User not found');
        }

        $invitation = $this->invitationService->generateToken($user->id, $request->user()->id);

        return response()->json([
            'data' => [
                'activation_url' => config('app.frontend_url', 'http://localhost:5173') . '/activate/' . $invitation['raw_token'],
                'expires_at' => $invitation['expires_at'],
            ],
        ]);
    }
}

