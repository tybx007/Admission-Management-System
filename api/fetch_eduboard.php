<?php
require_once '../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit();
}

// Accept JSON body OR normal POST
$raw  = file_get_contents('php://input');
$json = !empty($raw) ? json_decode($raw, true) : null;

$exam_type = trim($json['exam_type'] ?? $_POST['exam_type'] ?? '');
$exam_year = trim($json['exam_year'] ?? $_POST['exam_year'] ?? '');
$board     = trim($json['board'] ?? $_POST['board'] ?? '');
$roll       = trim($json['roll'] ?? $_POST['roll'] ?? '');
$reg        = trim($json['reg'] ?? $_POST['reg'] ?? '');

$session_id = trim($json['session_id'] ?? $_POST['session_id'] ?? '');
$captcha    = trim($json['captcha'] ?? $_POST['captcha'] ?? '');

// ---------------- Validation ----------------

if (
    empty($exam_type) ||
    empty($exam_year) ||
    empty($board) ||
    empty($roll) ||
    empty($session_id) ||
    empty($captcha)
) {

    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields.'
    ]);

    exit();
}

$valid_exams = ['ssc', 'hsc', 'dakhil', 'alim', 'equivalent'];

if (!in_array(strtolower($exam_type), $valid_exams)) {

    echo json_encode([
        'success' => false,
        'message' => 'Invalid exam type.'
    ]);

    exit();
}

// ---------------- Call Rust API ----------------

$payload = [

    "session_id" => $session_id,

    "board" => ucfirst(strtolower($board)),

    "exam" => strtoupper($exam_type),

    "year" => (int)$exam_year,

    "roll" => $roll,

    "reg" => $reg,

    "captcha" => $captcha

];

$ch = curl_init("http://localhost:8080/api/v1/verify");

curl_setopt_array($ch, [

    CURLOPT_POST => true,

    CURLOPT_RETURNTRANSFER => true,

    CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Accept: application/json"
    ],

    CURLOPT_POSTFIELDS => json_encode($payload),

    CURLOPT_CONNECTTIMEOUT => 10,

    CURLOPT_TIMEOUT => 30,

]);

$response = curl_exec($ch);

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

$curlError = curl_error($ch);

curl_close($ch);

if ($curlError) {

    echo json_encode([
        "success" => false,
        "message" => $curlError
    ]);

    exit();

}

$data = json_decode($response, true);

if ($httpCode != 200) {

    echo json_encode([
        "success" => false,
        "message" => $data["error"] ?? $data["message"] ?? "Verification failed."
    ]);

    exit();

}

// ---------------- Parse Response ----------------

$student = $data['student'] ?? [];
$academic = $data['academic'] ?? [];

// ---------------- Registration ----------------

$reg_value = trim(
    $student['registration']
    ?? $reg
);

// ---------------- DOB ----------------

$dob = trim(
    $student['date_of_birth']
    ?? ''
);

if (!empty($dob)) {

    $normalized = str_replace('-', '/', $dob);

    $parts = explode('/', $normalized);

    if (count($parts) == 3) {

        if (strlen($parts[0]) == 4) {

            $dob = sprintf(
                '%04d-%02d-%02d',
                $parts[0],
                $parts[1],
                $parts[2]
            );

        } else {

            $dob = sprintf(
                '%04d-%02d-%02d',
                $parts[2],
                $parts[1],
                $parts[0]
            );

        }

    }

}

// ---------------- Return (Same format as old API) ----------------

echo json_encode([

    'success' => true,

    'message' => 'Data fetched successfully.',

    'roll' => trim($student['roll'] ?? $roll),

    'reg' => $reg_value,

    'full_name' => strtoupper(trim($student['name'] ?? '')),

    'father_name' => strtoupper(trim($student['father_name'] ?? '')),

    'mother_name' => strtoupper(trim($student['mother_name'] ?? '')),

    'date_of_birth' => $dob,

    'institute_name' => trim($academic['institution'] ?? ''),

    'board_name' => strtoupper(trim($academic['board'] ?? $board)),

    'passing_year' => $exam_year,

    'gpa' => $academic['gpa'] ?? '',

    'result' => '',

    'group' => strtoupper(trim($academic['group'] ?? '')),

    'grades' => $data['subjects'] ?? [],

    'exam_type' => strtolower($exam_type)

]);