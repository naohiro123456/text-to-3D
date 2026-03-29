<?php

declare(strict_types=1);

const STORAGE_ROOT = __DIR__ . '/storage';
const JOBS_DIR = STORAGE_ROOT . '/jobs';
const HISTORY_DIR = STORAGE_ROOT . '/history';

function ensure_storage_dirs(): void
{
    if (!is_dir(STORAGE_ROOT)) {
        mkdir(STORAGE_ROOT, 0775, true);
    }
    if (!is_dir(JOBS_DIR)) {
        mkdir(JOBS_DIR, 0775, true);
    }
    if (!is_dir(HISTORY_DIR)) {
        mkdir(HISTORY_DIR, 0775, true);
    }
}

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function read_json_body(): array
{
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput ?: '{}', true);

    if (!is_array($data)) {
        json_response([
            'success' => false,
            'message' => 'Invalid JSON body.',
        ], 400);
    }

    return $data;
}

function normalize_user_id(?string $rawUserId): string
{
    $candidate = trim((string)$rawUserId);
    if ($candidate === '') {
        return 'guest';
    }

    $normalized = preg_replace('/[^a-zA-Z0-9_-]/', '_', $candidate);
    if (!is_string($normalized) || $normalized === '') {
        return 'guest';
    }

    return substr($normalized, 0, 80);
}

function parse_prompt_to_params(string $prompt): array
{
    $shape = 'sphere';
    if (preg_match('/cube|box|立方体|キューブ/ui', $prompt)) {
        $shape = 'box';
    } elseif (preg_match('/cylinder|円柱|筒/ui', $prompt)) {
        $shape = 'cylinder';
    } elseif (preg_match('/torus|donut|ドーナツ|トーラス/ui', $prompt)) {
        $shape = 'torus';
    } elseif (preg_match('/cone|円すい|コーン/ui', $prompt)) {
        $shape = 'cone';
    }

    $color = 0x278aff;
    $colorName = 'blue';
    if (preg_match('/red|赤/ui', $prompt)) {
        $color = 0xff4d5e;
        $colorName = 'red';
    } elseif (preg_match('/green|緑/ui', $prompt)) {
        $color = 0x39b86f;
        $colorName = 'green';
    } elseif (preg_match('/yellow|黄/ui', $prompt)) {
        $color = 0xffcb47;
        $colorName = 'yellow';
    } elseif (preg_match('/white|白/ui', $prompt)) {
        $color = 0xf5f7fb;
        $colorName = 'white';
    } elseif (preg_match('/black|黒/ui', $prompt)) {
        $color = 0x2d3540;
        $colorName = 'black';
    }

    $scale = 1.0;
    $scaleName = 'medium';
    if (preg_match('/small|小さい|ミニ/ui', $prompt)) {
        $scale = 0.75;
        $scaleName = 'small';
    } elseif (preg_match('/large|big|大きい|巨大/ui', $prompt)) {
        $scale = 1.35;
        $scaleName = 'large';
    }

    $glbByShape = [
        'sphere' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Sphere/glTF-Binary/Sphere.glb',
        'box' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Cube/glTF-Binary/Cube.glb',
        'cylinder' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Cylinder/glTF-Binary/Cylinder.glb',
        'torus' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Torus/glTF-Binary/Torus.glb',
        'cone' => 'https://raw.githubusercontent.com/KhronosGroup/glTF-Sample-Assets/main/Models/Cone/glTF-Binary/Cone.glb',
    ];

    return [
        'shape' => $shape,
        'shapeName' => $shape,
        'color' => $color,
        'colorName' => $colorName,
        'scale' => $scale,
        'scaleName' => $scaleName,
        'roughness' => 0.35,
        'metalness' => 0.35,
        'glbUrl' => $glbByShape[$shape] ?? null,
    ];
}

function read_json_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }

    $raw = file_get_contents($path);
    $parsed = json_decode((string)$raw, true);
    return is_array($parsed) ? $parsed : [];
}

function write_json_file(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
