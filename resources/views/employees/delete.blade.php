<?php 
require '../db_connect.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    die("Invalid Request.");
}

if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    $stmt = $pdo->prepare("DELETE FROM sections WHERE section_id = ?");
    $stmt->execute([$id]);
    header("Location:index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" style="height: 100%; background-color: #073263;">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Delete Section</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />

    <!-- Google Fonts: Poppins -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet" />

    <style>
        body {
            background-color: #073263;
            color: #e1e7f0;
            font-family: 'Poppins', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }

        .delete-box {
            background-color: #051e40;
            padding: 2rem 2.5rem;
            border-radius: 1rem;
            box-shadow: 0 6px 15px rgba(0,0,0,0.5);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }

        h1, h2 {
            color: #d7e2f3;
        }

        .btn-danger {
            background-color: #d9534f;
            border: none;
        }

        .btn-danger:hover {
            background-color: #c9302c;
        }

        .btn-secondary {
            background-color: #4d648d;
            border: none;
            color: #fff;
        }

        footer {
            margin-top: 2rem;
            font-size: 0.9rem;
            color: #94a8c9;
        }
    </style>
</head>
<body>

<div class="delete-box">
    <h1>Department of Science and Technology</h1>
    <h2 class="mt-4">Confirm Deletion</h2>
    <p class="mb-4">Are you sure you want to delete this section?</p>

    <div class="d-flex justify-content-center gap-3">
        <a href="?id=<?= htmlspecialchars($id) ?>&confirm=yes" class="btn btn-danger">Yes, Delete</a>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>

    <footer class="mt-4">
        &copy; <?= date('Y'); ?> Department of Science and Technology. All Rights Reserved.
    </footer>
</div>

<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
