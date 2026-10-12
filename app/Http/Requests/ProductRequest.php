<?php

namespace App\Http\Requests;

use App\Http\Controllers\Api\LookupController;
use App\Support\TyreSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('inventory.manage');
    }

    protected function prepareForValidation(): void
    {
        // Accept a complete size string ("205/55R16") as an alternative to separate fields.
        if ($this->filled('size') && ! $this->filled('width')) {
            if ($parsed = TyreSize::parse((string) $this->input('size'))) {
                $this->merge(['width' => $parsed['width'], 'profile' => $parsed['profile'], 'rim' => $parsed['rim']]);
            }
        }
        if ($this->has('rim')) {
            $this->merge(['rim' => preg_replace('/^R/i', '', (string) $this->input('rim'))]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $money = ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'];
        $req = $creating ? 'required' : 'sometimes';

        return [
            'brand_id' => [$req, 'integer', Rule::exists('brands', 'id')],
            'tyre_model_id' => ['nullable', 'integer', Rule::exists('tyre_models', 'id')],
            'model_name' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9\-_.\/]+$/', Rule::unique('products', 'sku')->ignore($this->route('product'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'condition' => [$req, Rule::in(['new', 'used'])],
            'width' => [$req, 'integer', 'between:'.TyreSize::MIN_WIDTH.','.TyreSize::MAX_WIDTH],
            'profile' => ['nullable', 'integer', 'between:'.TyreSize::MIN_PROFILE.','.TyreSize::MAX_PROFILE],
            'rim' => [$req, 'numeric', 'between:'.TyreSize::MIN_RIM.','.TyreSize::MAX_RIM, 'decimal:0,1'],
            'load_index' => ['nullable', 'string', 'max:10', 'regex:/^\d{2,3}(\/\d{2,3})?$/'],
            'speed_rating' => ['nullable', 'string', 'max:4', 'regex:/^[A-Za-z]{1,2}$/'],
            'tyre_type' => ['nullable', Rule::in(array_keys(LookupController::TYRE_TYPES))],
            'run_flat' => ['sometimes', 'boolean'],
            'cost_price' => $money,
            'selling_price' => [$req, 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'],
            'promo_price' => $money,
            'min_selling_price' => $money,
            'fitting_fee' => $money,
            'balancing_fee' => $money,
            'alignment_fee' => $money,
            'min_stock_level' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'reorder_qty' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'default_location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')],
            'default_supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')],
            'is_public' => ['sometimes', 'boolean'],
            'opening_qty' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'opening_location_id' => ['nullable', 'required_with:opening_qty', 'integer', Rule::exists('inventory_locations', 'id')],
        ];
    }

    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);
        if ($key === null && ! $this->user()->hasPermission('financials.view')) {
            // Users without financial access cannot set confidential cost information.
            unset($data['cost_price']);
        }

        return $data;
    }

    public function messages(): array
    {
        return [
            'width.between' => 'Tyre width must be between :min and :max mm.',
            'profile.between' => 'Profile must be between :min and :max.',
            'rim.between' => 'Rim size must be between :min and :max inches.',
            'load_index.regex' => 'Load index should look like 91 or 104/102.',
            'speed_rating.regex' => 'Speed rating should be a letter such as H, V or W.',
        ];
    }
}
