<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\Role;

/**
 * Canonical list of permissions and the default permission set of each role.
 * The owner role always holds every permission (enforced in User::hasPermission).
 * Other roles' permissions can be changed by the owner in Users → Roles.
 */
final class PermissionRegistry
{
    /** name => [group, label] */
    public const PERMISSIONS = [
        'dashboard.view' => ['Dashboard', 'View the dashboard'],
        'dashboard.financial' => ['Dashboard', 'View sales revenue on the dashboard'],

        'inventory.view' => ['Inventory', 'Search and view tyres and stock levels'],
        'inventory.manage' => ['Inventory', 'Add and edit tyre records and used tyre units'],
        'inventory.archive' => ['Inventory', 'Archive and restore tyre records'],
        'inventory.import' => ['Inventory', 'Import opening stock from spreadsheets'],
        'brands.manage' => ['Inventory', 'Manage brands and tyre models'],
        'locations.manage' => ['Inventory', 'Manage storage locations'],
        'used.inspect' => ['Inventory', 'Record used tyre inspections and grades'],
        'movements.view' => ['Inventory', 'View stock movement history'],
        'transfers.create' => ['Inventory', 'Transfer stock between locations'],
        'adjustments.request' => ['Inventory', 'Request stock adjustments, damaged stock and write-offs'],
        'adjustments.approve' => ['Inventory', 'Approve stock adjustments (not their own)'],

        'financials.view' => ['Financial', 'View cost prices, margins and inventory value'],

        'suppliers.view' => ['Suppliers', 'View suppliers and their delivery history'],
        'suppliers.manage' => ['Suppliers', 'Add, edit and archive suppliers'],

        'receiving.view' => ['Receiving', 'View stock receipts'],
        'receiving.create' => ['Receiving', 'Capture stock deliveries'],
        'receiving.post' => ['Receiving', 'Post deliveries into stock'],
        'receiving.reverse' => ['Receiving', 'Reverse posted deliveries'],

        'sales.create' => ['Sales', 'Record sales'],
        'sales.view_all' => ['Sales', 'View all employees\' sales (otherwise own sales only)'],
        'sales.void' => ['Sales', 'Void completed sales'],
        'sales.returns' => ['Sales', 'Record customer returns and inspect returned tyres'],
        'sales.price_override' => ['Sales', 'Sell below the minimum approved price'],
        'reservations.manage' => ['Sales', 'Create and cancel reservations'],
        'reservations.view_all' => ['Sales', 'View all reservations (otherwise own only)'],
        'quotations.manage' => ['Sales', 'Create quotations and convert them'],

        'stocktake.count' => ['Stocktaking', 'Count stock in assigned stocktakes'],
        'stocktake.manage' => ['Stocktaking', 'Create, start and assign stocktakes; view all'],
        'stocktake.view_expected' => ['Stocktaking', 'See expected quantities while counting (non-blind counts)'],
        'stocktake.approve' => ['Stocktaking', 'Review and approve stocktake discrepancies'],

        'reports.operational' => ['Reports', 'Stock, movement, stocktake and slow-moving reports'],
        'reports.sales' => ['Reports', 'Sales reports'],
        'reports.financial' => ['Reports', 'Inventory valuation report'],

        'users.view' => ['Administration', 'View employees and their activity'],
        'users.manage' => ['Administration', 'Create, edit and deactivate employees'],
        'roles.manage' => ['Administration', 'Change role permissions'],
        'audit.view' => ['Administration', 'View the audit log'],
        'settings.manage' => ['Administration', 'Change business settings'],
    ];

    /** @return array<string, array{label:string, description:string, permissions:list<string>}> */
    public static function roles(): array
    {
        $all = array_keys(self::PERMISSIONS);

        return [
            Role::OWNER => [
                'label' => 'Owner / Super Admin',
                'description' => 'Full access, including financial information, users, roles and settings.',
                'permissions' => $all,
            ],
            Role::MANAGER => [
                'label' => 'Manager',
                'description' => 'Runs daily operations, approves stocktakes and adjustments. Financial access is configurable.',
                'permissions' => array_values(array_diff($all, [
                    'financials.view', 'reports.financial', 'users.manage', 'roles.manage', 'settings.manage',
                ])),
            ],
            Role::CLERK => [
                'label' => 'Inventory Clerk',
                'description' => 'Receives deliveries, maintains tyre records and counts stock.',
                'permissions' => [
                    'dashboard.view', 'inventory.view', 'inventory.manage', 'used.inspect', 'movements.view', 'transfers.create',
                    'adjustments.request', 'suppliers.view', 'receiving.view', 'receiving.create', 'receiving.post',
                    'stocktake.count', 'stocktake.view_expected',
                ],
            ],
            Role::SALES => [
                'label' => 'Sales Employee',
                'description' => 'Searches stock, reserves tyres, records sales and creates quotations.',
                'permissions' => ['dashboard.view', 'inventory.view', 'sales.create', 'reservations.manage', 'quotations.manage'],
            ],
            Role::COUNTER => [
                'label' => 'Stock Counter',
                'description' => 'Counts stock in assigned stocktakes only (blind counts).',
                'permissions' => ['dashboard.view', 'stocktake.count'],
            ],
        ];
    }

    /** Create/refresh permissions and roles. Existing role permission customisations are preserved unless $reset. */
    public static function sync(bool $reset = false): void
    {
        foreach (self::PERMISSIONS as $name => [$group, $label]) {
            Permission::query()->updateOrCreate(['name' => $name], ['group' => $group, 'label' => $label]);
        }
        Permission::query()->whereNotIn('name', array_keys(self::PERMISSIONS))->delete();
        $ids = Permission::query()->pluck('id', 'name');

        foreach (self::roles() as $name => $def) {
            $role = Role::query()->firstOrNew(['name' => $name]);
            $isNew = ! $role->exists;
            $role->fill(['label' => $def['label'], 'description' => $def['description'], 'is_system' => true])->save();
            if ($isNew || $reset || $name === Role::OWNER) {
                $role->permissions()->sync($ids->only($def['permissions'])->values()->all());
            }
        }
    }
}
