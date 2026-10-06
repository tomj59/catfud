<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    /** Who changed what, newest first. Filter to one product or node with `subject_type` and `subject_id`. */
    public function index(AuditLogRequest $request): AnonymousResourceCollection
    {
        $q = AuditLog::with('user')->orderByDesc('id')
            ->when($request->query('subject_type'), fn ($b, $v) => $b->where('subject_type', $v))
            ->when($request->query('subject_id'), fn ($b, $v) => $b->where('subject_id', $v))
            ->when($request->query('user_id'), fn ($b, $v) => $b->where('user_id', $v))
            ->when($request->query('action'), fn ($b, $v) => $b->where('action', $v));

        return AuditLogResource::collection($q->paginate((int) $request->query('per_page', 50)));
    }
}
