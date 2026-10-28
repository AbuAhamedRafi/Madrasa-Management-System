<?php

namespace App\Repositories;

use App\Enums\AccountStatus;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class UserRepository
{
    public function findById(int $id): ?User
    {
        return User::with(['roles.permissions', 'permissionGrants'])->find($id);
    }

    public function findByPublicId(string $publicId): ?User
    {
        return User::with(['roles.permissions', 'permissionGrants'])
            ->where('public_id', $publicId)
            ->first();
    }

    public function findByIdentifier(string $login): ?User
    {
        $normalized = strtolower(trim($login));

        return User::with(['roles.permissions', 'permissionGrants'])
            ->where(function (Builder $query) use ($normalized) {
                $query->where('username', $normalized)
                    ->orWhereRaw('LOWER(email) = ?', [$normalized]);
            })
            ->first();
    }

    public function paginateWithFilters(
        ?string $search = null,
        ?string $role = null,
        ?string $status = null,
        int $page = 1,
        int $pageSize = 20
    ): LengthAwarePaginator {
        $query = User::with('roles')->latest('id');

        if ($search) {
            $term = '%' . strtolower(trim($search)) . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->whereRaw('LOWER(username) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(name_en) LIKE ?', [$term])
                    ->orWhere('name_bn', 'LIKE', $term);
            });
        }

        if ($role && $role !== 'ALL') {
            $query->whereHas('roles', fn ($q) => $q->where('key', $role));
        }

        if ($status && $status !== 'ALL') {
            $query->where('status', $status);
        }

        return $query->paginate($pageSize, ['*'], 'page', $page);
    }

    public function getCounts(): array
    {
        return [
            'total' => User::count(),
            'active' => User::where('status', AccountStatus::ACTIVE)->count(),
            'invited' => User::where('status', AccountStatus::INVITED)->count(),
            'disabled' => User::where('status', AccountStatus::DISABLED)->count(),
        ];
    }

    public function countActiveAdminsExcluding(int $userId): int
    {
        return User::where('id', '!=', $userId)
            ->where('status', AccountStatus::ACTIVE)
            ->whereHas('roles', fn ($q) => $q->where('key', 'ADMIN'))
            ->lockForUpdate()
            ->get()
            ->count();
    }

    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    public function update(User $user, array $attributes): bool
    {
        return $user->update($attributes);
    }
}
