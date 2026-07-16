<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Services\Ai\ServiceKnowledgeDocumentService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;

class ServiceKnowledgePdfController extends Controller
{
    public function __construct(
        private ServiceKnowledgeDocumentService $document_service
    ) {}

    public function show(int $service_id)
    {
        $knowledge = $this->document_service->build($service_id);

        if (!$knowledge) {
            abort(404, 'Service not found.');
        }

        $sections = collect(
            $knowledge['sections'] ?? []
        )->map(function (array $section) {
            $content = trim(
                (string) ($section['content'] ?? '')
            );

            /*
             * Convert generated Markdown into HTML.
             *
             * The fallback still creates a readable PDF
             * if Str::markdown is unavailable.
             */
            if (method_exists(Str::class, 'markdown')) {
                $section['html'] = (string) Str::markdown(
                    $content,
                    [
                        'html_input' => 'strip',
                        'allow_unsafe_links' => false,
                    ]
                );
            } else {
                $section['html'] = nl2br(
                    e($content)
                );
            }

            return $section;
        })->values()->toArray();

        $pdf = Pdf::loadView(
            'ai.service_knowledge_pdf',
            [
                'knowledge' => $knowledge,
                'sections' => $sections,
            ]
        )->setPaper('a4', 'portrait');

        return $pdf->stream(
            "service-{$service_id}-knowledge-preview.pdf"
        );
    }
}