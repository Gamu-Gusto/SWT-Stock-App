import type { Component } from 'vue';
import {
    LayoutDashboard, CircleDot, PackagePlus, Truck, Receipt, CalendarClock, ClipboardCheck, Building2,
    BarChart3, ArrowLeftRight, Users, Settings, Search, ShoppingCart, FileText,
} from 'lucide-vue-next';

export interface NavItem {
    label: string;
    to: string;
    icon: Component;
    can?: string[];
}

/** Desktop sidebar, in the order requested by the business. */
export const mainNav: NavItem[] = [
    { label: 'Dashboard', to: '/', icon: LayoutDashboard, can: ['dashboard.view'] },
    { label: 'Tyre inventory', to: '/inventory', icon: CircleDot, can: ['inventory.view'] },
    { label: 'Add stock', to: '/add-stock', icon: PackagePlus, can: ['inventory.manage', 'receiving.create'] },
    { label: 'Stock receiving', to: '/receiving', icon: Truck, can: ['receiving.view'] },
    { label: 'Sales', to: '/sales', icon: Receipt, can: ['sales.create', 'sales.returns'] },
    { label: 'Reservations', to: '/reservations', icon: CalendarClock, can: ['reservations.manage'] },
    { label: 'Quotations', to: '/quotations', icon: FileText, can: ['quotations.manage'] },
    { label: 'Stocktaking', to: '/stocktakes', icon: ClipboardCheck, can: ['stocktake.count', 'stocktake.manage', 'stocktake.approve'] },
    { label: 'Suppliers', to: '/suppliers', icon: Building2, can: ['suppliers.view'] },
    { label: 'Reports', to: '/reports', icon: BarChart3, can: ['reports.operational', 'reports.sales', 'reports.financial'] },
    { label: 'Stock movements', to: '/movements', icon: ArrowLeftRight, can: ['movements.view', 'adjustments.request'] },
    { label: 'Users', to: '/users', icon: Users, can: ['users.view'] },
    { label: 'Settings', to: '/settings', icon: Settings, can: ['settings.manage', 'brands.manage', 'locations.manage'] },
];

/** Mobile bottom bar: the five daily jobs. */
export const mobileNav: NavItem[] = [
    { label: 'Home', to: '/', icon: LayoutDashboard },
    { label: 'Search', to: '/search', icon: Search, can: ['inventory.view'] },
    { label: 'Add stock', to: '/add-stock', icon: PackagePlus, can: ['inventory.manage', 'receiving.create'] },
    { label: 'Sell', to: '/sales/new', icon: ShoppingCart, can: ['sales.create'] },
    { label: 'Count', to: '/stocktakes', icon: ClipboardCheck, can: ['stocktake.count', 'stocktake.manage'] },
];
