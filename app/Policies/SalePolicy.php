<?php

namespace App\Policies;

use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    /** Employees see their own sales; sales.view_all sees every sale. */
    public function view(User $user, Sale $sale): bool
    {
        return $user->hasPermission('sales.view_all') || ($user->hasPermission('sales.create') && $sale->user_id === $user->id);
    }

    /** Only drafts can be edited, by their creator or a user who can see all sales. */
    public function update(User $user, Sale $sale): bool
    {
        return $user->hasPermission('sales.create') && ($sale->user_id === $user->id || $user->hasPermission('sales.view_all'));
    }
}
