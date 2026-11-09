<?php
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

// =============================================================================
// SMTP CONFIGURATION (Hostinger SSL)
// =============================================================================
$smtpHost     = 'smtp.hostinger.com';
$smtpPort     = 465; // SSL Port
$smtpUser     = 'careers@orainlabs.gamtech.in';
$smtpPassword = 'p>5BtiZbOzqM'; // <-- Enter your email account password here
$smtpFromName = 'Orain Labs Careers';

// 1. Anti-Spam Honeypot Check
if (!empty($_POST['website_url_hp'])) {
    echo json_encode(['success' => true, 'message' => 'Application recorded.']);
    exit;
}

// 2. Extract & Sanitize Input Data
$roleId        = htmlspecialchars(trim($_POST['role_id'] ?? ''));
$jobTitle      = htmlspecialchars(trim($_POST['job_title'] ?? 'Position'));
$jobCategory   = htmlspecialchars(trim($_POST['job_category'] ?? 'Engineering'));
$legalName     = htmlspecialchars(trim($_POST['legal_name'] ?? ''));
$preferredName = htmlspecialchars(trim($_POST['preferred_name'] ?? ''));
$email         = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
$phone         = htmlspecialchars(trim($_POST['phone'] ?? ''));
$location      = htmlspecialchars(trim($_POST['location'] ?? ''));
$startDate     = htmlspecialchars(trim($_POST['start_date'] ?? ''));
$workAuth      = htmlspecialchars(trim($_POST['work_auth'] ?? ''));
$localOffice   = htmlspecialchars(trim($_POST['local_office'] ?? ''));
$visaSponsor   = htmlspecialchars(trim($_POST['visa_sponsorship'] ?? ''));
$additionalInfo= htmlspecialchars(trim($_POST['additional_info'] ?? ''));
$certConfirm   = isset($_POST['cert_confirm']);

// Validate required fields
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL) || empty($legalName) || empty($roleId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields with a valid email.']);
    exit;
}

// 3. Position-Specific Storage Setup
$roleSlug           = preg_replace('/[^a-zA-Z0-9_-]/', '_', strtolower($roleId));
$baseDir            = __DIR__ . '/applications/';
$positionResumesDir = __DIR__ . '/applications/resumes/' . $roleSlug . '/';
$jsonPath           = $baseDir . 'applicants_' . $roleSlug . '.json';

// Initialize directory structure if missing
$oldUmask = umask(0);
if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0777, true);
}
if (!is_dir($positionResumesDir)) {
    @mkdir($positionResumesDir, 0777, true);
}
umask($oldUmask);

// 4. Enforce 15-Day Duplicate Policy
$applicantsList = [];
if (file_exists($jsonPath)) {
    $fileContents = file_get_contents($jsonPath);
    $decodedData  = json_decode($fileContents, true);
    if (is_array($decodedData)) {
        $applicantsList = $decodedData;
    }
}

$now = time();
$cooldownDays = 15;
$cooldownSeconds = $cooldownDays * 86400;

foreach ($applicantsList as $pastApp) {
    $pastEmail = strtolower(trim($pastApp['personalDetails']['email'] ?? ''));
    $pastRole  = $pastApp['positionApplied']['roleId'] ?? '';
    
    if ($pastEmail === strtolower($email) && $pastRole === $roleId) {
        $appliedTimestamp = strtotime($pastApp['appliedAt'] ?? '');
        if ($appliedTimestamp && ($now - $appliedTimestamp) < $cooldownSeconds) {
            $daysPassed = floor(($now - $appliedTimestamp) / 86400);
            $daysRemaining = $cooldownDays - $daysPassed;
            
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => "You have already submitted an application for the {$jobTitle} role within the last 15 days. You will be eligible to reapply for this specific position in {$daysRemaining} day(s)."
            ]);
            exit;
        }
    }
}

// 5. Resume Upload & MIME Inspection
$resumeRelPath = '';

if (isset($_FILES['resume_file']) && $_FILES['resume_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath   = $_FILES['resume_file']['tmp_name'];
    $originalName  = $_FILES['resume_file']['name'];
    $fileSize      = $_FILES['resume_file']['size'];
    $fileExtension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    $allowedExtensions = ['pdf', 'doc', 'docx'];
    if (!in_array($fileExtension, $allowedExtensions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid extension. Only PDF, DOC, and DOCX files are allowed.']);
        exit;
    }

    if ($fileSize > 10 * 1024 * 1024) { // 10MB limit
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File size exceeds 10MB limit.']);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $fileTmpPath);
    finfo_close($finfo);

    $allowedMimes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/octet-stream'
    ];

    if (!in_array($mimeType, $allowedMimes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file contents. Uploaded file is not a valid document.']);
        exit;
    }

    $cleanName      = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
    $storedFileName = time() . '_' . uniqid() . '_' . $cleanName . '.' . $fileExtension;
    $targetFilePath = $positionResumesDir . $storedFileName;

    if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
        @chmod($targetFilePath, 0644);
        $resumeRelPath = 'applications/resumes/' . $roleSlug . '/' . $storedFileName;
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save resume file. Check folder write permissions.']);
        exit;
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Resume file is required.']);
    exit;
}

// 6. Construct Applicant Record
$newApplicant = [
    'id'                   => 'app_' . uniqid(),
    'appliedAt'            => date('c'),
    'positionApplied'      => [
        'title'    => $jobTitle,
        'roleId'   => $roleId,
        'category' => $jobCategory
    ],
    'personalDetails'      => [
        'legalName'     => $legalName,
        'preferredName' => $preferredName,
        'email'         => $email,
        'phone'         => $phone,
        'location'      => $location,
        'startDate'     => $startDate
    ],
    'eligibility'          => [
        'authorizedToWork' => $workAuth,
        'localOffice3Days' => $localOffice,
        'visaSponsorship'  => $visaSponsor
    ],
    'additionalInfo'       => $additionalInfo,
    'resumePath'           => $resumeRelPath,
    'confirmedAgreement'   => $certConfirm
];

// 7. Save to Position-Specific JSON
$applicantsList[] = $newApplicant;
$jsonSaved = file_put_contents(
    $jsonPath,
    json_encode($applicantsList, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($jsonSaved === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to write application record to database.']);
    exit;
}

// 8. SMTP Transport Helper Function
function sendHostingerSmtp($host, $port, $username, $password, $fromName, $toEmail, $subject, $htmlContent) {
    $socket = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 20);
    if (!$socket) {
        error_log("SMTP Connection failed: {$errstr} ({$errno})");
        return false;
    }

    $readResponse = function($sock, $expectedCode) {
        $response = '';
        while ($line = fgets($sock, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return (substr($response, 0, 3) === (string)$expectedCode);
    };

    $sendCommand = function($sock, $cmd, $expectedCode) use ($readResponse) {
        fputs($sock, $cmd . "\r\n");
        return $readResponse($sock, $expectedCode);
    };

    if (!$readResponse($socket, 220)) { fclose($socket); return false; }
    if (!$sendCommand($socket, "EHLO " . gethostname(), 250)) { fclose($socket); return false; }
    if (!$sendCommand($socket, "AUTH LOGIN", 334)) { fclose($socket); return false; }
    if (!$sendCommand($socket, base64_encode($username), 334)) { fclose($socket); return false; }
    if (!$sendCommand($socket, base64_encode($password), 235)) { fclose($socket); return false; }
    if (!$sendCommand($socket, "MAIL FROM: <{$username}>", 250)) { fclose($socket); return false; }
    if (!$sendCommand($socket, "RCPT TO: <{$toEmail}>", 250)) { fclose($socket); return false; }
    if (!$sendCommand($socket, "DATA", 354)) { fclose($socket); return false; }

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$username}>\r\n";
    $headers .= "Reply-To: {$username}\r\n";
    $headers .= "To: <{$toEmail}>\r\n";
    $headers .= "Subject: {$subject}\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    fputs($socket, $headers . "\r\n" . $htmlContent . "\r\n.\r\n");
    if (!$readResponse($socket, 250)) { fclose($socket); return false; }

    $sendCommand($socket, "QUIT", 221);
    fclose($socket);
    return true;
}

// 9. Dispatch Confirmation Email via SMTP
$displayName = !empty($preferredName) ? $preferredName : $legalName;
$mailSubject = "Application Received: {$jobTitle} — Orain Labs";

$mailBody = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #111111; background-color: #f9f9f9; margin: 0; padding: 20px; }
        .wrapper { max-width: 580px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e5e5; border-radius: 6px; padding: 36px 32px; }
        .brand { font-size: 13px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: #111111; margin-bottom: 24px; }
        h1 { font-size: 20px; font-weight: 500; color: #111111; margin: 0 0 16px; }
        p { font-size: 14px; color: #444444; margin: 0 0 16px; }
        .meta-box { background: #f5f5f4; border: 1px solid #e5e5e5; border-radius: 4px; padding: 14px 18px; margin: 20px 0; font-family: monospace; font-size: 12px; color: #333333; }
        .footer { font-size: 12px; color: #888888; border-top: 1px solid #eeeeee; padding-top: 18px; margin-top: 28px; }
    </style>
</head>
<body>
    <div class='wrapper'>
        <div class='brand'>Orain LABS</div>
        <h1>Thank you for applying</h1>
        <p>Hi {$displayName},</p>
        <p>We have successfully received your application and resume for the <strong>{$jobTitle}</strong> position at Orain Labs.</p>
        
        <div class='meta-box'>
            ROLE: {$jobTitle}<br>
            TRACK: {$jobCategory}<br>
            TIMESTAMP: " . date('Y-m-d H:i:s T') . "
        </div>

        <p>Our engineering and research teams review every submission with first-principles technical depth. If your background aligns with our current problems, we will reach out directly with next steps.</p>
        
        <p><em>Note: If you wish to submit revised work or reapply for this specific position in the future, please note our 15-day application window.</em></p>

        <div class='footer'>
            orain Labs &mdash; Geometric Intelligence for Engineering<br>
            <a href='https://orainlabs.gamtech.in' style='color: #666666; text-decoration: none;'>Orain Labs</a>
        </div>
    </div>
</body>
</html>
";

sendHostingerSmtp(
    $smtpHost,
    $smtpPort,
    $smtpUser,
    $smtpPassword,
    $smtpFromName,
    $email,
    $mailSubject,
    $mailBody
);

// 10. Success Response
echo json_encode([
    'success'     => true,
    'message'     => "Application submitted successfully! A confirmation email has been sent to {$email}.",
    'applicantId' => $newApplicant['id']
]);