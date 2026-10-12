<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockImport;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ImportController extends Controller
{
    public function __construct(protected ImportService $imports) {}

    public function index(): JsonResponse
    {
        return response()->json(StockImport::query()->with('user:id,name')->orderByDesc('id')->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', File::types(['csv', 'txt', 'xlsx'])->max(5 * 1024)]]);
        $import = $this->imports->upload($request->file('file'), $request->user());

        return response()->json(['data' => $this->present($import)], 201);
    }

    public function show(StockImport $import): JsonResponse
    {
        return response()->json(['data' => $this->present($import)]);
    }

    public function preview(Request $request, StockImport $import): JsonResponse
    {
        $data = $this->validateOptions($request);
        abort_if($import->status !== 'uploaded', 409, 'This import has already been processed.');
        $preview = $this->imports->preview($import, $data['mapping'], $data['duplicate_mode'], $data);

        return response()->json(['data' => $preview]);
    }

    public function commit(Request $request, StockImport $import): JsonResponse
    {
        $data = $this->validateOptions($request);
        $import->forceFill(['mapping' => $data['mapping'], 'duplicate_mode' => $data['duplicate_mode']])->save();
        $import = $this->imports->commit($import, $data, $request->user());

        return response()->json(['data' => $this->present($import), 'message' => 'Import completed.']);
    }

    public function template(): BinaryFileResponse
    {
        return response()->download($this->imports->templatePath(), 'swt-stock-import-template.xlsx')->deleteFileAfterSend();
    }

    protected function validateOptions(Request $request): array
    {
        $fields = array_keys(ImportService::FIELDS);
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mapping.*' => ['nullable', 'integer', 'min:0', 'max:200'],
            'duplicate_mode' => ['required', Rule::in(['skip', 'add'])],
            'default_location_id' => ['nullable', 'integer', Rule::exists('inventory_locations', 'id')],
            'used_status' => ['nullable', Rule::in(['pending_inspection', 'available'])],
        ]);
        $data['mapping'] = array_intersect_key($data['mapping'], array_flip($fields));

        return $data;
    }

    protected function present(StockImport $import): array
    {
        return $import->toArray() + ['fields' => collect(ImportService::FIELDS)->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'required' => $f[1]])->values()];
    }
}
