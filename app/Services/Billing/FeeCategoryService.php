<?php

namespace App\Services\Billing;

use App\Enums\FeeBillingType;
use App\Exceptions\BillingDomainException;
use App\Models\FeeCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FeeCategoryService
{
    public function listCategories(array $filters = []): LengthAwarePaginator
    {
        $query = FeeCategory::query();

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isActive !== null) {
                $query->where('is_active', $isActive);
            }
        }

        if (!empty($filters['billing_type'])) {
            $query->where('billing_type', $filters['billing_type']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', "%{$search}%")
                  ->orWhere('name_bn', 'ilike', "%{$search}%")
                  ->orWhere('name_en', 'ilike', "%{$search}%");
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->orderBy('code')->paginate($perPage);
    }

    public function createCategory(array $data, int $actorId): FeeCategory
    {
        $code = strtoupper(trim($data['code']));

        if (FeeCategory::where('code', $code)->exists()) {
            throw BillingDomainException::conflict(
                'FEE_CATEGORY_DUPLICATE',
                "A fee category with code '{$code}' already exists.",
                ['code' => $code]
            );
        }

        $billingType = isset($data['billing_type'])
            ? (FeeBillingType::tryFrom($data['billing_type']) ?? FeeBillingType::RECURRING)
            : FeeBillingType::RECURRING;

        return FeeCategory::create([
            'code' => $code,
            'name_bn' => trim($data['name_bn']),
            'name_en' => trim($data['name_en']),
            'billing_type' => $billingType,
            'description_bn' => $data['description_bn'] ?? null,
            'description_en' => $data['description_en'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $actorId,
        ]);
    }

    public function updateCategory(int $id, array $data, int $actorId): FeeCategory
    {
        $category = FeeCategory::find($id);
        if (!$category) {
            throw BillingDomainException::notFound('FEE_CATEGORY_NOT_FOUND', "Fee category #{$id} not found.");
        }

        if (isset($data['code']) && strtoupper(trim($data['code'])) !== $category->code) {
            throw BillingDomainException::validation(
                'FEE_CATEGORY_CODE_IMMUTABLE',
                'Fee category code is immutable and cannot be changed after creation.'
            );
        }

        if (isset($data['name_bn'])) {
            $category->name_bn = trim($data['name_bn']);
        }
        if (isset($data['name_en'])) {
            $category->name_en = trim($data['name_en']);
        }
        if (isset($data['billing_type'])) {
            $bt = FeeBillingType::tryFrom($data['billing_type']);
            if (!$bt) {
                throw BillingDomainException::validation('INVALID_BILLING_TYPE', 'Invalid billing type specified.');
            }
            $category->billing_type = $bt;
        }
        if (array_key_exists('description_bn', $data)) {
            $category->description_bn = $data['description_bn'];
        }
        if (array_key_exists('description_en', $data)) {
            $category->description_en = $data['description_en'];
        }
        if (array_key_exists('is_active', $data)) {
            $category->is_active = (bool) $data['is_active'];
        }

        $category->save();

        return $category->fresh();
    }
}
