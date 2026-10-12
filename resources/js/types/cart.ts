export interface CartLine {
    key: string;
    product_id: number;
    tyre_unit_id?: number;
    unit_code?: string;
    name: string;
    size?: string;
    condition: string;
    available: number;
    quantity: number;
    unit_price: string;
    list_price: string;
    min_price: string | null;
    discount: string;
    reservation_item_id?: number;
    fees: { fitting: string | null; balancing: string | null; alignment: string | null };
}
export interface ServiceLine { key: string; service_code: string; description: string; quantity: number; unit_price: string; discount: string }

