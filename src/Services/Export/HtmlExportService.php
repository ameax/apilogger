<?php

declare(strict_types=1);

namespace Ameax\ApiLogger\Services\Export;

use Ameax\ApiLogger\Models\ApiLog;

class HtmlExportService extends ApiLogExportService
{
    /**
     * Generate HTML export from ApiLog entry.
     */
    public function generate(ApiLog $apiLog): string
    {
        return view('apilogger::exports.api-log', [
            'apiLog' => $apiLog,
            'formatJson' => function ($data) {
                return $this->formatJsonForHtml($data);
            },
        ])->render();
    }

    /**
     * Format JSON data with syntax highlighting for HTML.
     *
     * @param  array<string, mixed>|null  $data
     */
    protected function formatJsonForHtml(?array $data): string
    {
        if (! $data) {
            return '';
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! $json) {
            return '';
        }

        // Simple syntax highlighting
        $json = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
        $json = preg_replace('/"([^"]+)"\s*:/', '<span class="json-key">"$1"</span>:', $json) ?? $json;
        $json = preg_replace('/:\s*"([^"]*)"/', ': <span class="json-string">"$1"</span>', $json) ?? $json;
        $json = preg_replace('/:\s*(\d+\.?\d*)/', ': <span class="json-number">$1</span>', $json) ?? $json;
        $json = preg_replace('/:\s*(true|false)/', ': <span class="json-boolean">$1</span>', $json) ?? $json;
        $json = preg_replace('/:\s*(null)/', ': <span class="json-null">$1</span>', $json) ?? $json;

        return $json;
    }
}
