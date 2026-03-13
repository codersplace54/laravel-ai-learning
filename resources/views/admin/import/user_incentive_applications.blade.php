<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Import User Incentive Applications (Excel)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>

<body class="bg-light">

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">Import User Incentive Applications (Excel)</h1>
            <a href="{{ url()->previous() }}" class="btn btn-outline-secondary btn-sm">
                ← Back
            </a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
            </div>

            @if (session('skipped_count', 0) > 0)
                @php
                    $reason_labels = [
                        'Missing required fields (NID or UserID)' => 'Missing required fields',
                        'User not found' => 'User not found / not mapped',
                        'Form name is empty' => 'Form name is empty',
                        'Proforma not found' => 'Proforma not found',
                        'Status not mapped' => 'Status not mapped',
                        'unknown' => 'Unknown',
                    ];

                    $grouped = collect(session('skipped_rows', []))
                        ->groupBy('reason')
                        ->map(fn($items) => [
                            'count' => $items->count(),
                            'rows' => $items->values()->all(),
                        ])
                        ->toArray();
                @endphp

                <div class="alert alert-warning mt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong>Skipped Rows</strong>
                        <span class="badge bg-dark">Total: {{ session('skipped_count') }}</span>
                    </div>

                    <div class="accordion mt-3" id="skippedAccordion">
                        @foreach ($grouped as $reason => $group)
                            @php
                                $title = $reason_labels[$reason] ?? $reason;
                                $collapse_id = 'collapse_' . md5($reason);
                                $heading_id = 'heading_' . md5($reason);
                            @endphp

                            <div class="accordion-item">
                                <h2 class="accordion-header" id="{{ $heading_id }}">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                                        data-bs-target="#{{ $collapse_id }}" aria-expanded="false"
                                        aria-controls="{{ $collapse_id }}">
                                        {{ $title }}
                                        <span class="ms-2 badge bg-secondary">{{ $group['count'] }}</span>
                                    </button>
                                </h2>

                                <div id="{{ $collapse_id }}" class="accordion-collapse collapse"
                                    aria-labelledby="{{ $heading_id }}" data-bs-parent="#skippedAccordion">

                                    <div class="accordion-body">
                                        <ul class="mb-0">
                                            @foreach ($group['rows'] as $row)
                                                <li class="mb-1">
                                                    Row: <strong>{{ $row['row'] ?? 'N/A' }}</strong>,
                                                    NID: {{ $row['nid'] ?? 'N/A' }},
                                                    User ID: {{ $row['user_id'] ?? 'N/A' }},
                                                    @if (!empty($row['form_name']))
                                                        Form: {{ $row['form_name'] }},
                                                    @endif
                                                    @if (!empty($row['status']))
                                                        Status: {{ $row['status'] }},
                                                    @endif
                                                    Reason: {{ $row['reason'] ?? 'N/A' }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        @endif


        @if (session('error'))
            <div class="alert alert-danger">
                {{ session('error') }}
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-body">
                <form action="{{ route('admin.import.user_incentive_applications') }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <label for="excel_file" class="form-label">Upload Excel File</label>
                        <input type="file" name="excel_file" id="excel_file" class="form-control" required>
                        <small class="text-muted">
                            Allowed: <code>.xlsx</code>, <code>.xls</code>, <code>.csv</code>
                        </small>
                        @error('excel_file')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        Import User Incentive Applications
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous">
    </script>

</body>

</html>
