<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

ensure_storage_dirs();
require_get_or_options();

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
$status = (string)($job['status'] ?? 'queued');

if ($status !== 'completed' && $status !== 'failed') {
    try {
        $currentProviderTaskId = (string)($job['providerTaskId'] ?? '');
        if ($currentProviderTaskId === '') {
            throw new RuntimeException('providerTaskId is missing.');
        }

        $providerTask = meshy_get_task($currentProviderTaskId);
        $providerStatus = (string)($providerTask['status'] ?? 'PENDING');
        $mappedStatus = meshy_map_status($providerStatus);

        $job['updatedAt'] = $now;
        $job['progress'] = (int)($providerTask['progress'] ?? 0);
        $job['providerStatus'] = $providerStatus;

        if ($job['stage'] === 'preview' && $providerStatus === 'SUCCEEDED') {
            $refineTaskId = meshy_create_refine_task(
                (string)($job['previewTaskId'] ?? $currentProviderTaskId),
                (string)($job['prompt'] ?? '')
            );
            $job['stage'] = 'refine';
            $job['providerTaskId'] = $refineTaskId;
            $job['refineTaskId'] = $refineTaskId;
            $job['status'] = 'processing';
            $job['providerStatus'] = 'PENDING';
            $job['progress'] = 0;
        } elseif ($job['stage'] === 'refine' && $providerStatus === 'SUCCEEDED') {
            $modelUrls = is_array($providerTask['model_urls'] ?? null) ? $providerTask['model_urls'] : [];
            $glbUrl = (string)($modelUrls['glb'] ?? '');
            $thumbnailUrl = (string)($providerTask['thumbnail_url'] ?? '');

            $job['status'] = 'completed';
            $job['result'] = [
                'shape' => null,
                'shapeName' => null,
                'color' => null,
                'colorName' => null,
                'scale' => null,
                'scaleName' => null,
                'roughness' => null,
                'metalness' => null,
                'glbUrl' => $glbUrl,
                'previewUrl' => $thumbnailUrl,
                'thumbnailUrl' => $thumbnailUrl,
                'title' => (string)($job['title'] ?? normalize_title((string)($job['prompt'] ?? ''))),
                'providerTaskId' => (string)($job['providerTaskId'] ?? ''),
            ];
        } elseif ($mappedStatus === 'failed') {
            $job['status'] = 'failed';
            $taskError = is_array($providerTask['task_error'] ?? null) ? $providerTask['task_error'] : [];
            $job['error'] = (string)($taskError['message'] ?? 'provider task failed');
        } else {
            $job['status'] = $mappedStatus;
        }

        write_json_file($jobPath, $job);
    } catch (Throwable $e) {
        $job['status'] = 'failed';
        $job['updatedAt'] = $now;
        $job['error'] = $e->getMessage();
        write_json_file($jobPath, $job);
    }
}

json_response([
    'success' => true,
    'job' => [
        'jobId' => $jobId,
        'status' => $job['status'] ?? 'queued',
        'stage' => $job['stage'] ?? 'preview',
        'provider' => $job['provider'] ?? 'meshy',
        'providerTaskId' => $job['providerTaskId'] ?? '',
        'progress' => (int)($job['progress'] ?? 0),
        'error' => $job['error'] ?? '',
        'title' => $job['title'] ?? normalize_title((string)($job['prompt'] ?? '')),
        'prompt' => $job['prompt'] ?? '',
        'updatedAt' => $job['updatedAt'] ?? $now,
        'result' => $job['result'] ?? null,
    ],
]);
