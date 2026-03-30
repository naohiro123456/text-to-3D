<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

ensure_storage_dirs();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Allow: GET, POST, DELETE, OPTIONS');
    http_response_code(204);
    exit;
}

function user_history_path(string $userId): string
{
    return HISTORY_DIR . '/' . $userId . '.json';
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = normalize_user_id((string)($_GET['userId'] ?? 'guest'));
    $path = user_history_path($userId);
    $items = read_json_file($path);
    if (!is_array($items)) {
        $items = [];
    }

    json_response([
        'success' => true,
        'userId' => $userId,
        'items' => array_values($items),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_json_body();
    $userId = normalize_user_id((string)($data['userId'] ?? 'guest'));
    $prompt = trim((string)($data['prompt'] ?? ''));
    $title = trim((string)($data['title'] ?? ''));
    if ($title === '') {
        $title = normalize_title($prompt);
    }

    if ($prompt === '') {
        json_response([
            'success' => false,
            'message' => 'prompt is required.',
        ], 400);
    }

    $path = user_history_path($userId);
    $current = read_json_file($path);
    if (!is_array($current)) {
        $current = [];
    }

    $entry = [
        'id' => uniqid('hist_', true),
        'prompt' => $prompt,
        'title' => $title,
        'mode' => (string)($data['mode'] ?? 'local'),
        'jobId' => (string)($data['jobId'] ?? ''),
        'glbUrl' => (string)($data['glbUrl'] ?? ''),
        'previewUrl' => (string)($data['previewUrl'] ?? ''),
        'thumbnailUrl' => (string)($data['thumbnailUrl'] ?? ''),
        'createdAt' => time(),
    ];

    $filtered = array_values(array_filter($current, static function ($item) use ($prompt) {
        return is_array($item) && (($item['prompt'] ?? '') !== $prompt);
    }));
    array_unshift($filtered, $entry);
    $filtered = array_slice($filtered, 0, 20);

    write_json_file($path, $filtered);

    json_response([
        'success' => true,
        'userId' => $userId,
        'items' => $filtered,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $data = read_json_body();
    $userId = normalize_user_id((string)($data['userId'] ?? 'guest'));
    $historyId = trim((string)($data['historyId'] ?? ''));

    if ($historyId === '') {
        json_response([
            'success' => false,
            'message' => 'historyId is required.',
        ], 400);
    }

    $path = user_history_path($userId);
    $current = read_json_file($path);
    if (!is_array($current)) {
        $current = [];
    }

    $filtered = array_values(array_filter($current, static function ($item) use ($historyId) {
        return is_array($item) && (($item['id'] ?? '') !== $historyId);
    }));

    write_json_file($path, $filtered);

    json_response([
        'success' => true,
        'userId' => $userId,
        'items' => $filtered,
    ]);
}

json_response([
    'success' => false,
    'message' => 'Method Not Allowed.',
], 405);
