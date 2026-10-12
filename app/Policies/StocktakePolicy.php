<?php

namespace App\Policies;

use App\Models\Stocktake;
use App\Models\User;

class StocktakePolicy
{
    public function view(User $user, Stocktake $stocktake): bool
    {
        return $user->hasPermission('stocktake.manage') || $user->hasPermission('stocktake.approve')
            || ($user->hasPermission('stocktake.count') && $stocktake->isAssigned($user));
    }

    /** Counting is limited to assigned employees (managers may also count). */
    public function count(User $user, Stocktake $stocktake): bool
    {
        return ($user->hasPermission('stocktake.count') && $stocktake->isAssigned($user)) || $user->hasPermission('stocktake.manage');
    }

    /** Whether expected system quantities may be shown to this user for this stocktake. */
    public function viewExpected(User $user, Stocktake $stocktake): bool
    {
        if ($user->hasPermission('stocktake.approve')) {
            return true;
        }

        return ! $stocktake->is_blind && $user->hasPermission('stocktake.view_expected');
    }
}
