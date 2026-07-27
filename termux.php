#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Karnataka MLA Termux Tool
 *
 * Features:
 * - Beautiful terminal banner
 * - Indexed search using politicians.json
 * - Search by name, district, constituency, constituency number, party, phone, telephone, email
 * - Case-insensitive, punctuation-insensitive, multi-keyword search
 * - Works in plain PHP 8+ without extensions beyond core PHP
 */

const DATA_FILE = __DIR__ . '/politicians.json';
const INDEX_FILE = __DIR__ . '/search_index.json';

/** @var array<int, array<string, mixed>> */
static $POLITICIANS_CACHE = null;
/** @var array<string, array<int>> */
static $INDEX_CACHE = null;

function out(string $text = ''): void {
    fwrite(STDOUT, $text . PHP_EOL);
}

function color(string $text, string $code = '0'): string {
    return "\033[{$code}m{$text}\033[0m";
}

function banner(): void {
    $lines = [
        "╔══════════════════════════════════════════════════════╗",
        "║      KARNATAKA MLA SEARCH TOOL  •  MADE BY MAHESH    ║",
        "╠══════════════════════════════════════════════════════╣",
        "║  Fast search over Karnataka MLA records              ║",
        "║  Search by name, district, constituency, party etc. ║",
        "╚══════════════════════════════════════════════════════╝",
    ];

    out(color("\n" . implode(PHP_EOL, $lines), '1;36'));
    out(color("[1] Search all fields", '1;32'));
    out(color("[2] Search by field", '1;32'));
    out(color("[3] Show record by ID", '1;32'));
    out(color("[4] Rebuild index", '1;32'));
    out(color("[0] Exit", '1;31'));
}

function pause(): void {
    out(color("\nPress Enter to continue...", '1;90'));
    fgets(STDIN);
}

function normalize(string $text): string {
    $text = strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function politicians(): array {
    global $POLITICIANS_CACHE;
    if (is_array($POLITICIANS_CACHE)) {
        return $POLITICIANS_CACHE;
    }

    if (!is_file(DATA_FILE)) {
        fwrite(STDERR, "Missing politicians.json\n");
        exit(1);
    }

    $json = file_get_contents(DATA_FILE);
    if ($json === false) {
        fwrite(STDERR, "Unable to read politicians.json\n");
        exit(1);
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        fwrite(STDERR, "Invalid politicians.json format\n");
        exit(1);
    }

    $POLITICIANS_CACHE = $data;
    return $data;
}

function record_text(array $record): string {
    $parts = [
        (string)($record['name'] ?? ''),
        (string)($record['constituency'] ?? ''),
        (string)($record['constituency_no'] ?? ''),
        (string)($record['district'] ?? ''),
        (string)($record['party'] ?? ''),
        (string)($record['address'] ?? ''),
        (string)($record['phone'] ?? ''),
        (string)($record['telephone'] ?? ''),
        (string)($record['email'] ?? ''),
    ];
    return normalize(implode(' ', $parts));
}

function build_index(): array {
    $records = politicians();
    $index = [];

    foreach ($records as $record) {
        $id = (int)($record['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $tokens = preg_split('/\s+/', record_text($record)) ?: [];
        $tokens[] = (string)$id;

        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (!isset($index[$token])) {
                $index[$token] = [];
            }
            $index[$token][$id] = $id;
        }
    }

    foreach ($index as $token => $ids) {
        ksort($index[$token]);
        $index[$token] = array_values($ids);
    }

    file_put_contents(INDEX_FILE, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $index;
}

function index_data(): array {
    global $INDEX_CACHE;
    if (is_array($INDEX_CACHE)) {
        return $INDEX_CACHE;
    }

    if (is_file(INDEX_FILE)) {
        $json = file_get_contents(INDEX_FILE);
        if ($json !== false) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $INDEX_CACHE = $data;
                return $data;
            }
        }
    }

    $INDEX_CACHE = build_index();
    return $INDEX_CACHE;
}

function fetch_by_ids(array $ids): array {
    $records = politicians();
    $map = [];
    foreach ($records as $record) {
        $map[(int)($record['id'] ?? 0)] = $record;
    }

    $out = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if (isset($map[$id])) {
            $out[] = $map[$id];
        }
    }
    return $out;
}

function score_record(array $record, string $query): int {
    $haystack = record_text($record);
    $name = normalize((string)($record['name'] ?? ''));
    $district = normalize((string)($record['district'] ?? ''));
    $constituency = normalize((string)($record['constituency'] ?? ''));
    $party = normalize((string)($record['party'] ?? ''));
    $phone = normalize((string)($record['phone'] ?? ''));
    $telephone = normalize((string)($record['telephone'] ?? ''));
    $email = normalize((string)($record['email'] ?? ''));
    $no = normalize((string)($record['constituency_no'] ?? ''));

    $score = 0;
    $terms = preg_split('/\s+/', normalize($query)) ?: [];
    $terms = array_values(array_filter($terms, static fn($t) => $t !== ''));
    $q = normalize($query);

    if ($q === '') {
        return 0;
    }

    if ($name === $q || $district === $q || $constituency === $q || $party === $q || $phone === $q || $telephone === $q || $email === $q || $no === $q) {
        $score += 1000;
    }

    if (str_starts_with($name, $q) || str_starts_with($district, $q) || str_starts_with($constituency, $q) || str_starts_with($party, $q)) {
        $score += 250;
    }

    if (str_contains($name, $q)) $score += 180;
    if (str_contains($district, $q)) $score += 160;
    if (str_contains($constituency, $q)) $score += 160;
    if (str_contains($party, $q)) $score += 140;
    if (str_contains($phone, $q) || str_contains($telephone, $q)) $score += 220;
    if (str_contains($email, $q)) $score += 200;
    if (str_contains($no, $q)) $score += 220;

    foreach ($terms as $term) {
        if (str_contains($haystack, $term)) {
            $score += 35;
        }
        if (str_contains($name, $term)) $score += 15;
        if (str_contains($district, $term)) $score += 15;
        if (str_contains($constituency, $term)) $score += 15;
        if (str_contains($party, $term)) $score += 15;
        if (str_contains($phone, $term) || str_contains($telephone, $term)) $score += 20;
        if (str_contains($email, $term)) $score += 20;
        if (str_contains($no, $term)) $score += 20;
    }

    return $score;
}

function search_records(string $query, ?string $field = null): array {
    $records = politicians();
    $queryNorm = normalize($query);

    if ($queryNorm === '') {
        return [];
    }

    if ($field !== null && $field !== '') {
        $field = strtolower(trim($field));
        $filtered = [];
        foreach ($records as $record) {
            $value = (string)($record[$field] ?? '');
            if ($field === 'id') {
                $value = (string)($record['id'] ?? '');
            }
            $valueNorm = normalize($value);
            if ($valueNorm === '') continue;
            if (str_contains($valueNorm, $queryNorm)) {
                $record['_score'] = 1000 + (str_starts_with($valueNorm, $queryNorm) ? 100 : 0);
                $filtered[] = $record;
                continue;
            }

            $terms = preg_split('/\s+/', $queryNorm) ?: [];
            $hit = 0;
            foreach ($terms as $term) {
                if ($term !== '' && str_contains($valueNorm, $term)) {
                    $hit++;
                }
            }
            if ($hit > 0) {
                $record['_score'] = $hit * 50;
                $filtered[] = $record;
            }
        }

        usort($filtered, static function (array $a, array $b): int {
            return ((int)($b['_score'] ?? 0)) <=> ((int)($a['_score'] ?? 0));
        });
        return $filtered;
    }

    // Indexed first pass
    $index = index_data();
    $candidateIds = [];
    $terms = preg_split('/\s+/', $queryNorm) ?: [];
    $terms = array_values(array_filter($terms, static fn($t) => $t !== ''));

    foreach ($terms as $term) {
        if (isset($index[$term])) {
            foreach ($index[$term] as $id) {
                $candidateIds[(int)$id] = (int)$id;
            }
        }
    }

    // Prefix fallback for better recall.
    if (!$candidateIds) {
        foreach ($index as $token => $ids) {
            if (str_starts_with($token, $queryNorm) || str_contains($token, $queryNorm)) {
                foreach ($ids as $id) {
                    $candidateIds[(int)$id] = (int)$id;
                }
            }
        }
    }

    if ($candidateIds) {
        $candidates = fetch_by_ids(array_values($candidateIds));
    } else {
        $candidates = $records;
    }

    $scored = [];
    foreach ($candidates as $record) {
        $score = score_record($record, $queryNorm);
        if ($score > 0) {
            $record['_score'] = $score;
            $scored[] = $record;
        }
    }

    usort($scored, static function (array $a, array $b): int {
        $byScore = ((int)($b['_score'] ?? 0)) <=> ((int)($a['_score'] ?? 0));
        if ($byScore !== 0) return $byScore;
        return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
    });

    return $scored;
}

function render_table(array $records, int $limit = 15): void {
    $show = array_slice($records, 0, $limit);
    if (!$show) {
        out(color("No records found.", '1;31'));
        return;
    }

    foreach ($show as $record) {
        out(color(str_repeat('─', 72), '1;90'));
        out(color("ID: " . (string)($record['id'] ?? ''), '1;36') . '  ' . color((string)($record['name'] ?? ''), '1;33'));
        out("Constituency : " . (string)($record['constituency'] ?? ''));
        out("District     : " . (string)($record['district'] ?? ''));
        out("Party        : " . (string)($record['party'] ?? ''));
        out("Phone        : " . (string)($record['phone'] ?? ''));
        out("Telephone    : " . (string)($record['telephone'] ?? ''));
        out("Email        : " . (string)($record['email'] ?? ''));
        out("Address      : " . (string)($record['address'] ?? ''));
    }
    out(color(str_repeat('─', 72), '1;90'));
    out(color('Showing ' . count($show) . ' of ' . count($records) . ' result(s).', '1;32'));
}

function prompt(string $label): string {
    fwrite(STDOUT, color($label, '1;36'));
    $input = fgets(STDIN);
    return $input === false ? '' : trim($input);
}

function search_all(): void {
    $q = prompt("Enter search query: ");
    $results = search_records($q);
    render_table($results);
}

function search_field(): void {
    out("Available fields: id, name, district, constituency, constituency_no, party, phone, telephone, email");
    $field = prompt("Field: ");
    $q = prompt("Value: ");
    $results = search_records($q, $field);
    render_table($results);
}

function show_by_id(): void {
    $id = (int)prompt("Enter ID: ");
    $results = search_records((string)$id, 'id');
    render_table($results, 1);
}

function run_cli_args(array $argv): bool {
    if (count($argv) < 2) {
        return false;
    }

    $cmd = strtolower((string)($argv[1] ?? ''));
    if ($cmd === '--help' || $cmd === '-h' || $cmd === 'help') {
        banner();
        out("
Usage:");
        out("  php termux_tool.php                # interactive mode");
        out("  php termux_tool.php search raichur # search all fields");
        out("  php termux_tool.php field party BJP");
        out("  php termux_tool.php id 53");
        out("  php termux_tool.php rebuild");
        return true;
    }

    if ($cmd === 'rebuild') {
        out(color("Rebuilding index...", '1;33'));
        build_index();
        out(color("Index rebuilt successfully.", '1;32'));
        return true;
    }

    if ($cmd === 'search') {
        $query = implode(' ', array_slice($argv, 2));
        $results = search_records($query);
        render_table($results);
        return true;
    }

    if ($cmd === 'field') {
        $field = (string)($argv[2] ?? '');
        $query = implode(' ', array_slice($argv, 3));
        $results = search_records($query, $field);
        render_table($results);
        return true;
    }

    if ($cmd === 'id') {
        $id = (string)($argv[2] ?? '');
        $results = search_records($id, 'id');
        render_table($results, 1);
        return true;
    }

    return false;
}

function main(): void {
    while (true) {
        if (function_exists('system') && stripos(PHP_OS_FAMILY, 'Windows') === false) {
            @system('clear');
        }
        banner();
        $choice = prompt("\nSelect option: ");

        switch ($choice) {
            case '1':
                search_all();
                pause();
                break;
            case '2':
                search_field();
                pause();
                break;
            case '3':
                show_by_id();
                pause();
                break;
            case '4':
                out(color("Rebuilding index...", '1;33'));
                build_index();
                out(color("Index rebuilt successfully.", '1;32'));
                pause();
                break;
            case '0':
                out(color("don't believe any politician jai hind 🇮🇳", '1;32'));
                exit(0);
            default:
                out(color("Invalid choice.", '1;31'));
                pause();
        }
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this file in Termux/CLI only.\n");
    exit(1);
}

if (isset($argv) && is_array($argv) && run_cli_args($argv)) {
    exit(0);
}

main();
