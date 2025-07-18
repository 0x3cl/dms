<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D-Track | Document Tracker</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #073263;
            color: #e1e7f0;
        }

        .btn-primary {
            background-color: #2a4a8b;
            border: none;
        }

        .btn-primary:hover {
            background-color: #4064a9;
        }

        .feature-icon {
            font-size: 2.5rem;
            color: #2a4a8b;
        }

        footer {
            background-color: #051e40;
            color: #ccc;
        }

        @media (max-width: 768px) {
            .feature-icon {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>

    <!-- HERO SECTION -->
    <section class="text-center py-5" style="background: linear-gradient(to right, #073263, #051e40);">
        <div class="container px-3">
            <img src="{{ asset('ptri.png') }}" alt="PTRI Logo" width="80" class="mb-3 img-fluid">
            <h1 class="display-5 fw-bold">Welcome to DocTrack</h1>
            <p class="lead">A smarter, faster, and secure way to track documents at DOST - PTRI</p>
            <a href="{{ route('login') }}" class="btn btn-lg btn-primary mt-3 px-4">Login to Start Tracking</a>
        </div>
    </section>

    <!-- FEATURES SECTION -->
    <section class="py-5 bg-light text-dark">
        <div class="container">
            <h2 class="text-center mb-5 fw-bold">Why Use DocTrack?</h2>
            <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-4 text-center">
                <div class="col">
                    <div class="feature-icon mb-2">📷</div>
                    <h5>QR Code Scanning</h5>
                    <p>Instantly view a document's history by scanning its QR code.</p>
                </div>
                <div class="col">
                    <div class="feature-icon mb-2">📍</div>
                    <h5>Real-Time Tracking</h5>
                    <p>Track documents as they move between departments and sections.</p>
                </div>
                <div class="col">
                    <div class="feature-icon mb-2">📁</div>
                    <h5>Secure Uploads</h5>
                    <p>Upload and manage files with secure access control.</p>
                </div>
                <div class="col">
                    <div class="feature-icon mb-2">📊</div>
                    <h5>Smart Logs</h5>
                    <p>Monitor document status and download history with ease.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- QUOTE SECTION -->
    <section class="text-center py-5" style="background-color: #051e40;">
        <div class="container px-3">
            <blockquote class="blockquote fs-5 fst-italic text-light mb-0">
                “Since using D-Track, our team has improved efficiency in routing and tracking documents.”
            </blockquote>
            <figcaption class="blockquote-footer text-muted mt-2">
                PTRI Staff Member
            </figcaption>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="text-center py-4">
        <div class="container">
            <img src="{{ asset('ptri.png') }}" alt="PTRI Logo" width="40" class="mb-2 img-fluid">
            <p class="mb-0 small">© {{ date('Y') }} DOST - PTRI | All rights reserved.</p>
            <p class="mb-0 small">Developed by your team using Laravel + Bootstrap 5</p>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
