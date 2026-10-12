/**
 * Integer-cent helpers for ON-SCREEN ESTIMATES only. The server recalculates every total with
 * exact decimal arithmetic and its result is what gets stored.
 */
export function cents(value: string | number | null | undefined): number {
    if (value === null || value === undefined || value === '') return 0;
    const [whole, frac = ''] = String(value).trim().split('.');
    const sign = whole.startsWith('-') ? -1 : 1;
    const w = Math.abs(parseInt(whole, 10) || 0);
    const f = parseInt((frac + '00').slice(0, 2), 10) || 0;
    const third = parseInt(frac.charAt(2) || '0', 10);
    return sign * (w * 100 + f + (third >= 5 ? 1 : 0));
}

export function fromCents(c: number): string {
    const sign = c < 0 ? '-' : '';
    const a = Math.abs(c);
    return `${sign}${Math.floor(a / 100)}.${String(a % 100).padStart(2, '0')}`;
}

export interface PriceLine { unit_price: string | number; quantity: number; discount?: string | number }

export function estimate(lines: PriceLine[], vat: { registered: boolean; rate: string; inclusive: boolean }) {
    let subtotal = 0;
    let discount = 0;
    for (const l of lines) {
        subtotal += cents(l.unit_price) * (Number(l.quantity) || 0);
        discount += cents(l.discount ?? 0);
    }
    const net = subtotal - discount;
    const bp = vat.registered ? cents(vat.rate) : 0;
    let vatAmount = 0;
    let total = net;
    if (bp > 0) {
        if (vat.inclusive) vatAmount = Math.round((net * bp) / (10000 + bp));
        else {
            vatAmount = Math.round((net * bp) / 10000);
            total = net + vatAmount;
        }
    }
    return { subtotal: fromCents(subtotal), discount: fromCents(discount), vat: fromCents(vatAmount), total: fromCents(total) };
}
