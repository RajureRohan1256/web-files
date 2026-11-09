<?php
session_start();

// ================= DYNAMIC PATH RESOLUTION =================
// 1. Locate users.json (Olabs-careers/user/users.json)
if (file_exists(__DIR__ . '/user/users.json')) {
    define('USERS_FILE_PATH', __DIR__ . '/user/users.json');
} elseif (file_exists(__DIR__ . '/../user/users.json')) {
    define('USERS_FILE_PATH', __DIR__ . '/../user/users.json');
} else {
    define('USERS_FILE_PATH', __DIR__ . '/users.json');
}

// 2. Locate new-jobs.json (Olabs-careers/jobs/new-jobs.json)
if (file_exists(__DIR__ . '/new-jobs.json')) {
    define('JSON_FILE_PATH', __DIR__ . '/new-jobs.json');
} elseif (file_exists(__DIR__ . '/jobs/new-jobs.json')) {
    define('JSON_FILE_PATH', __DIR__ . '/jobs/new-jobs.json');
} else {
    define('JSON_FILE_PATH', __DIR__ . '/new-jobs.json');
}
// ===========================================================

// 1. Handle Logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// 2. Handle Login Authentication
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $users = [];
    if (file_exists(USERS_FILE_PATH)) {
        $decoded = json_decode(file_get_contents(USERS_FILE_PATH), true);
        if (is_array($decoded)) {
            $users = isset($decoded['username']) ? [$decoded] : $decoded;
        }
    }

    $authenticated_user = null;
    foreach ($users as $user) {
        $stored_user = trim($user['username'] ?? '');
        $stored_hash = trim($user['password'] ?? '');

        if (strcasecmp($stored_user, $username) === 0 && password_verify($password, $stored_hash)) {
            $authenticated_user = $user;
            break;
        }
    }

    if ($authenticated_user) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        $_SESSION['user'] = [
            'username' => $authenticated_user['username'],
            'role'     => $authenticated_user['role'] ?? 'admin'
        ];
        header('Location: admin.php');
        exit;
    } else {
        $login_error = 'Invalid username or password.';
    }
}

// 3. Render Login Screen if not authenticated
if (empty($_SESSION['authenticated'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | Careers Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-lg shadow-md p-8">
        <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Admin Authentication</h2>
        <?php if ($login_error): ?>
            <div class="bg-red-50 text-red-600 p-3 rounded mb-4 text-sm font-medium"><?= htmlspecialchars($login_error) ?></div>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="login">
            <div>
                <label class="block text-sm font-medium text-gray-700">Username</label>
                <input type="text" name="username" required class="w-full mt-1 px-3 py-2 border rounded-md focus:ring-2 focus:ring-blue-500 focus:outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" name="password" required class="w-full mt-1 px-3 py-2 border rounded-md focus:ring-2 focus:ring-blue-500 focus:outline-none">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-md transition">Sign In</button>
        </form>
    </div>
</body>
</html>
<?php
    exit;
}

// ================= AUTHENTICATED ACTIONS =================

// Helper: Read Jobs Data (Extracts and cleans jobs array from {"jobs": [...]})
function get_jobs_data() {
    if (!file_exists(JSON_FILE_PATH)) {
        file_put_contents(JSON_FILE_PATH, json_encode(["jobs" => []], JSON_PRETTY_PRINT));
        return [];
    }
    $content = file_get_contents(JSON_FILE_PATH);
    $data = json_decode($content, true);

    if (!is_array($data)) {
        return [];
    }

    $jobs_list = [];

    // If properly nested under 'jobs'
    if (isset($data['jobs']) && is_array($data['jobs'])) {
        $jobs_list = $data['jobs'];
    }

    // Automatically recover and merge any stray jobs added as top-level keys (e.g. "0": {...})
    foreach ($data as $key => $value) {
        if ($key !== 'jobs' && is_array($value) && isset($value['id'])) {
            $jobs_list[] = $value;
        }
    }

    // Fallback if whole file was a plain array
    if (empty($jobs_list) && isset($data[0]['id'])) {
        $jobs_list = $data;
    }

    return array_values($jobs_list);
}

// Helper: Save Jobs Data (Always wraps properly in {"jobs": [...]})
function save_jobs_data($data) {
    $payload = [
        "jobs" => array_values($data)
    ];
    return file_put_contents(
        JSON_FILE_PATH,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

$jobs = get_jobs_data();

// Handle Job Deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $target_id = $_GET['id'];
    $jobs = array_values(array_filter($jobs, fn($job) => ($job['id'] ?? '') !== $target_id));
    save_jobs_data($jobs);
    header('Location: admin.php?msg=deleted');
    exit;
}

// Handle Add / Edit Job Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_job') {
    $job_id = trim($_POST['id'] ?? '');
    $split_lines = fn($text) => array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", "", $text)))));

    // Build application requirements
    $application = [];
    if (!empty($_POST['app_resume'])) $application['resume'] = true;
    if (!empty($_POST['app_portfolio'])) $application['portfolio'] = true;
    if (!empty($_POST['app_github'])) $application['github'] = true;
    if (!empty($_POST['app_linkedin'])) $application['linkedin'] = true;
    if (!empty($_POST['app_publications'])) $application['publications'] = true;

    $job_data = [
        "id" => $job_id,
        "title" => trim($_POST['title'] ?? ''),
        "slug" => trim($_POST['slug'] ?? ''),
        "location" => trim($_POST['location'] ?? ''),
        "employmentType" => trim($_POST['employmentType'] ?? ''),
        "experience" => trim($_POST['experience'] ?? ''),
        "department" => trim($_POST['department'] ?? ''),
        "category" => trim($_POST['category'] ?? ''),
        "status" => trim($_POST['status'] ?? 'open'),
        "summary" => trim($_POST['summary'] ?? ''),
        "about" => trim($_POST['about'] ?? ''),
        "responsibilities" => $split_lines($_POST['responsibilities'] ?? ''),
        "requiredSkills" => $split_lines($_POST['requiredSkills'] ?? ''),
        "preferredQualifications" => $split_lines($_POST['preferredQualifications'] ?? ''),
        "preferredBackground" => $split_lines($_POST['preferredBackground'] ?? ''),
        "techStack" => $split_lines($_POST['techStack'] ?? ''),
        "application" => $application
    ];

    $is_existing = false;
    foreach ($jobs as $idx => $job) {
        if (($job['id'] ?? '') === $job_id) {
            $jobs[$idx] = $job_data;
            $is_existing = true;
            break;
        }
    }

    if (!$is_existing) {
        $jobs[] = $job_data;
    }

    save_jobs_data($jobs);
    header('Location: admin.php?msg=saved');
    exit;
}

$edit_job = null;
if (isset($_GET['edit'])) {
    foreach ($jobs as $j) {
        if (($j['id'] ?? '') === $_GET['edit']) {
            $edit_job = $j;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Careers Management Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800 min-h-screen">
    <nav class="bg-white border-b border-gray-200 px-6 py-4 flex justify-between items-center shadow-sm">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Careers JSON Manager</h1>
            <p class="text-xs text-gray-500">User: <span class="font-medium text-gray-700"><?= htmlspecialchars($_SESSION['user']['username'] ?? '') ?></span> | Auth File: <code class="text-xs bg-gray-100 px-1 py-0.5 rounded"><?= htmlspecialchars(USERS_FILE_PATH) ?></code></p>
        </div>
        <div class="flex items-center space-x-4">
            <span class="text-sm text-gray-500 hidden md:inline">Jobs File: <code class="bg-gray-100 px-2 py-1 rounded text-xs"><?= htmlspecialchars(JSON_FILE_PATH) ?></code></span>
            <a href="admin.php?action=logout" class="text-sm text-red-600 hover:underline font-medium">Logout</a>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto p-6 grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left: List of Jobs -->
        <div class="lg:col-span-1 bg-white p-5 rounded-lg border shadow-sm">
            <div class="flex justify-between items-center mb-4">
                <h2 class="font-bold text-lg">Positions (<?= count($jobs) ?>)</h2>
                <a href="admin.php" class="text-xs bg-blue-50 text-blue-600 px-2 py-1 rounded hover:bg-blue-100 font-medium">+ Add New</a>
            </div>
            <div class="space-y-2 max-h-[75vh] overflow-y-auto">
                <?php if (empty($jobs)): ?>
                    <p class="text-gray-400 text-sm">No positions found.</p>
                <?php endif; ?>
                <?php foreach ($jobs as $item): ?>
                    <div class="p-3 border rounded-md hover:bg-gray-50 transition flex justify-between items-start <?= ($edit_job['id'] ?? '') === ($item['id'] ?? '') ? 'border-blue-500 bg-blue-50' : '' ?>">
                        <div>
                            <div class="font-medium text-sm text-gray-900"><?= htmlspecialchars($item['title'] ?? 'Untitled') ?></div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($item['id'] ?? '') ?> &bull; <span class="uppercase font-semibold text-<?= ($item['status'] ?? '') === 'open' ? 'green' : 'red' ?>-600"><?= htmlspecialchars($item['status'] ?? 'open') ?></span></div>
                        </div>
                        <div class="flex space-x-2 text-xs">
                            <a href="admin.php?edit=<?= urlencode($item['id'] ?? '') ?>" class="text-blue-600 hover:underline">Edit</a>
                            <a href="admin.php?action=delete&id=<?= urlencode($item['id'] ?? '') ?>" onclick="return confirm('Delete this position?')" class="text-red-600 hover:underline">Del</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Right: Editor Form -->
        <div class="lg:col-span-2 bg-white p-6 rounded-lg border shadow-sm">
            <h2 class="font-bold text-lg mb-4"><?= $edit_job ? 'Edit Position' : 'Create New Position' ?></h2>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_job">

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Unique ID</label>
                        <input type="text" name="id" value="<?= htmlspecialchars($edit_job['id'] ?? '') ?>" required <?= $edit_job ? 'readonly class="w-full mt-1 px-3 py-2 border rounded-md text-sm bg-gray-100 cursor-not-allowed"' : 'class="w-full mt-1 px-3 py-2 border rounded-md text-sm"' ?>>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Job Title</label>
                        <input type="text" name="title" value="<?= htmlspecialchars($edit_job['title'] ?? '') ?>" required class="w-full mt-1 px-3 py-2 border rounded-md text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Slug</label>
                        <input type="text" name="slug" value="<?= htmlspecialchars($edit_job['slug'] ?? '') ?>" required class="w-full mt-1 px-3 py-2 border rounded-md text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Location</label>
                        <input type="text" name="location" value="<?= htmlspecialchars($edit_job['location'] ?? 'Remote, India') ?>" required class="w-full mt-1 px-3 py-2 border rounded-md text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Employment Type</label>
                        <input type="text" name="employmentType" value="<?= htmlspecialchars($edit_job['employmentType'] ?? 'Internship') ?>" class="w-full mt-1 px-3 py-2 border rounded-md text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Experience</label>
                        <input type="text" name="experience" value="<?= htmlspecialchars($edit_job['experience'] ?? 'Intern') ?>" class="w-full mt-1 px-3 py-2 border rounded-md text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Department</label>
                        <input type="text" name="department" value="<?= htmlspecialchars($edit_job['department'] ?? '') ?>" class="w-full mt-1 px-3 py-2 border rounded-md text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Category</label>
                        <input type="text" name="category" value="<?= htmlspecialchars($edit_job['category'] ?? '') ?>" class="w-full mt-1 px-3 py-2 border rounded-md text-sm">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">Status</label>
                    <select name="status" class="w-full mt-1 px-3 py-2 border rounded-md text-sm bg-white">
                        <option value="open" <?= ($edit_job['status'] ?? '') === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="closed" <?= ($edit_job['status'] ?? '') === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">Summary</label>
                    <textarea name="summary" rows="2" class="w-full mt-1 px-3 py-2 border rounded-md text-sm"><?= htmlspecialchars($edit_job['summary'] ?? '') ?></textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 uppercase">About</label>
                    <textarea name="about" rows="3" class="w-full mt-1 px-3 py-2 border rounded-md text-sm"><?= htmlspecialchars($edit_job['about'] ?? '') ?></textarea>
                </div>

                <div class="space-y-3 pt-2">
                    <p class="text-xs text-gray-500 font-medium">Enter one item per line for the following lists:</p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase">Responsibilities</label>
                            <textarea name="responsibilities" rows="4" class="w-full mt-1 px-3 py-2 border rounded-md font-mono text-xs"><?= htmlspecialchars(implode("\n", $edit_job['responsibilities'] ?? [])) ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase">Required Skills</label>
                            <textarea name="requiredSkills" rows="4" class="w-full mt-1 px-3 py-2 border rounded-md font-mono text-xs"><?= htmlspecialchars(implode("\n", $edit_job['requiredSkills'] ?? [])) ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase">Preferred Qualifications</label>
                            <textarea name="preferredQualifications" rows="4" class="w-full mt-1 px-3 py-2 border rounded-md font-mono text-xs"><?= htmlspecialchars(implode("\n", $edit_job['preferredQualifications'] ?? [])) ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 uppercase">Preferred Background</label>
                            <textarea name="preferredBackground" rows="4" class="w-full mt-1 px-3 py-2 border rounded-md font-mono text-xs"><?= htmlspecialchars(implode("\n", $edit_job['preferredBackground'] ?? [])) ?></textarea>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-gray-600 uppercase">Tech Stack (One per line)</label>
                        <textarea name="techStack" rows="3" class="w-full mt-1 px-3 py-2 border rounded-md font-mono text-xs"><?= htmlspecialchars(implode("\n", $edit_job['techStack'] ?? [])) ?></textarea>
                    </div>
                </div>

                <div class="pt-2">
                    <label class="block text-xs font-semibold text-gray-600 uppercase mb-2">Required Application Fields</label>
                    <div class="flex flex-wrap gap-6">
                        <label class="flex items-center space-x-2 text-sm">
                            <input type="checkbox" name="app_resume" <?= !empty($edit_job['application']['resume']) || !$edit_job ? 'checked' : '' ?>>
                            <span>Resume</span>
                        </label>
                        <label class="flex items-center space-x-2 text-sm">
                            <input type="checkbox" name="app_portfolio" <?= !empty($edit_job['application']['portfolio']) ? 'checked' : '' ?>>
                            <span>Portfolio</span>
                        </label>
                        <label class="flex items-center space-x-2 text-sm">
                            <input type="checkbox" name="app_github" <?= !empty($edit_job['application']['github']) ? 'checked' : '' ?>>
                            <span>GitHub</span>
                        </label>
                        <label class="flex items-center space-x-2 text-sm">
                            <input type="checkbox" name="app_linkedin" <?= !empty($edit_job['application']['linkedin']) ? 'checked' : '' ?>>
                            <span>LinkedIn</span>
                        </label>
                        <label class="flex items-center space-x-2 text-sm">
                            <input type="checkbox" name="app_publications" <?= !empty($edit_job['application']['publications']) ? 'checked' : '' ?>>
                            <span>Publications</span>
                        </label>
                    </div>
                </div>

                <div class="pt-4 flex justify-end space-x-3">
                    <?php if ($edit_job): ?>
                        <a href="admin.php" class="px-4 py-2 border rounded-md text-sm text-gray-700 hover:bg-gray-50">Cancel</a>
                    <?php endif; ?>
                    <button type="submit" class="px-6 py-2 bg-blue-600 text-white rounded-md text-sm font-medium hover:bg-blue-700 transition">
                        <?= $edit_job ? 'Update Position' : 'Save Position' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>