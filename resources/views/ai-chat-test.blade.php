<!DOCTYPE html>
<html>
<head>
    <title>SWAAGAT AI Chat</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="min-h-screen bg-slate-100">
    <div class="p-10">
        <!-- <h1 class="text-2xl font-bold">SWAAGAT AI Chat Test</h1> -->
        <p class="mt-2 text-slate-600">Click the AI button at bottom-right.</p>
    </div>

    <x-swaagat-ai-chat />
</body>
</html>