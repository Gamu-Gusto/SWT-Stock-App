<?php

namespace App\Http\Requests;

use App\Models\Sale;
use App\Services\Inventory\SalesService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('sales.create');
    }

    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'];

        return [
            'status' => ['sometimes', Rule::in([Sale::DRAFT, Sale::COMPLETED])],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'customer_name' => ['nullable', 'string', 'max:150'],
            'customer_phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +()\-]+$/'],
            'customer_email' => ['nullable', 'email', 'max:150'],
            'vehicle' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['nullable', 'required_unless:status,draft', Rule::in(Sale::PAYMENT_METHODS)],
            'payment_reference' => ['nullable', 'string', 'max:80'],
            'amount_paid' => $money,
            'reservation_id' => ['nullable', 'integer', Rule::exists('reservations', 'id')],
            'quotation_id' => ['nullable', 'integer', Rule::exists('quotations', 'id')],
            'items' => ['array', 'max:50'],
            'items.*.product_id' => ['required_without:items.*.tyre_unit_id', 'nullable', 'integer', Rule::exists('products', 'id')],
            'items.*.tyre_unit_id' => ['nullable', 'integer', Rule::exists('tyre_units', 'id')],
            'items.*.quantity' => ['required_without:items.*.tyre_unit_id', 'nullable', 'integer', 'min:1', 'max:1000'],
            'items.*.unit_price' => $money,
            'items.*.discount' => $money,
            'items.*.location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')],
            'items.*.reservation_item_id' => ['nullable', 'integer', Rule::exists('reservation_items', 'id')],
            'services' => ['array', 'max:20'],
            'services.*.service_code' => ['required', Rule::in(array_keys(SalesService::SERVICE_CODES))],
            'services.*.description' => ['nullable', 'string', 'max:150'],
            'services.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'services.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,2'],
            'services.*.discount' => $money,
        ];
    }

    public function messages(): array
    {
        return ['payment_method.required_unless' => 'Select how the customer paid (cash, card or EFT).'];
    }
}
