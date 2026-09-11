<?php

use App\Models\Branch;
use App\Models\Business;
use App\Models\Role;
use App\Models\User;

function foundationUser(string $roleCode = 'ROLE-SA', array $attributes = []): User
{
    $business = Business::query()->where('code', 'YDM')->firstOrFail();
    $branch = Branch::query()->where('code', 'YDM-SJC')->firstOrFail();
    $role = Role::query()->where('code', $roleCode)->firstOrFail();

    return User::factory()->create(array_merge([
        'business_id' => $business->id,
        'default_branch_id' => $branch->id,
        'role_id' => $role->id,
        'password' => '123456',
    ], $attributes));
}
