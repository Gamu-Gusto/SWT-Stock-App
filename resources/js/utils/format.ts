/** Formatting for South African users: R 1 350,00 style money and dd/mm/yyyy dates (Africa/Johannesburg). */

const TZ = 'Africa/Johannesburg';

const money = new Intl.NumberFormat('en-ZA', { style: 'currency', currency: 'ZAR', minimumFractionDigits: 2 });

export function formatMoney(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') return '–';
    // Values arrive as exact decimal strings; Number() is used for display only, never for calculations.
    return money.format(Number(value)).replace(/ /g, ' ');
}

function parts(value: string | Date) {
    const d = typeof value === 'string' ? new Date(value.length === 10 ? `${value}T00:00:00+02:00` : value) : value;
    const f = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });
    const p = Object.fromEntries(f.formatToParts(d).map((x) => [x.type, x.value]));
    return p as Record<string, string>;
}

export function formatDate(value: string | null | undefined): string {
    if (!value) return '–';
    const p = parts(value);
    return `${p.day}/${p.month}/${p.year}`;
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) return '–';
    const p = parts(value);
    return `${p.day}/${p.month}/${p.year} ${p.hour}:${p.minute}`;
}

export function relativeTime(value: string | null | undefined): string {
    if (!value) return '';
    const diff = (new Date(value).getTime() - Date.now()) / 1000;
    const abs = Math.abs(diff);
    const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute');
    if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour');
    return rtf.format(Math.round(diff / 86400), 'day');
}

export function todayIso(): string {
    const p = parts(new Date());
    return `${p.year}-${p.month}-${p.day}`;
}

export function daysAgoIso(days: number): string {
    const p = parts(new Date(Date.now() - days * 86400000));
    return `${p.year}-${p.month}-${p.day}`;
}

export const statusLabels: Record<string, string> = {
    in_stock: 'In stock',
    low_stock: 'Low stock',
    out_of_stock: 'Out of stock',
    draft: 'Draft',
    posted: 'Posted',
    reversed: 'Reversed',
    completed: 'Completed',
    voided: 'Voided',
    returned: 'Returned',
    partially_returned: 'Part returned',
    active: 'Active',
    cancelled: 'Cancelled',
    expired: 'Expired',
    open: 'Open',
    converted: 'Converted',
    in_progress: 'In progress',
    submitted: 'Submitted',
    under_review: 'Under review',
    approved: 'Approved',
    closed: 'Closed',
    pending: 'Pending',
    rejected: 'Rejected',
    counted: 'Counted',
    recount_requested: 'Recount',
    pending_inspection: 'Awaiting inspection',
    available: 'Available',
    reserved: 'Reserved',
    sold: 'Sold',
    written_off: 'Written off',
    returned_to_supplier: 'Returned to supplier',
    missing: 'Missing',
    paid: 'Paid',
    unpaid: 'Unpaid',
    partial: 'Part paid',
    archived: 'Archived',
};

export function label(status: string | null | undefined): string {
    if (!status) return '–';
    return statusLabels[status] ?? status.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

export function plural(n: number, word: string, pluralWord = `${word}s`): string {
    return `${n} ${n === 1 ? word : pluralWord}`;
}
