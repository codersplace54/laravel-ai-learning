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
            @if (session('skipped_rows'))
                <div class="alert alert-warning mt-3">
                    <strong>Skipped rows:</strong>
                    <ul class="mb-0">
                        @foreach (session('skipped_rows') as $row)
                            <li>
                                Row: {{ $row['row_index'] }},
                                UID: {{ $row['uid'] ?? 'N/A' }},
                                Mobile: {{ $row['mobile_no'] ?? 'N/A' }},
                                Reason: {{ $row['reason'] }}
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

        @if (session('import_errors') && is_array(session('import_errors')))
            <div class="alert alert-warning">
                <strong>Some rows had issues:</strong>
                <ul class="mb-0">
                    @foreach (session('import_errors') as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-body">
                <form action="{{ route('admin.import.users') }}" method="POST" enctype="multipart/form-data">
                    @csrf

                    <div class="mb-3">
                        <label for="json_file" class="form-label">Upload JSON File</label>
                        <input type="file" name="json_file" id="json_file" class="form-control">
                        <small class="text-muted">
                            Allowed: <code>.json</code> or <code>.txt</code>.
                            If both file and text are provided, file will be used.
                        </small>
                        @error('json_file')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="json_text" class="form-label">Or Paste JSON Here</label>
                        <textarea name="json_text" id="json_text" rows="10" class="form-control">{{ old('json_text') }}</textarea>
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
