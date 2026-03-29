<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

ensure_storage_dirs();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: POST, OPTIONS');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Method Not Allowed. Use POST.',
    ], 405);
}

$data = read_json_body();
$prompt = trim((string)($data['prompt'] ?? ''));
$userId = normalize_user_id((string)($data['userId'] ?? 'guest'));

if ($prompt === '') {
    json_response([
        'success' => false,
        'message' => 'prompt is required.',
    ], 400);
}

$jobId = uniqid('job_', true);
$job = [
    'jobId' => $jobId,
    'userId' => $userId,
    'prompt' => $prompt,
    'status' => 'queued',
    'createdAt' => time(),
    'updatedAt' => time(),
    'result' => null,
];

$jobPath = JOBS_DIR . '/' . $jobId . '.json';
write_json_file($jobPath, $job);

json_response([
    'success' => true,
    'mode' => 'async-job',
    'message' => 'ジョブを受け付けました。ステータスAPIをポーリングしてください。',
    'job' => [
        'jobId' => $jobId,
        'status' => 'queued',
        'pollUrl' => './api/generate-3d-status.php?jobId=' . urlencode($jobId) . '&userId=' . urlencode($userId),
    ],
]);
