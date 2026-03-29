<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

ensure_storage_dirs();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: GET, OPTIONS');
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response([
        'success' => false,
        'message' => 'Method Not Allowed. Use GET.',
    ], 405);
}

$jobId = trim((string)($_GET['jobId'] ?? ''));
$userId = normalize_user_id((string)($_GET['userId'] ?? 'guest'));

if ($jobId === '') {
    json_response([
        'success' => false,
        'message' => 'jobId is required.',
    ], 400);
}

$jobPath = JOBS_DIR . '/' . $jobId . '.json';
if (!file_exists($jobPath)) {
    json_response([
        'success' => false,
        'message' => 'job not found.',
    ], 404);
}

$job = read_json_file($jobPath);
if (($job['userId'] ?? '') !== $userId) {
    json_response([
        'success' => false,
        'message' => 'job does not belong to this user.',
    ], 403);
}

$now = time();
$elapsed = $now - (int)($job['createdAt'] ?? $now);
if (($job['status'] ?? '') === 'queued' && $elapsed >= 1) {
    $job['status'] = 'processing';
    $job['updatedAt'] = $now;
    write_json_file($jobPath, $job);
}

if (($job['status'] ?? '') !== 'completed' && $elapsed >= 3) {
    $prompt = (string)($job['prompt'] ?? '');
    $job['status'] = 'completed';
    $job['result'] = parse_prompt_to_params($prompt);
    $job['updatedAt'] = $now;
    write_json_file($jobPath, $job);
}

json_response([
    'success' => true,
    'job' => [
        'jobId' => $jobId,
        'status' => $job['status'] ?? 'queued',
        'prompt' => $job['prompt'] ?? '',
        'updatedAt' => $job['updatedAt'] ?? $now,
        'result' => $job['result'] ?? null,
    ],
]);
