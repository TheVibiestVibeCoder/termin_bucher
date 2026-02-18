<?php
declare(strict_types=1);

function is_admin_logged_in(): bool
{
    return isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id']);
}

function admin_login(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => trim($username)]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, (string) $admin['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_username'] = (string) $admin['username'];
    return true;
}

function admin_logout(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_username']);
    session_regenerate_id(true);
}

function require_admin(): void
{
    if (!is_admin_logged_in()) {
        flash_set('error', 'Bitte zuerst als Admin anmelden.');
        redirect('admin/login.php');
    }
}

function current_admin_id(): ?int
{
    if (!is_admin_logged_in()) {
        return null;
    }

    return (int) $_SESSION['admin_id'];
}

function change_admin_password(int $adminId, string $currentPassword, string $newPassword): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = :id');
    $stmt->execute([':id' => $adminId]);
    $row = $stmt->fetch();
    if (!$row) {
        return false;
    }

    $hash = (string) $row['password_hash'];
    if (!password_verify($currentPassword, $hash)) {
        return false;
    }

    $update = db()->prepare('UPDATE admins SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
    $update->execute([
        ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ':updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ':id' => $adminId,
    ]);

    create_database_backup();
    return true;
}

