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

            @if (session('skipped_rows'))
                <div class="alert alert-warning mt-3">
                    <strong>Skipped rows:</strong>
                    <ul class="mb-0">
                        @foreach (session('skipped_rows') as $row)
                            <li>
                                Noc_master_id: {{ $row['noc_master_id'] ?? 'N/A' }},
                                Row: {{ $row['row'] ?? 'N/A' }},
                                NOC ID: {{ $row['noc_id'] ?? 'N/A' }},
                                Old User ID: {{ $row['old_user_id'] ?? 'N/A' ?? 'N/A' }},
                                Reason: {{ $row['reason'] ?? 'N/A' }}
                            </li>
                        @endforeach
                    </ul>
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
                <form action="{{ route('admin.import.service_applications') }}" method="POST" enctype="multipart/form-data">
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
