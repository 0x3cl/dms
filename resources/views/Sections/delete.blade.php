<?php 
require '../db_connect.php';

$id = $_GET['id'] ?? null;
if (!$id) {
    die("Invalid Request.");
}

// If user confirmed deletion
if (isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    $stmt = $pdo->prepare("DELETE FROM sections WHERE section_id = ?");
    $stmt->execute([$id]);
    header("Location:index.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" style="background-color: #073263;">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Delete Section</title>

    <!-- Bootstrap & Google Fonts -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet" />

    <style>
        body {
            background-color: #073263;
            color: #e1e7f0;
            font-family: 'Poppins', sans-serif;
            padding: 2rem;
        }

        .card-custom {
            background-color: #051e40;
            border: none;
            border-radius: 1rem;
            padding: 2.5rem;
            max-width: 600px;
            margin: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
        }

        .btn-danger {
            background-color: #d9534f;
            border: none;
            box-shadow: 0 4px 10px rgba(217, 83, 79, 0.4);
        }

        .btn-secondary {
            background-color: #4d648d;
            border: none;
        }

        footer {
            text-align: center;
            margin-top: 3rem;
            font-size: 0.9rem;
            color: #a9bcd7;
        }

        h1, h2 {
            color: #d7e2f3;
            text-align: center;
        }

        p {
            text-align: center;
            color: #b0c5e8;
        }

        .action-buttons {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 2rem;
        }
    </style>
</head>
<body>

    <div class="card card-custom">
        <h1>Department of Science and Technology</h1>
        <h2>Confirm Deletion</h2>
        <p>Are you sure you want to delete this section?</p>

        <div class="action-buttons">
            <a href="?id=<?= htmlspecialchars($id) ?>&confirm=yes" class="btn btn-danger">Yes, Delete</a>
            <a href="index.php" class="btn btn-secondary">Cancel</a>
        </div>
    </div>

    <footer>
        &copy; <?= date('Y'); ?> Department of Science and Technology. All Rights Reserved.
    </footer>

</body>
</html>
