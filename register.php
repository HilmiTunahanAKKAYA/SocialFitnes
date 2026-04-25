<?php
session_start();
require_once __DIR__ . '/db_connection.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function generateUniqueUserId(PDO $pdo, string $username): string
{
    $attempts = 0;

    do {
        $suffix = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $candidate = $username . '#' . $suffix;

        $stmt = $pdo->prepare('SELECT id FROM users WHERE unique_id = :unique_id LIMIT 1');
        $stmt->execute(['unique_id' => $candidate]);
        $exists = $stmt->fetchColumn();

        $attempts++;
    } while ($exists && $attempts < 30);

    if ($exists) {
        throw new RuntimeException('Benzersiz kullanıcı ID üretilemedi. Lütfen tekrar deneyin.');
    }

    return $candidate;
}

$errors = [];
$successUniqueId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $errors[] = 'Kullanıcı adı 3-30 karakter olmalı ve sadece harf, rakam veya alt çizgi içermelidir.';
    }

    if (strlen($password) < 6) {
        $errors[] = 'Şifre en az 6 karakter olmalıdır.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);

        if ($stmt->fetchColumn()) {
            $errors[] = 'Bu kullanıcı adı zaten alınmış.';
        }
    }

    if (!$errors) {
        try {
            $uniqueId = generateUniqueUserId($pdo, $username);
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare(
                'INSERT INTO users (username, unique_id, password_hash, group_id, total_points)
                 VALUES (:username, :unique_id, :password_hash, NULL, 0)'
            );

            $stmt->execute([
                'username' => $username,
                'unique_id' => $uniqueId,
                'password_hash' => $passwordHash,
            ]);
            $successUniqueId = $uniqueId;
        } catch (Throwable $e) {
            $errors[] = 'Kayıt başarısız: ' . $e->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kayıt Ol - Social Fitness</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-6">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h1 class="h4 mb-3">Hesap Oluştur</h1>
                    <p class="text-muted">Username#123456 formatında bir giriş ID'si alacaksınız.</p>

                    <?php if ($errors): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= h($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($successUniqueId): ?>
                        <div class="alert alert-success">
                            Kayıt başarılı. Benzersiz giriş ID'niz:
                            <strong><?= h($successUniqueId) ?></strong>
                            <br>
                            <a href="login.php" class="alert-link">Giriş sayfasına git</a>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="mt-3">
                        <div class="mb-3">
                            <label class="form-label" for="username">Kullanıcı Adı</label>
                            <input class="form-control" type="text" id="username" name="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Şifre</label>
                            <input class="form-control" type="password" id="password" name="password" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Kayıt Ol</button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="login.php">Zaten hesabın var mı? Giriş yap</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
