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
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Delete Department</title>
</head>
<body>

    <h1>Department of Science and Technology</h1>

    <h2>Are you sure you want to delete this department?</h2>

    <p><strong>Department Name:</strong> <?php echo htmlspecialchars($department['department_name']); ?></p>

    <form method="POST">
        <button type="submit" name="delete">Yes, Delete</button>
    </form>

    <p>
        <button onclick="window.location.href='index.php'">Cancel</button>
    </p>

</body>
</html>
