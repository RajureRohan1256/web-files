<?php
// Dynamic path checking for user/users.json
if (file_exists(__DIR__ . '/users.json')) {
    define('USERS_FILE', __DIR__ . '/users.json');
} elseif (file_exists(__DIR__ . '/user/users.json')) {
    define('USERS_FILE', __DIR__ . '/user/users.json');
} else {
    define('USERS_FILE', __DIR__ . '/user/users.json');
}

function add_or_update_user($username, $plainPassword, $role = 'admin') {
    $dir = dirname(USERS_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $users = file_exists(USERS_FILE) ? json_decode(file_get_contents(USERS_FILE), true) ?? [] : [];

    $user_entry = [
        'username'   => trim($username),
        'password'   => password_hash($plainPassword, PASSWORD_DEFAULT),
        'role'       => $role,
        'created_at' => date('Y-m-d H:i:s')
    ];

    $found = false;
    foreach ($users as $index => $u) {
        if (strcasecmp($u['username'] ?? '', $username) === 0) {
            $users[$index] = $user_entry;
            $found = true;
            break;
        }
    }

    if (!$found) {
        $users[] = $user_entry;
    }

    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    return "User saved to: " . USERS_FILE;
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = add_or_update_user($_POST['username'], $_POST['password'], $_POST['role'] ?? 'admin');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Manager</title>
</head>
<body style="font-family:sans-serif; padding: 20px;">
    <h3>Create / Update User</h3>
    <?php if ($msg): ?>
        <p style="color: green; font-weight: bold;"><?= htmlspecialchars($msg) ?></p>
    <?php endif; ?>
    <form method="POST" style="max-width:300px;">
        <p><input type="text" name="username" placeholder="Username" required style="width:100%; padding: 8px;"></p>
        <p><input type="password" name="password" placeholder="Password" required style="width:100%; padding: 8px;"></p>
        <p>
            <select name="role" style="width:100%; padding: 8px;">
                <option value="superadmin">Superadmin</option>
                <option value="admin">Admin</option>
            </select>
        </p>
        <button type="submit" style="padding: 8px 16px;">Save User</button>
    </form>
</body>
</html>