<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

const FORM_STATE_SELECT_BRANCH = 'select_branch';
const FORM_STATE_DOWNLOAD = 'download';
const CONFIG_FILE = __DIR__ . '/update.config.php';

$messages = [];
$errors = [];

$config = loadConfig();

enforceAuthentication($config['auth'] ?? []);

$owner = trim($_POST['owner'] ?? ($config['owner'] ?? ''));
$repository = trim($_POST['repository'] ?? ($config['repository'] ?? ''));
$branch = trim($_POST['branch'] ?? '');
$state = $_POST['state'] ?? null;
$createBackup = isset($_POST['create_backup']);
$configuredTargetDirectory = $config['target_directory'] ?? __DIR__;
$targetDirectoryInput = $_POST['target_directory'] ?? $configuredTargetDirectory;
$targetDirectory = $targetDirectoryInput === '' ? '' : rtrim($targetDirectoryInput, '/');
$excludesFromConfig = array_map('strval', $config['excludes'] ?? []);
$excludesInput = $_POST['excludes'] ?? implode("\n", $excludesFromConfig);
$excludes = normalizeExcludes($excludesInput);

if ($state === FORM_STATE_SELECT_BRANCH && ($owner === '' || $repository === '')) {
    $errors[] = 'Bitte geben Sie sowohl einen Owner als auch ein Repository an.';
    $state = null;
}

$branches = [];
if ($state === FORM_STATE_SELECT_BRANCH && !$errors) {
    try {
        $branches = fetchBranches($owner, $repository);
        persistConfig($owner, $repository, $excludes, $targetDirectory);
        if ($branches === []) {
            $errors[] = 'Keine Branches gefunden. Prüfen Sie Owner und Repository.';
            $state = null;
        }
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
        $state = null;
    }
}

if ($state === FORM_STATE_DOWNLOAD && $owner && $repository && $branch) {
    try {
        validateTargetDirectory($targetDirectory);
        $messages[] = 'Starte Workflow: Lade Branch herunter und aktualisiere Dateien.';

        $zipPath = downloadBranchZip($owner, $repository, $branch);
        $messages[] = "ZIP-Archiv wurde heruntergeladen: {$zipPath}";

        persistConfig($owner, $repository, $excludes, $targetDirectory);

        if ($createBackup) {
            $backupPath = createBackup($targetDirectory);
            if ($backupPath !== null) {
                $messages[] = "Backup erstellt: {$backupPath}";
            }
        }

        $skippedPaths = extractZip($zipPath, $targetDirectory, $excludes);
        if ($skippedPaths !== []) {
            $messages[] = 'Folgende Pfade wurden vom Update ausgeschlossen: ' . implode(', ', $skippedPaths);
        }
        $messages[] = 'Update abgeschlossen. Dateien wurden überschrieben.';
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }

    $state = null;
}

function fetchBranches(string $owner, string $repo): array
{
    $url = sprintf(
        'https://api.github.com/repos/%s/%s/branches?per_page=100',
        rawurlencode($owner),
        rawurlencode($repo)
    );
    $response = githubRequest($url);

    $branches = [];
    foreach ($response as $branch) {
        if (!isset($branch['name'])) {
            continue;
        }

        $commit = $branch['commit']['commit'] ?? [];
        $committerDate = $commit['committer']['date'] ?? null;
        $authorDate = $commit['author']['date'] ?? null;
        $commitDate = $committerDate ?? $authorDate;

        $branches[] = [
            'name' => (string) $branch['name'],
            'commit_date' => $commitDate,
            'commit_committed_date' => $committerDate,
            'commit_authored_date' => $authorDate,
            'commit_message' => isset($commit['message']) ? (string) $commit['message'] : null,
            'commit_sha' => isset($branch['commit']['sha']) ? (string) $branch['commit']['sha'] : null,
        ];
    }

    usort($branches, static function (array $a, array $b): int {
        $dateA = $a['commit_date'] ?? null;
        $dateB = $b['commit_date'] ?? null;

        if ($dateA === $dateB) {
            return strcmp($a['name'], $b['name']);
        }

        if ($dateA === null) {
            return 1;
        }

        if ($dateB === null) {
            return -1;
        }

        return $dateA < $dateB ? 1 : -1;
    });

    return $branches;
}

function githubRequest(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => [
                'User-Agent: update-script',
                'Accept: application/vnd.github+json',
            ],
            'timeout' => 20,
        ],
    ]);

    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        throw new RuntimeException('GitHub Anfrage fehlgeschlagen. Prüfen Sie Owner/Repository oder Ihre Netzwerkverbindung.');
    }

    $decoded = json_decode($result, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

function downloadBranchZip(string $owner, string $repo, string $branch): string
{
    $url = sprintf('https://codeload.github.com/%s/%s/zip/refs/heads/%s', rawurlencode($owner), rawurlencode($repo), rawurlencode($branch));
    $tempFile = tempnam(sys_get_temp_dir(), 'update_');
    if ($tempFile === false) {
        throw new RuntimeException('Konnte temporäre Datei nicht erstellen.');
    }

    $fp = fopen($tempFile, 'wb');
    if ($fp === false) {
        throw new RuntimeException('Konnte temporäre Datei nicht öffnen.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'update-script',
        CURLOPT_FAILONERROR => true,
        CURLOPT_TIMEOUT => 120,
    ]);

    if (!curl_exec($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        throw new RuntimeException('Download fehlgeschlagen: ' . $error);
    }

    curl_close($ch);
    fclose($fp);

    return $tempFile;
}

function loadConfig(): array
{
    $defaults = defaultConfig();

    if (!is_readable(CONFIG_FILE)) {
        return $defaults;
    }

    $data = include CONFIG_FILE;

    if (!is_array($data)) {
        return $defaults;
    }

    return array_replace_recursive($defaults, $data);
}

function defaultConfig(): array
{
    return [
        'owner' => '',
        'repository' => '',
        'excludes' => [],
        'target_directory' => __DIR__,
        'auth' => [
            'username' => 'admin',
            'password_hash' => '$2y$12$v1OUgsjnzQ7o3vrZCMSxteopMaWbIoB5KGt7HlPgQuqIuMdKHo2Y2',
        ],
    ];
}

function persistConfig(string $owner, string $repository, array $excludes, string $targetDirectory): void
{
    if ($owner === '' || $repository === '') {
        return;
    }

    $config = loadConfig();

    $config['owner'] = $owner;
    $config['repository'] = $repository;
    $config['excludes'] = array_values($excludes);
    if ($targetDirectory !== '') {
        $config['target_directory'] = $targetDirectory;
    }

    $export = var_export($config, true);
    $content = "<?php\nreturn {$export};\n";

    if (@file_put_contents(CONFIG_FILE, $content, LOCK_EX) === false) {
        throw new RuntimeException('Konfiguration konnte nicht gespeichert werden.');
    }
}

function extractZip(string $zipPath, string $targetDirectory, array $excludes): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('ZIP-Archiv konnte nicht geöffnet werden.');
    }

    $tempDir = $targetDirectory . '/.update_tmp_' . uniqid();
    if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
        $zip->close();
        throw new RuntimeException('Temporäres Verzeichnis konnte nicht erstellt werden.');
    }

    if (!$zip->extractTo($tempDir)) {
        $zip->close();
        removeDirectory($tempDir);
        throw new RuntimeException('Entpacken des Archivs fehlgeschlagen.');
    }
    $zip->close();

    // GitHub zip extrahiert als repo-branchName
    $entries = scandir($tempDir);
    if ($entries === false) {
        removeDirectory($tempDir);
        throw new RuntimeException('Temporäres Verzeichnis konnte nicht gelesen werden.');
    }

    $skipped = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $sourcePath = $tempDir . '/' . $entry;
        if (is_dir($sourcePath)) {
            copyDirectory($sourcePath, $targetDirectory, $excludes, $skipped);
        }
    }

    removeDirectory($tempDir);
    ksort($skipped);

    return array_keys($skipped);
}

function copyDirectory(string $source, string $destination, array $excludes, array &$skipped): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relativePath = substr($item->getPathname(), strlen($source));
        $relativePath = str_replace('\\', '/', $relativePath);
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            continue;
        }

        $matchedExclude = matchExclude($relativePath, $excludes);
        if ($matchedExclude !== null) {
            $skipped[$matchedExclude] = true;
            continue;
        }

        $targetPath = rtrim($destination, '/\\') . '/' . $relativePath;

        if ($item->isDir()) {
            if (!is_dir($targetPath) && !mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                throw new RuntimeException('Konnte Verzeichnis nicht erstellen: ' . $targetPath);
            }
        } else {
            $parentDir = dirname($targetPath);
            if (!is_dir($parentDir) && !mkdir($parentDir, 0775, true) && !is_dir($parentDir)) {
                throw new RuntimeException('Konnte Verzeichnis nicht erstellen: ' . $parentDir);
            }
            if (!copy($item->getPathname(), $targetPath)) {
                throw new RuntimeException('Konnte Datei nicht kopieren: ' . $targetPath);
            }
        }
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        $path = $item->getPathname();
        $item->isDir() ? rmdir($path) : unlink($path);
    }

    rmdir($directory);
}

function createBackup(string $targetDirectory): ?string
{
    if (!is_dir($targetDirectory)) {
        return null;
    }

    $zipPath = $targetDirectory . '/backup_' . date('Ymd_His') . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Backup konnte nicht erstellt werden.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($targetDirectory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $filePath = $item->getPathname();
        $relativePath = substr($filePath, strlen($targetDirectory) + 1);

        if ($relativePath === '' || str_starts_with($relativePath, 'backup_')) {
            continue;
        }

        if ($item->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($filePath, $relativePath);
        }
    }

    $zip->close();

    return $zipPath;
}

function validateTargetDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        throw new RuntimeException('Zielverzeichnis existiert nicht: ' . $directory);
    }

    if (!is_writable($directory)) {
        throw new RuntimeException('Zielverzeichnis ist nicht beschreibbar: ' . $directory);
    }
}

function normalizeExcludes(string $input): array
{
    if ($input === '') {
        return [];
    }

    $lines = preg_split('/\R+/', $input) ?: [];
    $normalized = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $line = str_replace('\\', '/', $line);
        $line = ltrim($line, './');
        $line = rtrim($line, '/');

        if ($line === '') {
            continue;
        }

        $normalized[$line] = true;
    }

    return array_keys($normalized);
}

function matchExclude(string $relativePath, array $excludes): ?string
{
    foreach ($excludes as $exclude) {
        if ($relativePath === $exclude || str_starts_with($relativePath, $exclude . '/')) {
            return $exclude;
        }
    }

    return null;
}

function enforceAuthentication(array $authConfig): void
{
    $username = $authConfig['username'] ?? '';
    $passwordHash = $authConfig['password_hash'] ?? '';

    if ($username === '' || $passwordHash === '') {
        return;
    }

    $providedUser = $_SERVER['PHP_AUTH_USER'] ?? null;
    $providedPassword = $_SERVER['PHP_AUTH_PW'] ?? null;

    if ($providedUser !== $username || !is_string($providedPassword) || !password_verify($providedPassword, $passwordHash)) {
        header('WWW-Authenticate: Basic realm="Update Script"');
        header('HTTP/1.1 401 Unauthorized');
        echo 'Authentifizierung erforderlich.';
        exit;
    }
}

function formatIsoDate(?string $isoDate): ?string
{
    if (!$isoDate) {
        return null;
    }

    try {
        $date = new DateTimeImmutable($isoDate);
        $date = $date->setTimezone(new DateTimeZone(date_default_timezone_get()));

        return $date->format('d.m.Y H:i');
    } catch (Exception) {
        return null;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Repository Update</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg-gradient: linear-gradient(135deg, #eef2ff 0%, #fdf2f8 35%, #ecfdf5 100%);
            --bg-accent-a: rgba(99, 102, 241, 0.28);
            --bg-accent-b: rgba(236, 72, 153, 0.25);
            --bg-accent-c: rgba(16, 185, 129, 0.25);
            --surface: rgba(255, 255, 255, 0.86);
            --surface-strong: rgba(255, 255, 255, 0.95);
            --text-color: #0f172a;
            --text-secondary: #475569;
            --border-color: rgba(148, 163, 184, 0.3);
            --accent: #6366f1;
            --accent-strong: #4f46e5;
            --accent-soft: rgba(99, 102, 241, 0.15);
            --accent-soft-strong: rgba(79, 70, 229, 0.18);
            --success: #16a34a;
            --error: #dc2626;
            font-family: "Inter", "Manrope", "Segoe UI", system-ui, -apple-system, sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg-gradient: linear-gradient(135deg, #111827 0%, #1f2937 35%, #0f172a 100%);
                --surface: rgba(17, 24, 39, 0.8);
                --surface-strong: rgba(15, 23, 42, 0.92);
                --text-color: #f8fafc;
                --text-secondary: #cbd5f5;
                --border-color: rgba(148, 163, 184, 0.25);
                --accent-soft: rgba(129, 140, 248, 0.2);
                --accent-soft-strong: rgba(129, 140, 248, 0.25);
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            background: var(--bg-gradient);
            color: var(--text-color);
            position: relative;
            overflow-x: hidden;
        }

        .backdrop {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .backdrop span {
            position: absolute;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            filter: blur(90px);
            opacity: 0.6;
            animation: float 18s ease-in-out infinite;
        }

        .backdrop .orb-a {
            background: var(--bg-accent-a);
            top: -80px;
            left: -120px;
            animation-delay: 0s;
        }

        .backdrop .orb-b {
            background: var(--bg-accent-b);
            top: 30%;
            right: -160px;
            animation-delay: 4s;
        }

        .backdrop .orb-c {
            background: var(--bg-accent-c);
            bottom: -140px;
            left: 50%;
            transform: translateX(-50%);
            animation-delay: 8s;
        }

        @keyframes float {
            0%,
            100% {
                transform: translate3d(0, 0, 0) scale(1);
            }

            50% {
                transform: translate3d(20px, -30px, 0) scale(1.05);
            }
        }

        .layout {
            width: min(980px, calc(100% - 3rem));
            margin: 3.5rem auto 4.5rem;
            position: relative;
            z-index: 1;
        }

        .app-card {
            background: var(--surface);
            border-radius: 24px;
            border: 1px solid var(--border-color);
            box-shadow: 0 30px 90px rgba(15, 23, 42, 0.16);
            backdrop-filter: blur(18px);
            padding: clamp(1.75rem, 2.5vw + 1.25rem, 3rem);
            position: relative;
            overflow: hidden;
        }

        .app-card::before {
            content: "";
            position: absolute;
            inset: 1px;
            border-radius: 22px;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.85), rgba(255, 255, 255, 0.45));
            z-index: -1;
        }

        .hero {
            display: grid;
            gap: 0.75rem;
            margin-bottom: 2.75rem;
        }

        .hero-label {
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--accent-strong);
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .hero-label::before {
            content: "";
            width: 30px;
            height: 1px;
            background: currentColor;
            opacity: 0.45;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(1.85rem, 1.2rem + 1.85vw, 2.8rem);
            font-weight: 700;
            letter-spacing: -0.015em;
        }

        .hero p {
            margin: 0;
            color: var(--text-secondary);
            line-height: 1.65;
            max-width: 60ch;
        }

        form {
            display: grid;
            gap: 1.5rem;
        }

        .section-card {
            border: 1px solid var(--border-color);
            border-radius: 18px;
            padding: 1.4rem;
            background: var(--surface-strong);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.35);
            display: grid;
            gap: 1.2rem;
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .section-title span {
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .section-title .subtitle {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }

        .field-grid {
            display: grid;
            gap: 1.2rem;
        }

        .field-group {
            display: grid;
            gap: 0.45rem;
        }

        label {
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        input[type="text"],
        textarea {
            width: 100%;
            padding: 0.85rem 1rem;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.75);
            color: inherit;
            font: inherit;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        textarea {
            min-height: 7.5rem;
            resize: vertical;
        }

        input[type="text"]:focus,
        textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-soft);
            transform: translateY(-1px);
        }

        .messages-wrapper {
            display: grid;
            gap: 1rem;
        }

        .messages-card {
            border: 1px solid var(--border-color);
            background: var(--surface-strong);
            border-radius: 18px;
            padding: 1.2rem 1.4rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.4);
            display: grid;
            gap: 0.75rem;
        }

        .messages {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 0.35rem;
            position: relative;
        }

        .messages::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 12px;
            opacity: 0.6;
        }

        .messages li {
            padding: 0.55rem 0.85rem;
            border-radius: 12px;
            font-size: 0.95rem;
            line-height: 1.5;
            background: rgba(255, 255, 255, 0.65);
        }

        .messages.success::before {
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.18), transparent 65%);
        }

        .messages.success li {
            color: var(--success);
        }

        .messages.error::before {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), transparent 65%);
        }

        .messages.error li {
            color: var(--error);
        }

        fieldset {
            border: 0;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 1.1rem;
        }

        fieldset legend {
            font-size: 1.05rem;
            font-weight: 600;
        }

        .branch-list {
            display: grid;
            gap: 1.15rem;
        }

        .branch-card {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.9rem 1.25rem;
            align-items: start;
            padding: 1.15rem 1.35rem;
            border-radius: 18px;
            border: 1px solid var(--border-color);
            background: rgba(255, 255, 255, 0.7);
            position: relative;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        .branch-card::after {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--accent-soft), transparent 65%);
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .branch-card:hover,
        .branch-card:focus-within {
            border-color: var(--accent);
            box-shadow: 0 24px 45px rgba(99, 102, 241, 0.18);
            transform: translateY(-3px);
        }

        .branch-card:hover::after,
        .branch-card:focus-within::after {
            opacity: 1;
        }

        .branch-card input[type="radio"] {
            margin-top: 0.45rem;
            width: 22px;
            height: 22px;
            accent-color: var(--accent);
            appearance: none;
            border-radius: 50%;
            border: 2px solid var(--border-color);
            background: rgba(255, 255, 255, 0.75);
            display: grid;
            place-content: center;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .branch-card input[type="radio"]::after {
            content: "";
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: transparent;
            transition: background 0.2s ease;
        }

        .branch-card input[type="radio"]:checked {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-soft-strong);
        }

        .branch-card input[type="radio"]:checked::after {
            background: var(--accent);
        }

        .branch-name {
            font-weight: 600;
            font-size: 1.15rem;
        }

        .branch-meta {
            display: grid;
            gap: 0.4rem;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }

        .branch-meta div {
            display: flex;
            align-items: center;
            gap: 0.45rem;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            background: rgba(99, 102, 241, 0.12);
            color: var(--accent-strong);
            border-radius: 999px;
            padding: 0.25rem 0.65rem;
            font-size: 0.78rem;
            letter-spacing: 0.02em;
        }

        .branch-commit-message {
            margin-top: 0.75rem;
            font-size: 0.93rem;
            color: var(--text-secondary);
            white-space: pre-line;
            line-height: 1.45;
        }

        .form-hint {
            margin: 0;
            color: var(--text-secondary);
            font-size: 0.85rem;
        }

        button[type="submit"] {
            justify-self: start;
            border: none;
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: white;
            font-weight: 600;
            letter-spacing: 0.04em;
            border-radius: 999px;
            padding: 0.9rem 2.2rem;
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            position: relative;
            overflow: hidden;
        }

        button[type="submit"]::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, rgba(255, 255, 255, 0.25), transparent 55%);
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 35px rgba(79, 70, 229, 0.35);
        }

        button[type="submit"]:hover::after {
            opacity: 1;
        }

        button[type="submit"]:focus-visible {
            outline: none;
            box-shadow: 0 0 0 5px rgba(99, 102, 241, 0.35);
        }

        .checkbox-row {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .checkbox-row input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
        }

        .checkbox-row span {
            line-height: 1.45;
        }

        code {
            font-family: "Fira Code", "SFMono-Regular", ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 0.85rem;
            background: rgba(15, 23, 42, 0.08);
            padding: 0.1rem 0.4rem;
            border-radius: 5px;
        }

        @media (max-width: 840px) {
            .layout {
                width: calc(100% - 2.5rem);
            }

            .section-card {
                padding: 1.2rem;
            }
        }

        @media (max-width: 640px) {
            body {
                align-items: stretch;
            }

            .layout {
                width: calc(100% - 2rem);
                margin: 2.5rem auto 3.5rem;
            }

            .branch-card {
                grid-template-columns: 1fr;
            }

            .branch-card input[type="radio"] {
                justify-self: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="backdrop" aria-hidden="true">
        <span class="orb-a"></span>
        <span class="orb-b"></span>
        <span class="orb-c"></span>
    </div>
    <main class="layout">
        <div class="app-card">
            <header class="hero">
                <span class="hero-label">Update Assistant</span>
                <h1>GitHub Branch Update Workflow</h1>
                <p>Verwalten Sie Aktualisierungen Ihres Projekts mit wenigen Klicks: Branch auswählen, optionales Backup erstellen und zielgenau deployen.</p>
            </header>

            <?php if ($messages || $errors): ?>
                <div class="messages-wrapper">
                    <div class="messages-card">
                        <?php if ($messages): ?>
                            <ul class="messages success">
                                <?php foreach ($messages as $message): ?>
                                    <li><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>

                        <?php if ($errors): ?>
                            <ul class="messages error">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post">
        <div class="section-card">
            <div class="section-title">
                <span>Repository-Informationen</span>
                <span class="subtitle">Owner und Projekt angeben</span>
            </div>
            <div class="field-grid">
        <input type="hidden" name="state" value="<?= $state === FORM_STATE_SELECT_BRANCH ? FORM_STATE_DOWNLOAD : FORM_STATE_SELECT_BRANCH ?>">
        <div class="field-group">
            <label for="owner">GitHub Owner</label>
            <input type="text" name="owner" id="owner" value="<?= htmlspecialchars($owner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
        </div>

        <div class="field-group">
            <label for="repository">Repository</label>
            <input type="text" name="repository" id="repository" value="<?= htmlspecialchars($repository, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
        </div>
            </div>
        </div>

        <?php if ($state === FORM_STATE_SELECT_BRANCH && $branches): ?>
            <div class="section-card">
                <div class="section-title">
                    <span>Branch auswählen</span>
                    <span class="subtitle">Commit-Details &amp; Zeitleiste im Blick</span>
                </div>
            <fieldset>
                <div class="branch-list">
                    <?php foreach ($branches as $index => $branchInfo): ?>
                        <?php $name = $branchInfo['name']; ?>
                        <label class="branch-card">
                            <input type="radio" name="branch" value="<?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $name === $branch ? 'checked' : '' ?> <?= ($branch === '' && $index === 0) ? 'required' : '' ?>>
                            <div>
                                <div class="branch-name"><?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <div class="branch-meta">
                                    <?php
                                    $createdIso = $branchInfo['commit_authored_date'] ?? null;
                                    $createdDisplay = formatIsoDate($createdIso);
                                    $updatedIso = $branchInfo['commit_committed_date'] ?? null;
                                    $updatedDisplay = formatIsoDate($updatedIso);
                                    $commitSha = $branchInfo['commit_sha'] ?? null;
                                    ?>
                                    <?php if ($createdDisplay !== null): ?>
                                        <div>
                                            <span class="meta-pill">Erstellt</span>
                                            <time datetime="<?= htmlspecialchars((string) $createdIso, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($createdDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></time>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($updatedDisplay !== null && $updatedDisplay !== $createdDisplay): ?>
                                        <div>
                                            <span class="meta-pill">Zuletzt aktualisiert</span>
                                            <time datetime="<?= htmlspecialchars((string) $updatedIso, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($updatedDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></time>
                                        </div>
                                    <?php elseif ($updatedDisplay !== null): ?>
                                        <div>
                                            <span class="meta-pill">Stand</span>
                                            <time datetime="<?= htmlspecialchars((string) $updatedIso, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($updatedDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></time>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($commitSha): ?>
                                        <div>
                                            <span class="meta-pill">Commit</span>
                                            <code><?= htmlspecialchars(substr($commitSha, 0, 8), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($branchInfo['commit_message'])): ?>
                                    <div class="branch-commit-message"><?= htmlspecialchars($branchInfo['commit_message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <?php endif; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <div class="field-grid">
            <div class="field-group">
                <label for="target_directory">Zielverzeichnis</label>
                <input type="text" name="target_directory" id="target_directory" value="<?= htmlspecialchars($targetDirectory, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            </div>

            <div class="field-group">
                <label for="excludes">Pfade vom Update ausschließen (ein Eintrag pro Zeile)</label>
                <textarea name="excludes" id="excludes" rows="4" placeholder="z. B. config.php oder storage/"><?= htmlspecialchars($excludesInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                <p class="form-hint">Pfadangaben beziehen sich auf die Projektwurzel. Unterordner bitte mit abschließendem Slash angeben, z.&nbsp;B. <code>storage/</code>.</p>
            </div>

            <label class="checkbox-row">
                <input type="checkbox" name="create_backup" <?= $createBackup ? 'checked' : '' ?>>
                <span>Vor dem Update ein ZIP-Backup anlegen</span>
            </label>

            <p class="form-hint">Workflow-Intro: Beim Absenden wird der ausgewählte Branch heruntergeladen und in das Zielverzeichnis extrahiert. Dabei werden vorhandene Dateien überschrieben.</p>
            </div>
            </div>
        <?php else: ?>
            <div class="section-card">
                <div class="section-title">
                    <span>Workflow vorbereiten</span>
                    <span class="subtitle">Branches abrufen &amp; prüfen</span>
                </div>
            <p class="form-hint">Nach dem Absenden werden die verfügbaren Branches des Repositories geladen.</p>
            </div>
        <?php endif; ?>

        <?php if ($state === FORM_STATE_SELECT_BRANCH && $branches): ?>
            <button type="submit">Branch herunterladen und aktualisieren</button>
        <?php else: ?>
            <button type="submit">Branches laden</button>
        <?php endif; ?>
            </form>
        </div>
    </main>
</body>
</html>
