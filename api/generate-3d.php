<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

ensure_storage_dirs();
require_post_or_options();

$data = read_json_body();
$prompt = trim((string)($data['prompt'] ?? ''));
$userId = normalize_user_id((string)($data['userId'] ?? 'guest'));

if ($prompt === '') {
    json_response([
        'success' => false,
        'message' => 'prompt is required.',
    ], 400);
}

$title = trim((string)($data['title'] ?? ''));
if ($title === '') {
    $title = normalize_title($prompt);
}

$jobId = uniqid('job_', true);
$externalTaskId = '';
$jobStatus = 'queued';
$providerMessage = 'queued';

try {
    $externalTaskId = meshy_create_preview_task($prompt);
    $jobStatus = 'processing';
    $providerMessage = 'preview task created';
} catch (Throwable $e) {
    $jobStatus = 'failed';
    $providerMessage = $e->getMessage();
}

$job = [
    'jobId' => $jobId,
    'userId' => $userId,
    'prompt' => $prompt,
    'title' => $title,
    'status' => $jobStatus,
    'createdAt' => time(),
    'updatedAt' => time(),
    'provider' => 'meshy',
    'providerTaskId' => $externalTaskId,
    'stage' => 'preview',
    'progress' => 0,
    'previewTaskId' => $externalTaskId,
    'refineTaskId' => null,
    'error' => $jobStatus === 'failed' ? $providerMessage : '',
    'result' => null,
];

$jobPath = JOBS_DIR . '/' . $jobId . '.json';
write_json_file($jobPath, $job);

if ($jobStatus === 'failed') {
    json_response([
        'success' => false,
        'mode' => 'async-job',
        'message' => 'Meshyジョブ作成に失敗しました。',
        'error' => $providerMessage,
        'job' => [
            'jobId' => $jobId,
            'status' => 'failed',
            'pollUrl' => './api/generate-3d-status.php?jobId=' . urlencode($jobId) . '&userId=' . urlencode($userId),
        ],
    ], 502);
}

json_response([
    'success' => true,
    'mode' => 'async-job',
    'message' => 'Meshyジョブを受け付けました。',
    'job' => [
        'jobId' => $jobId,
        'status' => $jobStatus,
        'providerTaskId' => $externalTaskId,
        'stage' => 'preview',
        'pollUrl' => './api/generate-3d-status.php?jobId=' . urlencode($jobId) . '&userId=' . urlencode($userId),
    ],
]);
