<?php

/**
 * Auth Layout Template
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% yield 'title' 'KickersCup' %}</title>

    <!-- Favicon -->
    <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon">

    <!-- CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="{{ asset('css/main.css') }}">
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}">

    <!-- Custom Head Content -->
    {% yield 'head' %}

    <style>
        /* Inline Auth Styles */
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .auth-container {
            flex-grow: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 2rem 1rem;
        }

        .auth-box {
            background-color: #fff;
            border-radius: 0.5rem;
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.1);
            padding: 2rem;
            width: 100%;
            max-width: 480px;
        }

        .auth-title {
            text-align: center;
            margin-bottom: 1.5rem;
            color: #343a40;
        }

        .auth-form .form-group {
            margin-bottom: 1.25rem;
        }

        .auth-links {
            text-align: center;
            margin-top: 1.5rem;
        }

        .auth-links a {
            color: #007bff;
        }

        .alert {
            margin-bottom: 1.5rem;
        }

        .btn-block {
            padding: 0.75rem;
        }
    </style>
</head>
<body>
<!-- Header -->
<header class="bg-dark text-white">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center py-3">
            <a href="{{ url('home') }}" class="text-white text-decoration-none">
                <h1 class="h4 m-0">KickersCup</h1>
            </a>
        </div>
    </div>
</header>

<!-- Main Content -->
<main>
    {% yield 'content' %}
</main>

<!-- Footer -->
<footer class="bg-dark text-white py-4 mt-auto">
    <div class="container">
        <div class="row">
            <div class="col-md-6">
                <p>&copy; {{ date('Y') }} KickersCup. Alle Rechte vorbehalten.</p>
            </div>
            <div class="col-md-6 text-md-right">
                <a href="{{ url('terms') }}" class="text-white mr-3">AGB</a>
                <a href="{{ url('privacy') }}" class="text-white mr-3">Datenschutz</a>
                <a href="{{ url('imprint') }}" class="text-white">Impressum</a>
            </div>
        </div>
    </div>
</footer>

<!-- JavaScript -->
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Custom Scripts -->
{% yield 'scripts' %}
</body>
</html>