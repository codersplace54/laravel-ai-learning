<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <title>
        {{ $knowledge['service_name'] ?? 'Service Knowledge Guide' }}
    </title>

    <style>
        @page {
            margin: 22mm 17mm 20mm 17mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #222222;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5pt;
            line-height: 1.55;
            word-wrap: break-word;
        }

        .cover-page {
            text-align: center;
            padding-top: 110px;
            page-break-after: always;
        }

        .cover-label {
            margin-bottom: 24px;
            color: #6b7280;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }

        .cover-title {
            margin: 0 0 20px;
            color: #7b1e1e;
            font-size: 29px;
            line-height: 1.25;
        }

        .cover-department {
            margin-bottom: 40px;
            color: #444444;
            font-size: 14px;
        }

        .cover-meta {
            display: inline-block;
            min-width: 300px;
            padding: 18px 25px;
            border: 1px solid #dddddd;
            background: #fafafa;
            text-align: left;
        }

        .cover-meta p {
            margin: 6px 0;
        }

        .notice {
            margin-top: 55px;
            color: #777777;
            font-size: 9px;
        }

        .contents-page {
            page-break-after: always;
        }

        .contents-page h1 {
            color: #7b1e1e;
        }

        .contents-page ol {
            padding-left: 24px;
        }

        .contents-page li {
            margin-bottom: 8px;
        }

        .section {
            page-break-before: always;
        }

        .section-label {
            margin-bottom: 14px;
            padding: 8px 11px;
            border-left: 4px solid #7b1e1e;
            background: #f5eeee;
            color: #7b1e1e;
            font-size: 10px;
            font-weight: bold;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 18px;
            color: #7b1e1e;
            font-size: 23px;
            line-height: 1.25;
        }

        h2 {
            margin: 24px 0 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #dddddd;
            color: #333333;
            font-size: 16px;
        }

        h3 {
            margin: 18px 0 8px;
            color: #444444;
            font-size: 13px;
        }

        h4 {
            margin: 15px 0 6px;
            color: #555555;
            font-size: 11px;
        }

        p {
            margin: 7px 0 11px;
        }

        ul,
        ol {
            margin: 7px 0 13px;
            padding-left: 25px;
        }

        li {
            margin-bottom: 5px;
        }

        code {
            padding: 2px 4px;
            background: #f1f1f1;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 9px;
        }

        pre {
            padding: 12px;
            border: 1px solid #dddddd;
            background: #f7f7f7;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: DejaVu Sans Mono, monospace;
            font-size: 8.5px;
        }

        blockquote {
            margin: 15px 0;
            padding: 10px 15px;
            border-left: 4px solid #cccccc;
            background: #f8f8f8;
        }

        table {
            width: 100%;
            margin: 12px 0;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 7px;
            border: 1px solid #cccccc;
            vertical-align: top;
            text-align: left;
        }

        th {
            background: #f1f1f1;
        }

        .section-footer {
            margin-top: 25px;
            padding-top: 8px;
            border-top: 1px solid #eeeeee;
            color: #888888;
            font-size: 8px;
        }
    </style>
</head>

<body>

    {{-- Cover page --}}
    <div class="cover-page">
        <div class="cover-label">
            SWAAGAT Service Knowledge Preview
        </div>

        <h1 class="cover-title">
            {{ $knowledge['service_name'] ?? 'Service Guide' }}
        </h1>

        <div class="cover-department">
            {{ $knowledge['department_name'] ?? 'Department not configured' }}
        </div>

        <div class="cover-meta">
            <p>
                <strong>Service ID:</strong>
                {{ $knowledge['service_id'] ?? '-' }}
            </p>

            <p>
                <strong>Department ID:</strong>
                {{ $knowledge['department_id'] ?? '-' }}
            </p>

            <p>
                <strong>Total sections:</strong>
                {{ $knowledge['total_sections'] ?? count($sections) }}
            </p>

            <p>
                <strong>Source updated:</strong>
                {{ $knowledge['source_updated_at'] ?? 'Not available' }}
            </p>

            <p>
                <strong>Generated:</strong>
                {{ now()->format('d M Y, h:i A') }}
            </p>
        </div>

        <div class="notice">
            This PDF is generated from the latest published service
            configuration for review and testing.
        </div>
    </div>

    {{-- Contents --}}
    <div class="contents-page">
        <h1>Contents</h1>

        <ol>
            @foreach ($sections as $section)
                <li>
                    {{ $section['section_title'] ?? $section['title'] }}
                </li>
            @endforeach
        </ol>
    </div>

    {{-- Knowledge sections --}}
    @foreach ($sections as $section)
        <div class="section">

            <div class="section-label">
                {{ str_replace(
                    '_',
                    ' ',
                    $section['section_type'] ?? 'section'
                ) }}
            </div>

            {!! $section['html'] !!}

            <div class="section-footer">
                Knowledge key:
                {{ $section['knowledge_key'] ?? '-' }}

                <br>

                Content hash:
                {{ $section['content_hash'] ?? '-' }}
            </div>
        </div>
    @endforeach

</body>
</html>