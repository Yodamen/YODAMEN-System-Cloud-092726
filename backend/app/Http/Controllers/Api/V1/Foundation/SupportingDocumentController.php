<?php

namespace App\Http\Controllers\Api\V1\Foundation;

use App\Http\Controllers\Controller;
use App\Models\SupportingDocument;
use App\Services\Foundation\SupportingDocumentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportingDocumentController extends Controller
{
    public function __construct(private readonly SupportingDocumentService $documents) {}

    public function uploadTemp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'module' => ['required', 'string', Rule::in(SupportingDocumentService::MODULES)],
            'purpose' => ['nullable', 'string', 'max:120'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,csv', 'max:10240'],
        ]);

        try {
            $row = $this->documents->uploadTemp($request->user(), $request->file('file'), $data['module'], $data['purpose'] ?? null);
            return response()->json([
                'document' => [
                    'token' => $row->upload_token,
                    'name' => $row->original_name,
                    'mime_type' => $row->mime_type,
                    'size_bytes' => (int) $row->size_bytes,
                ],
            ], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(SupportingDocumentService::ENTITY_TYPES)],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);
        abort_unless($this->documents->canViewEntityType($request->user(), $data['entity_type']), 403);
        $rows = $this->documents->listForEntity($request->user(), $data['entity_type'], (int) $data['entity_id']);
        return response()->json(['documents' => $rows->map(fn ($row) => [
            ...$this->documents->serialize($row),
            'can_download' => $this->documents->canDownload($request->user(), $row),
        ])->values()]);
    }

    public function download(Request $request, SupportingDocument $supportingDocument): StreamedResponse
    {
        abort_unless($this->documents->canDownload($request->user(), $supportingDocument), 404);
        abort_unless($this->documents->fileExists($supportingDocument), 404);
        return Storage::disk($supportingDocument->disk)->response(
            $supportingDocument->path,
            $supportingDocument->original_name,
            ['Content-Type' => $supportingDocument->mime_type ?: 'application/octet-stream'],
        );
    }
}
