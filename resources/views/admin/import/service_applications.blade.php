<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Import NOC Applications (Excel)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>

<body class="bg-light">

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">Import NOC Applications (Excel)</h1>
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
                        'missing_required_fields' => 'Missing required fields',
                        'service_not_found' => 'Service ID not found / not mapped',
                        'user_not_found' => 'User not found / not mapped',
                        'status_not_mapped' => 'Status not mapped',
                        'unknown' => 'Unknown',
                    ];

                    $grouped = session('skipped_grouped', []);
                @endphp

                <div class="alert alert-warning mt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong>Skipped Rows</strong>
                        <span class="badge bg-dark">Total: {{ session('skipped_count') }}</span>
                    </div>

                    <div class="accordion mt-3" id="skippedAccordion">
                        @foreach ($grouped as $reason_key => $group)
                            @php
                                $title = $reason_labels[$reason_key] ?? $reason_key;
                                $collapse_id = 'collapse_' . $reason_key;
                                $heading_id = 'heading_' . $reason_key;
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
                                                    NOC ID: {{ $row['noc_id'] ?? 'N/A' }},
                                                    Noc_master_id: {{ $row['noc_master_id'] ?? 'N/A' }},
                                                    Old User ID: {{ $row['old_user_id'] ?? 'N/A' }},
                                                    @if (!empty($row['raw_status']))
                                                        Raw Status: <code>{{ $row['raw_status'] }}</code>,
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
            @if (session('assignment_skipped_count', 0) > 0)
                @php
                    $assignment_reason_labels = [
                        'service_flow_not_found' => 'Service flow not found',
                        'status_not_eligible_for_assignment' => 'Status not eligible for assignment',
                        'validation_step_missing' => 'Validation step missing in service flow',
                        'unknown' => 'Unknown',
                    ];

                    $assignment_grouped = session('assignment_skipped_grouped', []);

                    $assignment_grouped_formatted = [];
                    foreach ($assignment_grouped as $reason_key => $rows) {
                        $assignment_grouped_formatted[$reason_key] = [
                            'count' => count($rows),
                            'rows' => $rows,
                        ];
                    }
                @endphp

                <div class="alert alert-warning mt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong>Assignment Skipped Rows</strong>
                        <span class="badge bg-dark">Total: {{ session('assignment_skipped_count') }}</span>
                    </div>

                    <div class="accordion mt-3" id="assignmentSkippedAccordion">
                        @foreach ($assignment_grouped_formatted as $reason_key => $group)
                            @php
                                $title = $assignment_reason_labels[$reason_key] ?? $reason_key;
                                $collapse_id = 'a_collapse_' . $reason_key;
                                $heading_id = 'a_heading_' . $reason_key;
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
                                    aria-labelledby="{{ $heading_id }}" data-bs-parent="#assignmentSkippedAccordion">
                                    <div class="accordion-body">
                                        <ul class="mb-0">
                                            @foreach ($group['rows'] as $r)
                                                <li class="mb-1">
                                                    Row: <strong>{{ $r['row'] ?? 'N/A' }}</strong>,
                                                    Old ID: {{ $r['old_id'] ?? 'N/A' }},
                                                    Service ID: {{ $r['service_id'] ?? 'N/A' }},
                                                    Status: {{ $r['status'] ?? 'N/A' }},
                                                    Reason: {{ $r['reason'] ?? 'N/A' }}
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
                <form action="{{ route('admin.import.service_applications') }}" method="POST"
                    enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <label for="excel_file" class="form-label">Upload Excel File</label>
                        <input type="file" name="excel_file" id="excel_file" class="form-control">
                        <small class="text-muted">
                            Allowed: <code>.xlsx</code>, <code>.xls</code>, <code>.csv</code>
                        </small>
                        @error('excel_file')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        Import NOC Applications
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
