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
                    @if(session('files_processed'))
                        <li>Files Processed: {{ session('files_processed', 0) }}</li>
                    @endif
                    @if(session('total_checked'))
                        <li>Total Records Checked: {{ session('total_checked', 0) }}</li>
                    @endif
                    <li>Records Updated: {{ session('updated_count', 0) }}</li>
                    @if(session('skipped_count'))
                        <li>Records Skipped: {{ session('skipped_count', 0) }}</li>
                    @endif
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
                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">Correct Imported File Paths</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Fix file paths from old import data to proper URLs</p>
                        <form action="{{ route('admin.correction.all_file_paths') }}" method="POST" onsubmit="return confirm('This will update all file paths. Continue?');">
                            @csrf
                            <button type="submit" class="btn btn-warning w-100">Correct Imported File Paths</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card shadow-sm border-info">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Remove Base URL from Files</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Convert to: <code>storage/sites/default/files/filename.jpg</code></p>
                        <form action="{{ route('admin.correction.normalize_paths') }}" method="POST" onsubmit="return confirm('Normalize all paths?');">
                            @csrf
                            <button type="submit" class="btn btn-info w-100">Remove Base URL from Files</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card shadow-sm border-dark">
                    <div class="card-header bg-dark text-white">
                        <h5 class="mb-0">Fix Incorrectly Normalized Paths</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Revert paths for records where old_id is null</p>
                        <form action="{{ route('admin.correction.fix_incorrectly_normalized_paths') }}" method="POST" onsubmit="return confirm('Fix incorrectly normalized paths?');">
                            @csrf
                            <button type="submit" class="btn btn-dark w-100">Fix Incorrectly Normalized Paths</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

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
                <div class="card shadow-sm border-danger">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">Fix Partner Dates (Excel Serial Numbers)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Converts Excel date numbers (35051, 45034) to proper dates (Y-m-d format)</p>
                        <form action="{{ route('admin.correction.fix_partner_dates') }}" method="POST" onsubmit="return confirm('Fix all partner dates?');">
                            @csrf
                            <button type="submit" class="btn btn-danger w-100">Fix Partner Dates</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card shadow-sm border-warning">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">Update Workflow Columns</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Fix wrong workflow columns in assignment and history tables using correct first step flow data</p>
                        <form action="{{ route('admin.update.workflow_columns') }}" method="POST" onsubmit="return confirm('Update workflow columns for all records?');">
                            @csrf
                            <button type="submit" class="btn btn-warning w-100">Update Workflow Columns</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
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

            <div class="col-md-6 mb-4">
                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Update Partnership Application NOC Certificate</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.correction.partnership_application_noc_certificate') }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div class="mb-3">
                                <label for="noc_files" class="form-label">Upload Excel Files</label>
                                <input type="file" name="files[]" id="noc_files" class="form-control" multiple required>
                                <small class="text-muted">Excel with nid & field_certificate columns</small>
                            </div>
                            <button type="submit" class="btn btn-secondary w-100">Update Partnership NOC Certificates</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>
