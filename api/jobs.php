<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

ensure_storage_dirs();
require_get_or_options();

$userId = normalize_user_id((string)($_GET['userId'] ?? 'guest'));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 1) {
    $limit = 20;
}
if ($limit > 100) {
    $limit = 100;
}

$jobs = list_user_jobs($userId);

if ($statusFilter !== '') {
    $jobs = array_values(array_filter($jobs, static function ($job) use ($statusFilter) {
        return is_array($job) && ((string)($job['status'] ?? '') === $statusFilter);
    }));
}

$jobs = array_slice($jobs, 0, $limit);

$items = array_map(static function ($job) {
    return [
        'jobId' => $job['jobId'] ?? '',
        'title' => $job['title'] ?? normalize_title((string)($job['prompt'] ?? '')),
        'prompt' => $job['prompt'] ?? '',
        'status' => $job['status'] ?? 'queued',
        'stage' => $job['stage'] ?? 'preview',
        'progress' => (int)($job['progress'] ?? 0),
        'provider' => $job['provider'] ?? 'meshy',
        'providerTaskId' => $job['providerTaskId'] ?? '',
        'updatedAt' => (int)($job['updatedAt'] ?? 0),
        'createdAt' => (int)($job['createdAt'] ?? 0),
        'result' => $job['result'] ?? null,
    ];
}, $jobs);

json_response([
    'success' => true,
    'userId' => $userId,
    'items' => $items,
]);
