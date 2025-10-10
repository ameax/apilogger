<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Log #{{ $apiLog->id }} - {{ $apiLog->method }} {{ $apiLog->endpoint }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #1f2937;
            background: #f9fafb;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
        }

        .header h1 {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }

        .header .meta {
            opacity: 0.9;
            font-size: 0.875rem;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.875rem;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .status-success { background: #d1fae5; color: #065f46; }
        .status-warning { background: #fef3c7; color: #92400e; }
        .status-danger { background: #fee2e2; color: #991b1b; }
        .status-info { background: #dbeafe; color: #1e40af; }

        .content {
            padding: 2rem;
        }

        .section {
            margin-bottom: 2rem;
        }

        .section:last-child {
            margin-bottom: 0;
        }

        .section h2 {
            font-size: 1.25rem;
            color: #374151;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e5e7eb;
        }

        .field {
            margin-bottom: 1rem;
        }

        .field-label {
            font-weight: 600;
            color: #6b7280;
            font-size: 0.875rem;
            margin-bottom: 0.25rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .field-value {
            color: #1f2937;
            padding: 0.5rem;
            background: #f9fafb;
            border-radius: 4px;
            word-break: break-word;
        }

        .field-value.empty {
            color: #9ca3af;
            font-style: italic;
        }

        pre {
            background: #1f2937;
            color: #f9fafb;
            padding: 1rem;
            border-radius: 6px;
            overflow-x: auto;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        pre code {
            font-family: 'Courier New', Courier, monospace;
        }

        .json-key { color: #93c5fd; }
        .json-string { color: #86efac; }
        .json-number { color: #fbbf24; }
        .json-boolean { color: #c084fc; }
        .json-null { color: #f87171; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        table th {
            background: #f9fafb;
            font-weight: 600;
            color: #6b7280;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        table tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-get { background: #dbeafe; color: #1e40af; }
        .badge-post { background: #d1fae5; color: #065f46; }
        .badge-put { background: #fef3c7; color: #92400e; }
        .badge-patch { background: #fef3c7; color: #92400e; }
        .badge-delete { background: #fee2e2; color: #991b1b; }

        .direction-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
        }

        .direction-inbound { background: #dbeafe; color: #1e40af; }
        .direction-outbound { background: #d1fae5; color: #065f46; }

        .footer {
            padding: 1.5rem 2rem;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            font-size: 0.875rem;
            color: #6b7280;
            text-align: center;
        }

        @media print {
            body {
                padding: 0;
                background: white;
            }

            .container {
                box-shadow: none;
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 1rem;
            }

            .header,
            .content {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>API Log #{{ $apiLog->id }}</h1>
            <div class="meta">
                <div style="margin-bottom: 0.5rem;">
                    <span class="badge badge-{{ strtolower($apiLog->method) }}">{{ $apiLog->method }}</span>
                    <span>{{ $apiLog->endpoint }}</span>
                </div>
                <div>{{ $apiLog->created_at->format('Y-m-d H:i:s') }}</div>
            </div>
            <span class="status-badge status-{{ $apiLog->status_color }}">
                {{ $apiLog->response_code }} - {{ $apiLog->formatted_response_time }}
            </span>
        </div>

        <div class="content">
            <!-- Request Information -->
            <div class="section">
                <h2>Request Information</h2>
                <table>
                    <tr>
                        <th style="width: 200px;">Direction</th>
                        <td>
                            <span class="direction-badge direction-{{ $apiLog->direction }}">
                                {{ ucfirst($apiLog->direction) }}
                            </span>
                        </td>
                    </tr>
                    @if($apiLog->service)
                    <tr>
                        <th>Service</th>
                        <td>{{ $apiLog->service }}</td>
                    </tr>
                    @endif
                    @if($apiLog->correlation_identifier)
                    <tr>
                        <th>Correlation ID</th>
                        <td><code>{{ $apiLog->correlation_identifier }}</code></td>
                    </tr>
                    @endif
                    @if($apiLog->retry_attempt > 0)
                    <tr>
                        <th>Retry Attempt</th>
                        <td>{{ $apiLog->retry_attempt }}</td>
                    </tr>
                    @endif
                    <tr>
                        <th>IP Address</th>
                        <td>{{ $apiLog->ip_address ?? '—' }}</td>
                    </tr>
                    @if($apiLog->user_identifier)
                    <tr>
                        <th>User</th>
                        <td>{{ $apiLog->user_identifier }}</td>
                    </tr>
                    @endif
                    @if($apiLog->user_agent)
                    <tr>
                        <th>User Agent</th>
                        <td style="word-break: break-all;">{{ $apiLog->user_agent }}</td>
                    </tr>
                    @endif
                </table>
            </div>

            <!-- Request Parameters -->
            @if($apiLog->request_parameters && !empty($apiLog->request_parameters))
            <div class="section">
                <h2>Request Parameters</h2>
                <pre><code>{!! $formatJson($apiLog->request_parameters) !!}</code></pre>
            </div>
            @endif

            <!-- Request Headers -->
            @if($apiLog->request_headers && !empty($apiLog->request_headers))
            <div class="section">
                <h2>Request Headers</h2>
                <table>
                    @foreach($apiLog->request_headers as $key => $value)
                    <tr>
                        <th style="width: 250px;">{{ $key }}</th>
                        <td>{{ is_array($value) ? implode(', ', $value) : $value }}</td>
                    </tr>
                    @endforeach
                </table>
            </div>
            @endif

            <!-- Request Body -->
            @if($apiLog->request_body && !empty($apiLog->request_body))
            <div class="section">
                <h2>Request Body</h2>
                <pre><code>{!! $formatJson($apiLog->request_body) !!}</code></pre>
            </div>
            @endif

            <!-- Response Information -->
            <div class="section">
                <h2>Response Information</h2>
                <table>
                    <tr>
                        <th style="width: 200px;">Status Code</th>
                        <td><span class="status-badge status-{{ $apiLog->status_color }}">{{ $apiLog->response_code }}</span></td>
                    </tr>
                    <tr>
                        <th>Response Time</th>
                        <td>{{ $apiLog->formatted_response_time }}</td>
                    </tr>
                    <tr>
                        <th>Request Size</th>
                        <td>{{ $apiLog->request_size > 1024 ? round($apiLog->request_size / 1024, 2) . ' KB' : $apiLog->request_size . ' B' }}</td>
                    </tr>
                    <tr>
                        <th>Response Size</th>
                        <td>{{ $apiLog->response_size > 1024 ? round($apiLog->response_size / 1024, 2) . ' KB' : $apiLog->response_size . ' B' }}</td>
                    </tr>
                </table>
            </div>

            <!-- Response Headers -->
            @if($apiLog->response_headers && !empty($apiLog->response_headers))
            <div class="section">
                <h2>Response Headers</h2>
                <table>
                    @foreach($apiLog->response_headers as $key => $value)
                    <tr>
                        <th style="width: 250px;">{{ $key }}</th>
                        <td>{{ is_array($value) ? implode(', ', $value) : $value }}</td>
                    </tr>
                    @endforeach
                </table>
            </div>
            @endif

            <!-- Response Body -->
            @if($apiLog->response_body && !empty($apiLog->response_body))
            <div class="section">
                <h2>Response Body</h2>
                <pre><code>{!! $formatJson($apiLog->response_body) !!}</code></pre>
            </div>
            @endif

            <!-- Comment -->
            @if($apiLog->comment)
            <div class="section">
                <h2>Comment</h2>
                <div class="field-value">{{ $apiLog->comment }}</div>
            </div>
            @endif

            <!-- Metadata -->
            @if($apiLog->metadata && !empty($apiLog->metadata))
            <div class="section">
                <h2>Metadata</h2>
                <pre><code>{!! $formatJson($apiLog->metadata) !!}</code></pre>
            </div>
            @endif
        </div>

        <div class="footer">
            Exported from Kocher API Logger on {{ now()->format('Y-m-d H:i:s') }}
        </div>
    </div>
</body>
</html>
