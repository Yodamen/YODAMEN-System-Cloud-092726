<?php

namespace Tests;

use App\Services\Foundation\SupportingDocumentService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    public function workflowReasonId(string $code): int
    {
        $id = \App\Models\ReferenceValue::query()
            ->where('code', strtoupper($code))
            ->where('active', true)
            ->whereHas('group', fn ($query) => $query
                ->where('code', \App\Services\MasterData\WorkflowReasonCatalog::GROUP_CODE)
                ->where('active', true))
            ->value('id');

        if (! $id) {
            throw new \RuntimeException('Workflow reason not seeded: '.strtoupper($code));
        }

        return (int) $id;
    }

    public function supportingDocumentToken($user, string $module, string $filename = 'support.pdf'): string
    {
        $document = app(SupportingDocumentService::class)->uploadTemp(
            $user,
            UploadedFile::fake()->create($filename, 12, 'application/pdf'),
            $module,
            'TEST_SUPPORT',
        );

        return (string) $document->upload_token;
    }

    public function attachSupportingDocument($user, string $module, string $entityType, int $entityId, string $purpose = 'TEST_SUPPORT'): void
    {
        $token = $this->supportingDocumentToken($user, $module);
        app(SupportingDocumentService::class)->claimTokens($user, [$token], $module, $entityType, $entityId, $purpose);
    }
}
