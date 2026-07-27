<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function loadData(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $file = __DIR__ . DIRECTORY_SEPARATOR . 'politicians.json';
    if (!is_file($file)) {
        respond(500, [
            'status' => false,
            'message' => 'politicians.json not found.',
        ]);
    }

    $json = file_get_contents($file);
    if ($json === false) {
        respond(500, [
            'status' => false,
            'message' => 'Unable to read politicians.json.',
        ]);
    }

    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        respond(500, [
            'status' => false,
            'message' => 'Invalid JSON data file.',
        ]);
    }

    if (!is_array($decoded)) {
        respond(500, [
            'status' => false,
            'message' => 'Invalid data format.',
        ]);
    }

    $cache = $decoded;
    return $cache;
}

function cleanInput(mixed $value, int $maxLen = 120): string
{
    $value = is_string($value) ? $value : (string)$value;
    $value = strip_tags($value);
    $value = trim($value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    if (strlen($value) > $maxLen) {
        $value = substr($value, 0, $maxLen);
    }
    return $value;
}

function normalizeText(string $value): string
{
    $value = cleanInput($value, 400);
    $value = function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function digitsOnly(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function isValidEmail(string $value): bool
{
    return (bool)filter_var($value, FILTER_VALIDATE_EMAIL);
}

function fieldValue(array $record, string $field): string
{
    $value = $record[$field] ?? '';
    return is_scalar($value) ? (string)$value : '';
}

function textMatchScore(string $needle, string $haystack, int $weight): int
{
    if ($needle === '' || $haystack === '') {
        return 0;
    }

    if ($needle === $haystack) {
        return $weight + 50;
    }

    if (str_starts_with($haystack, $needle)) {
        return $weight + 30;
    }

    if (str_contains($haystack, $needle)) {
        return $weight + 10;
    }

    return 0;
}

function numericMatchScore(string $needleDigits, string $haystackDigits, int $weight): int
{
    if ($needleDigits === '' || $haystackDigits === '') {
        return 0;
    }

    if ($needleDigits === $haystackDigits) {
        return $weight + 50;
    }

    if (str_starts_with($haystackDigits, $needleDigits)) {
        return $weight + 30;
    }

    if (str_contains($haystackDigits, $needleDigits)) {
        return $weight + 10;
    }

    return 0;
}

function tokenizeQuery(string $query): array
{
    $normalized = normalizeText($query);
    if ($normalized === '') {
        return [];
    }
    return array_values(array_filter(explode(' ', $normalized), static fn($t) => $t !== ''));
}

function recordScore(array $record, array $tokens): int
{
    $weights = [
        'name' => 100,
        'constituency' => 95,
        'district' => 85,
        'party' => 65,
        'phone' => 90,
        'telephone' => 85,
        'email' => 80,
        'address' => 25,
        'id' => 120,
        'constituency_no' => 120,
    ];

    $score = 0;

    foreach ($tokens as $token) {
        $tokenDigits = digitsOnly($token);
        $tokenScore = 0;

        if ($tokenDigits !== '') {
            $tokenScore = max(
                $tokenScore,
                numericMatchScore($tokenDigits, digitsOnly(fieldValue($record, 'id')), $weights['id'])
            );
            $tokenScore = max(
                $tokenScore,
                numericMatchScore($tokenDigits, digitsOnly(fieldValue($record, 'constituency_no')), $weights['constituency_no'])
            );
            $tokenScore = max(
                $tokenScore,
                numericMatchScore($tokenDigits, digitsOnly(fieldValue($record, 'phone')), $weights['phone'])
            );
            $tokenScore = max(
                $tokenScore,
                numericMatchScore($tokenDigits, digitsOnly(fieldValue($record, 'telephone')), $weights['telephone'])
            );
        } else {
            $tokenScore = max($tokenScore, textMatchScore($token, normalizeText(fieldValue($record, 'name')), $weights['name']));
            $tokenScore = max($tokenScore, textMatchScore($token, normalizeText(fieldValue($record, 'constituency')), $weights['constituency']));
            $tokenScore = max($tokenScore, textMatchScore($token, normalizeText(fieldValue($record, 'district')), $weights['district']));
            $tokenScore = max($tokenScore, textMatchScore($token, normalizeText(fieldValue($record, 'party')), $weights['party']));
            $tokenScore = max($tokenScore, textMatchScore($token, normalizeText(fieldValue($record, 'address')), $weights['address']));
            $tokenScore = max($tokenScore, textMatchScore($token, normalizeText(fieldValue($record, 'email')), $weights['email']));
        }

        if ($tokenScore === 0) {
            return -1; // AND semantics: every token must match somewhere
        }

        $score += $tokenScore;
    }

    return $score;
}

function filterByField(array $records, string $field, string $query): array
{
    $query = cleanInput($query);
    if ($query === '') {
        return $records;
    }

    $queryNorm = normalizeText($query);
    $queryDigits = digitsOnly($query);

    $out = [];
    foreach ($records as $record) {
        $value = fieldValue($record, $field);

        if ($field === 'id' || $field === 'constituency_no') {
            if ($queryDigits !== '' && digitsOnly($value) === $queryDigits) {
                $out[] = $record;
            }
            continue;
        }

        if ($field === 'phone' || $field === 'telephone') {
            $hay = digitsOnly($value);
            if ($queryDigits !== '' && str_contains($hay, $queryDigits)) {
                $out[] = $record;
            }
            continue;
        }

        $hay = normalizeText($value);
        if ($queryNorm !== '' && str_contains($hay, $queryNorm)) {
            $out[] = $record;
        }
    }

    return $out;
}

function validateQueryValue(string $value, int $maxLen = 120): string
{
    $value = cleanInput($value, $maxLen);
    if ($value === '') {
        return '';
    }
    return $value;
}

$records = loadData();
$params = [
    'q' => isset($_GET['q']) ? validateQueryValue((string)$_GET['q'], 200) : '',
    'id' => isset($_GET['id']) ? validateQueryValue((string)$_GET['id'], 20) : '',
    'district' => isset($_GET['district']) ? validateQueryValue((string)$_GET['district'], 80) : '',
    'party' => isset($_GET['party']) ? validateQueryValue((string)$_GET['party'], 40) : '',
    'constituency' => isset($_GET['constituency']) ? validateQueryValue((string)$_GET['constituency'], 120) : '',
    'phone' => isset($_GET['phone']) ? validateQueryValue((string)$_GET['phone'], 40) : '',
    'email' => isset($_GET['email']) ? validateQueryValue((string)$_GET['email'], 120) : '',
];

$hasAny = false;
foreach ($params as $value) {
    if ($value !== '') {
        $hasAny = true;
        break;
    }
}

if (!$hasAny) {
    respond(400, [
        'status' => false,
        'message' => 'Please provide at least one search parameter.',
    ]);
}

$results = $records;

// Exact ID search is a fast path.
if ($params['id'] !== '') {
    if (!preg_match('/^\d+$/', $params['id'])) {
        respond(400, [
            'status' => false,
            'message' => 'The id parameter must contain digits only.',
        ]);
    }
    $id = (int)$params['id'];
    $results = array_values(array_filter($results, static fn(array $r): bool => (int)($r['id'] ?? 0) === $id));
} else {
    // Field filters use partial, case-insensitive matching.
    if ($params['district'] !== '') {
        $results = filterByField($results, 'district', $params['district']);
    }
    if ($params['party'] !== '') {
        $results = filterByField($results, 'party', $params['party']);
    }
    if ($params['constituency'] !== '') {
        $results = filterByField($results, 'constituency', $params['constituency']);
    }
    if ($params['phone'] !== '') {
        $results = filterByField($results, 'phone', $params['phone']);
        if (!$results) {
            $results = filterByField($results, 'telephone', $params['phone']);
        }
    }
    if ($params['email'] !== '') {
        $emailNeedle = normalizeText($params['email']);
        $results = array_values(array_filter($results, static function (array $r) use ($emailNeedle): bool {
            return str_contains(normalizeText(fieldValue($r, 'email')), $emailNeedle);
        }));
    }

    if ($params['q'] !== '') {
        $tokens = tokenizeQuery($params['q']);
        if (!$tokens) {
            respond(400, [
                'status' => false,
                'message' => 'The q parameter is empty after validation.',
            ]);
        }

        $scored = [];
        foreach ($results as $record) {
            $score = recordScore($record, $tokens);
            if ($score >= 0) {
                $record['_score'] = $score;
                $scored[] = $record;
            }
        }

        usort($scored, static function (array $a, array $b): int {
            $scoreCmp = ((int)$b['_score']) <=> ((int)$a['_score']);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }
            return ((int)$a['id']) <=> ((int)$b['id']);
        });

        $results = array_map(static function (array $r): array {
            unset($r['_score']);
            return $r;
        }, $scored);
    } else {
        usort($results, static fn(array $a, array $b): int => ((int)$a['id']) <=> ((int)$b['id']));
    }
}

if (!$results) {
    respond(404, [
        'status' => false,
        'message' => 'No records found.',
    ]);
}

respond(200, [
    'status' => true,
    'total' => count($results),
    'results' => array_values($results),
]);
