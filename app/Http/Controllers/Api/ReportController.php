<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(protected ReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->available($request->user())]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        abort_unless(isset(ReportService::REPORTS[$key]), 404);

        return response()->json(['data' => $this->reports->run($key, $this->filters($request), $request->user())]);
    }

    public function export(Request $request, string $key, ReportExporter $exporter): StreamedResponse
    {
        abort_unless(isset(ReportService::REPORTS[$key]), 404);
        $format = $request->validate(['format' => ['required', Rule::in(['csv', 'xlsx'])]])['format'];
        $report = $this->reports->run($key, $this->filters($request), $request->user());
        Audit::log('report.exported', 'Report', "Exported {$report['title']} ({$format})", null, ['filters' => $report['filters']]);

        return $format === 'csv' ? $exporter->csv($report) : $exporter->xlsx($report);
    }

    protected function filters(Request $request): array
    {
        return $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'brand_id' => ['nullable', 'integer'],
            'size' => ['nullable', 'string', 'max:30'],
            'width' => ['nullable', 'integer'],
            'profile' => ['nullable', 'integer'],
            'rim' => ['nullable', 'numeric'],
            'condition' => ['nullable', Rule::in(['new', 'used'])],
            'user_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'group_by' => ['nullable', Rule::in(['date', 'employee', 'brand', 'size', 'condition'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'movement_type' => ['nullable', 'string', 'max:30'],
            'stocktake_id' => ['nullable', 'integer'],
        ]);
    }
}
