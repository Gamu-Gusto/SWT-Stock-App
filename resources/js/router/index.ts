import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import { useAuth } from '@/stores/auth';
import { useLookups } from '@/stores/lookups';

declare module 'vue-router' {
    interface RouteMeta {
        guest?: boolean;
        /** User needs ANY of these permissions. The server enforces the same rules. */
        can?: string[];
        title?: string;
    }
}

const routes: RouteRecordRaw[] = [
    { path: '/login', name: 'login', component: () => import('@/pages/auth/LoginPage.vue'), meta: { guest: true, title: 'Sign in' } },
    { path: '/forgot-password', name: 'forgot', component: () => import('@/pages/auth/ForgotPasswordPage.vue'), meta: { guest: true, title: 'Forgot password' } },
    { path: '/reset-password/:token', name: 'reset', component: () => import('@/pages/auth/ResetPasswordPage.vue'), meta: { guest: true, title: 'Reset password' } },
    {
        path: '/',
        component: () => import('@/layouts/AppLayout.vue'),
        children: [
            { path: '', name: 'dashboard', component: () => import('@/pages/DashboardPage.vue'), meta: { title: 'Dashboard' } },
            { path: 'search', name: 'search', component: () => import('@/pages/inventory/TyreSearchPage.vue'), meta: { can: ['inventory.view'], title: 'Tyre search' } },
            { path: 'inventory', name: 'inventory', component: () => import('@/pages/inventory/InventoryPage.vue'), meta: { can: ['inventory.view'], title: 'Tyre inventory' } },
            { path: 'inventory/new', name: 'product-new', component: () => import('@/pages/inventory/ProductFormPage.vue'), meta: { can: ['inventory.manage'], title: 'Add tyre' } },
            { path: 'inventory/:id', name: 'product', component: () => import('@/pages/inventory/ProductDetailPage.vue'), meta: { can: ['inventory.view'], title: 'Tyre details' } },
            { path: 'inventory/:id/edit', name: 'product-edit', component: () => import('@/pages/inventory/ProductFormPage.vue'), meta: { can: ['inventory.manage'], title: 'Edit tyre' } },
            { path: 'units/:id', name: 'unit', component: () => import('@/pages/inventory/UnitDetailPage.vue'), meta: { can: ['inventory.view'], title: 'Used tyre' } },
            { path: 'add-stock', name: 'add-stock', component: () => import('@/pages/inventory/AddStockPage.vue'), meta: { can: ['inventory.manage', 'receiving.create'], title: 'Add stock' } },

            { path: 'receiving', name: 'receipts', component: () => import('@/pages/receiving/ReceiptListPage.vue'), meta: { can: ['receiving.view'], title: 'Receiving history' } },
            { path: 'receiving/new', name: 'receipt-new', component: () => import('@/pages/receiving/ReceiptFormPage.vue'), meta: { can: ['receiving.create'], title: 'Receive stock' } },
            { path: 'receiving/:id', name: 'receipt', component: () => import('@/pages/receiving/ReceiptDetailPage.vue'), meta: { can: ['receiving.view'], title: 'Receipt' } },
            { path: 'receiving/:id/edit', name: 'receipt-edit', component: () => import('@/pages/receiving/ReceiptFormPage.vue'), meta: { can: ['receiving.create'], title: 'Edit draft receipt' } },

            { path: 'sales', name: 'sales', component: () => import('@/pages/sales/SaleListPage.vue'), meta: { can: ['sales.create', 'sales.returns'], title: 'Sales' } },
            { path: 'sales/new', name: 'sale-new', component: () => import('@/pages/sales/SaleFormPage.vue'), meta: { can: ['sales.create'], title: 'New sale' } },
            { path: 'sales/returns', name: 'returns', component: () => import('@/pages/sales/ReturnsPage.vue'), meta: { can: ['sales.returns'], title: 'Returns inspection' } },
            { path: 'sales/:id', name: 'sale', component: () => import('@/pages/sales/SaleDetailPage.vue'), meta: { can: ['sales.create', 'sales.returns'], title: 'Sale' } },

            { path: 'reservations', name: 'reservations', component: () => import('@/pages/reservations/ReservationListPage.vue'), meta: { can: ['reservations.manage'], title: 'Reservations' } },
            { path: 'reservations/new', name: 'reservation-new', component: () => import('@/pages/reservations/ReservationFormPage.vue'), meta: { can: ['reservations.manage'], title: 'Create reservation' } },
            { path: 'reservations/:id', name: 'reservation', component: () => import('@/pages/reservations/ReservationDetailPage.vue'), meta: { can: ['reservations.manage'], title: 'Reservation' } },

            { path: 'quotations', name: 'quotations', component: () => import('@/pages/quotations/QuotationListPage.vue'), meta: { can: ['quotations.manage'], title: 'Quotations' } },
            { path: 'quotations/new', name: 'quotation-new', component: () => import('@/pages/quotations/QuotationFormPage.vue'), meta: { can: ['quotations.manage'], title: 'New quotation' } },
            { path: 'quotations/:id', name: 'quotation', component: () => import('@/pages/quotations/QuotationDetailPage.vue'), meta: { can: ['quotations.manage'], title: 'Quotation' } },

            { path: 'stocktakes', name: 'stocktakes', component: () => import('@/pages/stocktakes/StocktakeListPage.vue'), meta: { can: ['stocktake.count', 'stocktake.manage', 'stocktake.approve'], title: 'Stocktaking' } },
            { path: 'stocktakes/approvals', name: 'stocktake-approvals', component: () => import('@/pages/stocktakes/StocktakeListPage.vue'), props: { approvals: true }, meta: { can: ['stocktake.approve'], title: 'Stocktake approvals' } },
            { path: 'stocktakes/new', name: 'stocktake-new', component: () => import('@/pages/stocktakes/StocktakeFormPage.vue'), meta: { can: ['stocktake.manage'], title: 'New stocktake' } },
            { path: 'stocktakes/:id', name: 'stocktake', component: () => import('@/pages/stocktakes/StocktakeDetailPage.vue'), meta: { can: ['stocktake.count', 'stocktake.manage', 'stocktake.approve'], title: 'Stocktake' } },
            { path: 'stocktakes/:id/count', name: 'stocktake-count', component: () => import('@/pages/stocktakes/StocktakeCountPage.vue'), meta: { can: ['stocktake.count', 'stocktake.manage'], title: 'Physical count' } },
            { path: 'stocktakes/:id/review', name: 'stocktake-review', component: () => import('@/pages/stocktakes/StocktakeReviewPage.vue'), meta: { can: ['stocktake.approve'], title: 'Stocktake review' } },

            { path: 'suppliers', name: 'suppliers', component: () => import('@/pages/suppliers/SupplierListPage.vue'), meta: { can: ['suppliers.view'], title: 'Suppliers' } },
            { path: 'suppliers/:id', name: 'supplier', component: () => import('@/pages/suppliers/SupplierDetailPage.vue'), meta: { can: ['suppliers.view'], title: 'Supplier' } },

            { path: 'movements', name: 'movements', component: () => import('@/pages/movements/MovementsPage.vue'), meta: { can: ['movements.view', 'adjustments.request'], title: 'Stock movements' } },
            { path: 'movements/transfers', name: 'transfers', component: () => import('@/pages/movements/TransfersPage.vue'), meta: { can: ['transfers.create', 'movements.view'], title: 'Stock transfers' } },
            { path: 'movements/adjustments', name: 'adjustments', component: () => import('@/pages/movements/AdjustmentsPage.vue'), meta: { can: ['adjustments.request', 'adjustments.approve'], title: 'Adjustments' } },

            { path: 'reports', name: 'reports', component: () => import('@/pages/reports/ReportsPage.vue'), meta: { can: ['reports.operational', 'reports.sales', 'reports.financial'], title: 'Reports' } },
            { path: 'reports/:key', name: 'report', component: () => import('@/pages/reports/ReportViewPage.vue'), meta: { can: ['reports.operational', 'reports.sales', 'reports.financial'], title: 'Report' } },
            { path: 'import', name: 'import', component: () => import('@/pages/inventory/StockImportPage.vue'), meta: { can: ['inventory.import'], title: 'Stock import' } },

            { path: 'users', name: 'users', component: () => import('@/pages/admin/UsersPage.vue'), meta: { can: ['users.view'], title: 'Users' } },
            { path: 'users/roles', name: 'roles', component: () => import('@/pages/admin/RolesPage.vue'), meta: { can: ['users.view'], title: 'Roles and permissions' } },
            { path: 'audit', name: 'audit', component: () => import('@/pages/admin/AuditLogPage.vue'), meta: { can: ['audit.view'], title: 'Audit log' } },
            { path: 'settings', name: 'settings', component: () => import('@/pages/admin/SettingsPage.vue'), meta: { can: ['settings.manage', 'brands.manage', 'locations.manage'], title: 'Settings' } },
            { path: 'notifications', name: 'notifications', component: () => import('@/pages/NotificationsPage.vue'), meta: { title: 'Notifications' } },
            { path: 'profile', name: 'profile', component: () => import('@/pages/ProfilePage.vue'), meta: { title: 'Profile and password' } },
            { path: ':pathMatch(.*)*', name: 'not-found', component: () => import('@/pages/NotFoundPage.vue'), meta: { title: 'Not found' } },
        ],
    },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: (to, from, saved) => saved ?? (to.path !== from.path ? { top: 0 } : undefined),
});

router.beforeEach(async (to) => {
    const auth = useAuth();
    if (!auth.checked) await auth.fetchUser();

    if (to.meta.guest) {
        return auth.isAuthenticated && to.name === 'login' ? { name: 'dashboard' } : true;
    }
    if (!auth.isAuthenticated) {
        return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} };
    }
    if (auth.user?.must_change_password && to.name !== 'profile') {
        return { name: 'profile', query: { force: '1' } };
    }
    await useLookups().load();
    if (to.meta.can && !auth.can(...to.meta.can)) {
        return { name: 'dashboard', query: { denied: to.meta.title ?? '1' } };
    }
    return true;
});

router.afterEach((to) => {
    const name = useLookups().settings?.business_name ?? 'Super Wheels and Tyres';
    document.title = `${to.meta.title ?? 'Stock'} · ${name}`;
});

export default router;
