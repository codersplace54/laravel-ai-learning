<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Application Data Correction</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">Application Data Correction</h1>
            <a href="{{ url()->previous() }}" class="btn btn-outline-secondary btn-sm">← Back</a>
        </div>

        @if (session('success'))
            <div class="alert alert-success">
                <strong>{{ session('success') }}</strong>
                <ul class="mb-0 mt-2">
                    <li>Files Processed: {{ session('files_processed', 0) }}</li>
                    <li>Records Updated: {{ session('updated_count', 0) }}</li>
                    <li>Records Skipped: {{ session('skipped_count', 0) }}</li>
                </ul>
            </div>

            @if (session('skipped_count', 0) > 0)
                <div class="alert alert-warning">
                    <strong>Skipped Rows</strong>
                    <ul class="mb-0 mt-2">
                        @foreach (session('skipped_rows', []) as $row)
                            <li>Row: {{ $row['row'] ?? 'N/A' }}, Old ID: {{ $row['old_id'] ?? 'N/A' }}, Reason: {{ $row['reason'] ?? 'N/A' }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif

        @if (session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Update Partnership Application Data</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.correction.partnership_application') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label for="app_files" class="form-label">Upload Excel Files</label>
                                <input type="file" name="files[]" id="app_files" class="form-control" multiple required>
                                <small class="text-muted">Allowed: .xlsx, .xls, .csv</small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Update Application Data</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Update Partnership Partner Data</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.correction.partnership_partner') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label for="partner_files" class="form-label">Upload Excel Files</label>
                                <input type="file" name="files[]" id="partner_files" class="form-control" multiple required>
                                <small class="text-muted">Allowed: .xlsx, .xls, .csv</small>
                            </div>
                            <button type="submit" class="btn btn-success w-100">Update Partner Data</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
