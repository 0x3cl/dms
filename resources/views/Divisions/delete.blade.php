<?php 
require '../db_connect.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    die("Invalid Request.");
}

if (isset($_POST['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM departments WHERE department_id = ?");
    $stmt->execute([$id]);
    header("Location: index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE department_id = ?");
$stmt->execute([$id]);
$department = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$department) {
    die("Department not found.");
}
?>
<!DOCTYPE html>
<html lang="en" style="background-color: #073263;">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Delete Department</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <!-- Poppins font -->
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
            padding: 2rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.4);
            max-width: 600px;
            margin: auto;
        }

        h1, h2 {
            color: #d7e2f3;
            text-align: center;
        }

        strong {
            color: #a0b9d9;
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

        .btn-group {
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
    <h2 class="mb-4">Confirm Deletion</h2>

    <p class="text-center">
        <strong>Department Name:</strong> <?php echo htmlspecialchars($department['department_name']); ?>
    </p>

    <form method="POST" class="btn-group">
        <button type="submit" name="delete" class="btn btn-danger">Yes, Delete</button>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>

</body>
</html>
