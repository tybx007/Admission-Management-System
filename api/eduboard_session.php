<?php

header('Content-Type: application/json');

$api = "http://localhost:8080/api/v1/session";

$ch = curl_init($api);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Accept: application/json"
    ],
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error    = curl_error($ch);

curl_close($ch);

if ($error) {
    echo json_encode([
        "success" => false,
        "message" => "Unable to connect to EduBoard API.",
        "error"   => $error
    ]);
    exit;
}

if ($status != 200) {
    echo json_encode([
        "success" => false,
        "message" => "EduBoard API returned HTTP {$status}.",
        "response" => $response
    ]);
    exit;
}

$data = json_decode($response, true);

echo json_encode([
    "success" => true,
    "session_id" => $data["session_id"],
    "image" => $data["image"]
]);