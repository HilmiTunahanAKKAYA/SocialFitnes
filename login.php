<?php
session_start();
require_once __DIR__ . '/db_connection.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uniqueId = trim($_POST['unique_id'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($uniqueId === '' || $password === '') {
        $error = 'Lütfen Benzersiz ID ve şifrenizi girin.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, unique_id, password_hash, group_id FROM users WHERE unique_id = :unique_id LIMIT 1');
        $stmt->execute(['unique_id' => $uniqueId]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['unique_id'] = $user['unique_id'];
            $_SESSION['group_id'] = (int) $user['group_id'];

            header('Location: dashboard.php');
            exit;
        }

        $error = 'Benzersiz ID veya şifre hatalı.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giriş - Social Fitness</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Giriş Yap</h1>
                    <p class="text-muted">Benzersiz ID formatı: Username#123456</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= h($error) ?></div>
                    <?php endif; ?>

                    <form method="post">
                        <div class="mb-3">
                            <label class="form-label" for="unique_id">Benzersiz ID</label>
                            <input class="form-control" type="text" id="unique_id" name="unique_id" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Şifre</label>
                            <input class="form-control" type="password" id="password" name="password" required>
                        </div>
                        <button class="btn btn-success w-100" type="submit">Giriş Yap</button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="register.php">Hesabın yok mu? Kayıt ol</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
