<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date']]);
        $q = ActivityLog::query()->with('user:id,name')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('action'), fn ($q) => $q->whereLike('action', $request->string('action').'%'))
            ->when($request->filled('entity_type'), fn ($q) => $q->where('entity_type', $request->input('entity_type')))
            ->when($request->filled('entity_id'), fn ($q) => $q->where('entity_id', $request->integer('entity_id')))
            ->when($request->filled('q'), fn ($q) => $q->whereLike('description', '%'.$request->string('q').'%'))
            ->when($request->filled('date_from'), fn ($q) => $q->where('created_at', '>=', $request->date('date_from')->startOfDay()))
            ->when($request->filled('date_to'), fn ($q) => $q->where('created_at', '<=', $request->date('date_to')->endOfDay()))
            ->orderByDesc('id');

        return response()->json($q->paginate(min($request->integer('per_page', 50) ?: 50, 200)));
    }

    public function actions(): JsonResponse
    {
        return response()->json(['data' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action')]);
    }
}
