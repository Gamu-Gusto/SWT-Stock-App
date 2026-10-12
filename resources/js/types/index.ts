export type StockStatus = 'in_stock' | 'low_stock' | 'out_of_stock';
export type Condition = 'new' | 'used';

export interface User {
    id: number;
    name: string;
    email: string;
    phone: string | null;
    role: { id: number; name: string; label: string };
    permissions: string[];
    must_change_password: boolean;
    last_login_at: string | null;
}

export interface Location {
    id: number;
    code: string;
    name: string;
    type: string;
    is_sellable: boolean;
    is_active: boolean;
}

export interface BrandLookup {
    id: number;
    name: string;
    is_active: boolean;
    is_preferred: boolean;
    models: { id: number; name: string }[];
}

export interface Settings {
    business_name: string;
    business_phone: string;
    whatsapp_number: string;
    business_email: string;
    business_address: string;
    website_url: string;
    logo_url: string;
    logo_is_default: boolean;
    brand_color: string;
    brand_ink: string;
    currency: string;
    vat_registered: boolean;
    vat_number: string;
    vat_rate: string;
    prices_include_vat: boolean;
    reservation_hours: number;
    quotation_valid_days: number;
    default_min_stock: number;
    used_tyre_grades: string[];
    stocktake_lock_mode: 'block' | 'track';
    slow_moving_days: number;
}

export interface Lookups {
    settings: Settings;
    brands: BrandLookup[];
    locations: Location[];
    suppliers: { id: number; company_name: string }[];
    employees: { id: number; name: string; role: string }[];
    service_codes: Record<string, string>;
    tyre_types: Record<string, string>;
    movement_types: Record<string, string>;
}

export interface TyreSpec {
    width: number;
    profile: number | null;
    rim: string;
    size: string;
    load_index: string | null;
    speed_rating: string | null;
    tyre_type: string | null;
    run_flat: boolean;
}

export interface Product {
    id: number;
    sku: string;
    brand?: { id: number; name: string };
    brand_id: number;
    tyre_model_id: number | null;
    model: string;
    name: string;
    display_name: string;
    description: string | null;
    condition: Condition;
    tracking: 'quantity' | 'unit';
    spec: TyreSpec | null;
    selling_price: string;
    promo_price: string | null;
    effective_price: string;
    min_selling_price: string | null;
    fitting_fee: string | null;
    balancing_fee: string | null;
    alignment_fee: string | null;
    cost_price?: string;
    qty_on_hand: number;
    qty_physical: number;
    qty_unsellable: number;
    qty_reserved: number;
    qty_available: number;
    stock_status: StockStatus;
    min_stock_level: number;
    reorder_qty: number;
    default_location_id: number | null;
    default_supplier_id: number | null;
    locations?: { location_id: number; name: string; is_sellable: boolean; qty: number }[];
    unit_price_range?: [string, string] | null;
    is_public: boolean;
    status: 'active' | 'archived';
    last_received_at: string | null;
    last_sold_at: string | null;
    last_movement_at: string | null;
    [key: string]: unknown;
}

export interface TyreUnit {
    id: number;
    unit_code: string;
    product_id: number;
    product?: { id: number; sku: string; display_name: string };
    location?: { id: number; name: string; is_sellable: boolean } | null;
    location_id: number | null;
    tread_depth_mm: string | null;
    grade: string | null;
    dot_code: string | null;
    defects: string | null;
    inspection_notes: string | null;
    selling_price: string;
    cost_price?: string;
    status: string;
    is_sellable: boolean;
    inspected_by?: string | null;
    inspected_at: string | null;
    images?: Media[];
}

export interface Media {
    id: number;
    name: string;
    mime_type: string;
    size: number;
    url: string;
}

export interface Paginated<T> {
    data: T[];
    current_page?: number;
    last_page?: number;
    total?: number;
    per_page?: number;
    meta?: { current_page: number; last_page: number; total: number; per_page: number; [k: string]: unknown };
}

export interface ApiErrorShape {
    status: number;
    message: string;
    code?: string;
    errors?: Record<string, string[]>;
    context?: Record<string, unknown> | null;
}
