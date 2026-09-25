<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page Not Found - {{ config('app.name', 'Eye Clinic') }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; }
        * { box-sizing: border-box; }
        body {
            align-items: center;
            background: #f4f6f9;
            color: #263238;
            display: flex;
            justify-content: center;
            margin: 0;
            min-height: 100vh;
            padding: 24px;
        }
        .error-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .1);
            max-width: 520px;
            padding: 40px;
            text-align: center;
            width: 100%;
        }
        .code { color: #dc3545; font-size: 64px; font-weight: 800; line-height: 1; }
        h1 { font-size: 24px; margin: 16px 0 8px; }
        p { color: #65727a; line-height: 1.55; margin: 0 0 28px; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; }
        .button {
            background: #007bff;
            border: 1px solid #007bff;
            border-radius: 7px;
            color: #fff;
            cursor: pointer;
            display: inline-block;
            font-size: 15px;
            padding: 11px 18px;
            text-decoration: none;
        }
        .button-secondary { background: #fff; color: #34495e; border-color: #c7cdd1; }
    </style>
</head>
<body>
    <main class="error-card">
        <div class="code">404</div>
        <h1>Page not found</h1>
        <p>The page may have moved or is no longer available. Your data has not been affected.</p>

        <div class="actions">
            <button class="button button-secondary" type="button" onclick="goBack()">Go Back</button>
            @auth
                <a class="button" href="{{ route('dashboard') }}">Return to Dashboard</a>
            @else
                <a class="button" href="{{ route('login') }}">Return to Login</a>
            @endauth
        </div>
    </main>

    <script>
        function goBack() {
            if (window.history.length > 1) {
                window.history.back();
                return;
            }

            window.location.assign(@json(auth()->check() ? route('dashboard') : route('login')));
        }
    </script>
</body>
</html>
