<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Import Users (JSON)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>

<body class="bg-light">

    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">Import Users (JSON)</h1>
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
                        'pan_missing' => 'PAN missing',
                        'duplicate_mobile_in_json' => 'Duplicate mobile in JSON',
                        'invalid_object' => 'Invalid object / row format',
                        'insert_or_ignore_skipped_due_to_db_unique' => 'Skipped due to DB unique constraint',
                        'unknown' => 'Unknown',
                    ];

                    $grouped = session('skipped_grouped', []);
                @endphp

                <div class="alert alert-warning mt-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <strong>Skipped Rows</strong>
                        <span class="badge bg-dark">Total: {{ session('skipped_count') }}</span>
                    </div>

                    <div class="accordion mt-3" id="skippedUsersAccordion">
                        @foreach ($grouped as $reason_key => $group)
                            @php
                                $title = $reason_labels[$reason_key] ?? $reason_key;
                                $collapse_id = 'u_collapse_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $reason_key);
                                $heading_id = 'u_heading_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $reason_key);
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
                                    aria-labelledby="{{ $heading_id }}" data-bs-parent="#skippedUsersAccordion">

                                    <div class="accordion-body">
                                        <ul class="mb-0">
                                            @foreach ($group['rows'] as $row)
                                                <li class="mb-1">
                                                    Row Index: <strong>{{ $row['row_index'] ?? 'N/A' }}</strong>,
                                                    UID: {{ $row['uid'] ?? 'N/A' }},
                                                    Mobile: {{ $row['mobile_no'] ?? 'N/A' }},
                                                    Mobile: {{ $row['user_name'] ?? 'N/A' }},
                                                    Reason: {{ $row['reason'] ?? 'N/A' }}

                                                    @if (!empty($row['missing_fields']) && is_array($row['missing_fields']))
                                                        , Missing: <code>{{ implode(', ', $row['missing_fields']) }}</code>
                                                    @endif

                                                    @if (!empty($row['count']))
                                                        , Count: <strong>{{ $row['count'] }}</strong>
                                                    @endif

                                                    @if (!empty($row['unique_values']) && is_array($row['unique_values']))
                                                        <br><strong>Conflicting Values:</strong>
                                                        <ul class="mt-1 mb-0">
                                                            @foreach ($row['unique_values'] as $values)
                                                                <li>
                                                                    Mobile: {{ $values['mobile_no'] ?? 'N/A' }}, 
                                                                    Email: {{ $values['email_id'] ?? 'N/A' }}, 
                                                                    Old ID: {{ $values['old_id'] ?? 'N/A' }}
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
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
                <form action="{{ route('admin.import.users') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <label for="json_files" class="form-label">Upload JSON Files</label>
                        <input type="file" name="json_files[]" id="json_files" class="form-control" multiple>
                        <small class="text-muted">
                            Allowed: <code>.json</code> or <code>.txt</code>. You can select multiple files.
                            If both files and text are provided, files will be used.
                        </small>
                        @error('json_files.*')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="json_text" class="form-label">Or Paste JSON Here</label>
                        <textarea name="json_text" id="json_text" rows="10"
                            class="form-control">{{ old('json_text') }}</textarea>
                        @error('json_text')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-primary">
                        Import Users
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
