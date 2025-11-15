<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Certificate</title>

    <style>
        /* Uniform margin on EVERY page */
        @page {
            margin: 20mm 15mm;   /* top/bottom, left/right */
        }

        html, body {
            margin: 0;
            padding: 0;
        }

        /* Centered column so content is not stuck to the left */
        .content-wrapper {
            /* printable width = 210mm - 2*15mm = 180mm
               we keep it a bit narrower and center it */
            width: 150mm;
            margin: 0 auto;      /* centers horizontally */
        }

        .content {
            font-family: "DejaVu Sans", sans-serif;
            font-size: 11pt;
            line-height: 1.4;
            text-align: left;
            color: #000;

            margin: 0;
            padding: 0;
            white-space: normal;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .content img,
        .content table {
            max-width: 100%;
            height: auto;
        }

        /* Optional watermark – does not affect layout */
        .watermark {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            opacity: 0.05;
            width: 120mm;
            height: auto;
            z-index: 0;
        }
    </style>
</head>
<body>
    @php
        $wm_data_uri = null;

        if (!empty($add_watermark) && !empty($watermark_path) && is_file($watermark_path)) {
            $mime  = function_exists('mime_content_type')
                        ? mime_content_type($watermark_path)
                        : 'image/jpeg';
            $bytes = @file_get_contents($watermark_path);
            if ($bytes !== false) {
                $wm_data_uri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            }
        }
    @endphp

    @if($wm_data_uri)
        <img src="{{ $wm_data_uri }}" alt="Watermark" class="watermark">
    @endif

    <div class="content-wrapper">
        <div class="content">
            {!! $content !!}  {{-- BODY HTML ONLY --}}
        </div>
    </div>
</body>
</html>
