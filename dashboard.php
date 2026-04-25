<?php
session_start();
require_once __DIR__ . '/db_connection.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash_' . $type] = $message;
}

function getFlash(string $type): ?string
{
    $key = 'flash_' . $type;
    if (!isset($_SESSION[$key])) {
        return null;
    }

    $message = $_SESSION[$key];
    unset($_SESSION[$key]);
    return $message;
}

$currentUserId = (int) $_SESSION['user_id'];

$stmt = $pdo->prepare(
    'SELECT u.id, u.username, u.unique_id, u.group_id, u.total_points, g.name AS group_name
     FROM users u
     LEFT JOIN groups g ON g.id = u.group_id
     WHERE u.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $currentUserId]);
$currentUser = $stmt->fetch();

if (!$currentUser) {
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_group') {
        $newGroupName = trim($_POST['new_group_name'] ?? '');

        if ($newGroupName === '') {
            setFlash('error', 'Grup adı boş bırakılamaz.');
        } elseif (mb_strlen($newGroupName) > 100) {
            setFlash('error', 'Grup adı en fazla 100 karakter olabilir.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO groups (name) VALUES (:name)');
                $stmt->execute(['name' => $newGroupName]);
                setFlash('success', 'Grup oluşturuldu. Grup ID: ' . $pdo->lastInsertId());
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    setFlash('error', 'Bu grup adı zaten kullanılıyor.');
                } else {
                    setFlash('error', 'Grup oluşturulamadı: ' . $e->getMessage());
                }
            }
        }

        header('Location: dashboard.php');
        exit;
    }

    if ($action === 'join_group') {
        $targetGroupId = (int) ($_POST['group_id'] ?? 0);

        if ($targetGroupId <= 0) {
            setFlash('error', 'Geçersiz grup ID.');
        } else {
            $stmt = $pdo->prepare('SELECT id, name FROM groups WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $targetGroupId]);
            $targetGroup = $stmt->fetch();

            if (!$targetGroup) {
                setFlash('error', 'Seçilen grup bulunamadı.');
            } else {
                $stmt = $pdo->prepare('UPDATE users SET group_id = :group_id WHERE id = :id');
                $stmt->execute([
                    'group_id' => $targetGroupId,
                    'id' => $currentUserId,
                ]);
                setFlash('success', 'Gruba katıldınız: ' . $targetGroup['name'] . ' (ID: ' . $targetGroup['id'] . ')');
            }
        }

        header('Location: dashboard.php');
        exit;
    }

    if ($action === 'delete_activity') {
        $activityId = (int) ($_POST['activity_id'] ?? 0);

        if ($activityId <= 0) {
            setFlash('error', 'Silinecek aktivite bulunamadı.');
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('SELECT points_earned FROM activities WHERE id = :id AND user_id = :user_id FOR UPDATE');
                $stmt->execute([
                    'id' => $activityId,
                    'user_id' => $currentUserId,
                ]);
                $pointsEarned = $stmt->fetchColumn();

                if ($pointsEarned === false) {
                    throw new RuntimeException('Aktivite bulunamadı veya size ait değil.');
                }

                $stmt = $pdo->prepare('DELETE FROM activities WHERE id = :id AND user_id = :user_id');
                $stmt->execute([
                    'id' => $activityId,
                    'user_id' => $currentUserId,
                ]);

                $stmt = $pdo->prepare('UPDATE users SET total_points = GREATEST(total_points - :points, 0) WHERE id = :id');
                $stmt->execute([
                    'points' => (float) $pointsEarned,
                    'id' => $currentUserId,
                ]);

                $pdo->commit();
                setFlash('success', 'Aktivite silindi ve puan güncellendi.');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                setFlash('error', 'Aktivite silinemedi: ' . $e->getMessage());
            }
        }

        header('Location: dashboard.php');
        exit;
    }

    if ($action === 'log_activity') {
        $activityType = trim($_POST['activity_type'] ?? '');
        $durationMinutes = (int) ($_POST['duration_minutes'] ?? 0);

        if ($activityType === '') {
            setFlash('error', 'Lütfen bir aktivite türü seçin.');
            header('Location: dashboard.php');
            exit;
        }

        if ($durationMinutes <= 0 || $durationMinutes > 600) {
            setFlash('error', 'Süre 1 ile 600 dakika arasında olmalıdır.');
            header('Location: dashboard.php');
            exit;
        }

        $pointsEarned = round($durationMinutes * (100 / 30), 2);

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT total_points, group_id FROM users WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $currentUserId]);
            $lockedUser = $stmt->fetch();

            if (!$lockedUser) {
                throw new RuntimeException('Kullanıcı bulunamadı.');
            }

            $oldScore = (float) $lockedUser['total_points'];
            $groupId = $lockedUser['group_id'] !== null ? (int) $lockedUser['group_id'] : null;

            $stmt = $pdo->prepare(
                'INSERT INTO activities (user_id, activity_type, duration_minutes, points_earned)
                 VALUES (:user_id, :activity_type, :duration_minutes, :points_earned)'
            );
            $stmt->execute([
                'user_id' => $currentUserId,
                'activity_type' => $activityType,
                'duration_minutes' => $durationMinutes,
                'points_earned' => $pointsEarned,
            ]);

            $stmt = $pdo->prepare('UPDATE users SET total_points = total_points + :points WHERE id = :id');
            $stmt->execute([
                'points' => $pointsEarned,
                'id' => $currentUserId,
            ]);

            if ($groupId !== null) {
                $newScore = $oldScore + $pointsEarned;

                $stmt = $pdo->prepare('SELECT id, total_points FROM users WHERE group_id = :group_id AND id <> :id');
                $stmt->execute([
                    'group_id' => $groupId,
                    'id' => $currentUserId,
                ]);
                $groupMates = $stmt->fetchAll();

                $insertNotification = $pdo->prepare(
                    'INSERT INTO notifications (user_id, actor_user_id, type, message)
                     VALUES (:user_id, :actor_user_id, :type, :message)'
                );

                foreach ($groupMates as $mate) {
                    $mateScore = (float) $mate['total_points'];
                    $oldGap = $mateScore - $oldScore;
                    $newGap = $mateScore - $newScore;

                    if ($oldScore <= $mateScore && $newScore > $mateScore) {
                        $insertNotification->execute([
                            'user_id' => $mate['id'],
                            'actor_user_id' => $currentUserId,
                            'type' => 'overtook',
                            'message' => $currentUser['username'] . ' seni geçti! Hadi harekete geç!',
                        ]);
                    }

                    if ($oldScore < $mateScore && $newScore < $mateScore && $oldGap >= 50 && $newGap < 50) {
                        $insertNotification->execute([
                            'user_id' => $mate['id'],
                            'actor_user_id' => $currentUserId,
                            'type' => 'closing_gap',
                            'message' => $currentUser['username'] . ' ensende! Yakalanma!',
                        ]);
                    }
                }
            }

            $pdo->commit();
            setFlash('success', 'Aktivite kaydedildi ve puanlar güncellendi.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            setFlash('error', 'Aktivite kaydedilemedi: ' . $e->getMessage());
        }

        header('Location: dashboard.php');
        exit;
    }
}

$successMessage = getFlash('success');
$errorMessage = getFlash('error');

$searchGroupName = trim($_GET['search_group_name'] ?? '');
$searchGroupId = trim($_GET['search_group_id'] ?? '');

$groupSql =
    'SELECT g.id, g.name, COUNT(u.id) AS member_count
     FROM groups g
     LEFT JOIN users u ON u.group_id = g.id
     WHERE 1 = 1';
$groupParams = [];

if ($searchGroupName !== '') {
    $groupSql .= ' AND g.name LIKE :search_group_name';
    $groupParams['search_group_name'] = '%' . $searchGroupName . '%';
}

if ($searchGroupId !== '' && ctype_digit($searchGroupId)) {
    $groupSql .= ' AND g.id = :search_group_id';
    $groupParams['search_group_id'] = (int) $searchGroupId;
}

$groupSql .= ' GROUP BY g.id, g.name ORDER BY g.id DESC LIMIT 100';

$stmt = $pdo->prepare($groupSql);
$stmt->execute($groupParams);
$allGroups = $stmt->fetchAll();

$leaderboard = [];
if ($currentUser['group_id'] !== null) {
    $stmt = $pdo->prepare(
        'SELECT id, username, unique_id, total_points
         FROM users
         WHERE group_id = :group_id
         ORDER BY total_points DESC, username ASC'
    );
    $stmt->execute(['group_id' => $currentUser['group_id']]);
    $leaderboard = $stmt->fetchAll();
}

$stmt = $pdo->prepare('SELECT id, message, type, is_read, created_at FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 20');
$stmt->execute(['user_id' => $currentUserId]);
$notifications = $stmt->fetchAll();

$markRead = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id AND is_read = 0');
$markRead->execute(['user_id' => $currentUserId]);

$stmt = $pdo->prepare('SELECT id, activity_type, duration_minutes, points_earned, created_at FROM activities WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 10');
$stmt->execute(['user_id' => $currentUserId]);
$recentActivities = $stmt->fetchAll();
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Panel - Social Fitness</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg bg-white border-bottom mb-4">
    <div class="container">
        <span class="navbar-brand fw-semibold">Social Fitness</span>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="text-muted">Giriş yapan: <strong><?= h($currentUser['unique_id']) ?></strong></span>
            <a href="dashboard.php?logout=1" class="btn btn-outline-danger btn-sm">Çıkış Yap</a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    <?php if ($successMessage): ?>
        <div class="alert alert-success"><?= h($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="alert alert-danger"><?= h($errorMessage) ?></div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 mb-0">Grup Sekmesi</h2>
                <span class="text-muted">
                    Aktif grup:
                    <strong><?= $currentUser['group_name'] ? h($currentUser['group_name']) . ' (ID: ' . (int) $currentUser['group_id'] . ')' : 'Henüz grup seçilmedi' ?></strong>
                </span>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-5">
                    <form method="post" class="border rounded p-3 bg-light">
                        <input type="hidden" name="action" value="create_group">
                        <label class="form-label" for="new_group_name">Yeni Grup Oluştur</label>
                        <div class="input-group">
                            <input class="form-control" type="text" id="new_group_name" name="new_group_name" placeholder="Grup adı" required>
                            <button class="btn btn-primary" type="submit">Oluştur</button>
                        </div>
                    </form>
                </div>
                <div class="col-lg-7">
                    <form method="get" class="border rounded p-3 bg-light">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label" for="search_group_name">Grup adına göre ara</label>
                                <input class="form-control" type="text" id="search_group_name" name="search_group_name" value="<?= h($searchGroupName) ?>" placeholder="Örnek: TeamAlpha">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="search_group_id">Grup ID'ye göre ara</label>
                                <input class="form-control" type="number" id="search_group_id" name="search_group_id" value="<?= h($searchGroupId) ?>" min="1" placeholder="Örnek: 3">
                            </div>
                            <div class="col-md-2 d-grid align-items-end">
                                <button class="btn btn-dark mt-4" type="submit">Ara</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Grup ID</th>
                            <th>Grup Adı</th>
                            <th>Üye Sayısı</th>
                            <th>İşlem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$allGroups): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">Kriterlere uygun grup bulunamadı.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allGroups as $group): ?>
                                <tr>
                                    <td><?= (int) $group['id'] ?></td>
                                    <td><?= h($group['name']) ?></td>
                                    <td><?= (int) $group['member_count'] ?></td>
                                    <td>
                                        <?php if ((int) $currentUser['group_id'] === (int) $group['id']): ?>
                                            <span class="badge text-bg-success">Mevcut Grup</span>
                                        <?php else: ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="join_group">
                                                <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                                <button class="btn btn-sm btn-outline-primary" type="submit">Gruba Katıl</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5">Aktivite Ekle</h2>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="action" value="log_activity">
                        <div class="mb-3">
                            <label class="form-label" for="activity_type">Aktivite Türü</label>
                            <select class="form-select" id="activity_type" name="activity_type" required>
                                <option value="">Seçiniz</option>
                                <option value="Kosu">Koşu</option>
                                <option value="Bisiklet">Bisiklet</option>
                                <option value="Yuruyus">Yürüyüş</option>
                                <option value="Spor Salonu">Spor Salonu</option>
                                <option value="Yuzme">Yüzme</option>
                                <option value="Yoga">Yoga</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="duration_minutes">Süre (dakika)</label>
                            <input class="form-control" type="number" id="duration_minutes" name="duration_minutes" min="1" max="600" required>
                        </div>
                        <div class="form-text mb-3">30 dakika = 100 puan.</div>
                        <button class="btn btn-primary w-100" type="submit">Aktivite Ekle</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Son Aktiviteler</h2>
                    <?php if (!$recentActivities): ?>
                        <p class="text-muted mb-0">Henüz aktivite yok.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($recentActivities as $activity): ?>
                                <li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-3">
                                    <div>
                                        <div class="fw-semibold"><?= h($activity['activity_type']) ?></div>
                                        <small class="text-muted">
                                            <?= (int) $activity['duration_minutes'] ?> dk,
                                            +<?= number_format((float) $activity['points_earned'], 2) ?> puan
                                        </small>
                                    </div>
                                    <form method="post" onsubmit="return confirm('Bu aktivite silinsin mi?');">
                                        <input type="hidden" name="action" value="delete_activity">
                                        <input type="hidden" name="activity_id" value="<?= (int) $activity['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Sil</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Grup Sıralaması</h2>
                    <?php if ($currentUser['group_id'] === null): ?>
                        <p class="text-muted mb-0">Sıralamayı görebilmek için önce bir gruba katılın veya grup oluşturun.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Kullanıcı</th>
                                        <th>Benzersiz ID</th>
                                        <th>Toplam Puan</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($leaderboard as $index => $member): ?>
                                        <tr class="<?= (int) $member['id'] === $currentUserId ? 'table-primary' : '' ?>">
                                            <td><?= $index + 1 ?></td>
                                            <td><?= h($member['username']) ?></td>
                                            <td><?= h($member['unique_id']) ?></td>
                                            <td><strong><?= number_format((float) $member['total_points'], 2) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3">Rekabet Bildirimleri</h2>
                    <?php if (!$notifications): ?>
                        <p class="text-muted mb-0">Henüz bildirim yok.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($notifications as $notification): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-start px-0">
                                    <div>
                                        <div class="fw-semibold <?= $notification['is_read'] ? 'text-secondary' : 'text-dark' ?>">
                                            <?= h($notification['message']) ?>
                                        </div>
                                        <small class="text-muted"><?= h($notification['created_at']) ?></small>
                                    </div>
                                    <?php if (!$notification['is_read']): ?>
                                        <span class="badge text-bg-warning">Yeni</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
