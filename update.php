<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

const FORM_STATE_SELECT_BRANCH = 'select_branch';
const FORM_STATE_DOWNLOAD = 'download';
const CONFIG_FILE = __DIR__ . '/update.config.php';

$messages = [];
$errors = [];

$config = loadConfig();

$owner = trim($_POST['owner'] ?? ($config['owner'] ?? ''));
$repository = trim($_POST['repository'] ?? ($config['repository'] ?? ''));
$branch = trim($_POST['branch'] ?? '');
$state = $_POST['state'] ?? null;
$createBackup = isset($_POST['create_backup']);
$targetDirectory = rtrim($_POST['target_directory'] ?? __DIR__, '/');
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
        persistConfig($owner, $repository, $excludes);
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

        persistConfig($owner, $repository, $excludes);

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
    $url = sprintf('https://api.github.com/repos/%s/%s/branches', rawurlencode($owner), rawurlencode($repo));
    $response = githubRequest($url);

    $branches = [];
    foreach ($response as $branch) {
        if (isset($branch['name'])) {
            $branches[] = $branch['name'];
        }
    }

    sort($branches);

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

    $decoded = json_decode($result, true, flags: JSON_THROW_ON_ERROR);

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
    if (!is_readable(CONFIG_FILE)) {
        return [];
    }

    $data = include CONFIG_FILE;

    return is_array($data) ? $data : [];
}

function persistConfig(string $owner, string $repository, array $excludes): void
{
    if ($owner === '' || $repository === '') {
        return;
    }

    $config = [
        'owner' => $owner,
        'repository' => $repository,
        'excludes' => array_values($excludes),
    ];

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
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Repository Update</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background-color: #f7f7f7; }
        form { background: #fff; padding: 1.5rem; border-radius: 8px; max-width: 600px; }
        label { display: block; margin-bottom: 0.5rem; font-weight: bold; }
        input[type="text"], select { width: 100%; padding: 0.5rem; margin-bottom: 1rem; }
        .messages { margin-bottom: 1rem; }
        .messages li { margin-bottom: 0.25rem; }
        .error { color: #b30000; }
        .success { color: #005c00; }
    </style>
</head>
<body>
    <h1>GitHub Branch Update Workflow</h1>

    <p>Wählen Sie GitHub Owner, Repository und Branch, um die Dateien in Ihrem Zielverzeichnis zu aktualisieren. Optional kann vor dem Update ein ZIP-Backup erstellt werden.</p>

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

    <form method="post">
        <input type="hidden" name="state" value="<?= $state === FORM_STATE_SELECT_BRANCH ? FORM_STATE_DOWNLOAD : FORM_STATE_SELECT_BRANCH ?>">
        <label for="owner">GitHub Owner</label>
        <input type="text" name="owner" id="owner" value="<?= htmlspecialchars($owner, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>

        <label for="repository">Repository</label>
        <input type="text" name="repository" id="repository" value="<?= htmlspecialchars($repository, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>

        <?php if ($state === FORM_STATE_SELECT_BRANCH && $branches): ?>
            <label for="branch">Branch</label>
            <select name="branch" id="branch" required>
                <option value="">Bitte wählen</option>
                <?php foreach ($branches as $name): ?>
                    <option value="<?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $name === $branch ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>

            <label for="target_directory">Zielverzeichnis</label>
            <input type="text" name="target_directory" id="target_directory" value="<?= htmlspecialchars($targetDirectory, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>

            <label for="excludes">Pfade vom Update ausschließen (ein Eintrag pro Zeile)</label>
            <textarea name="excludes" id="excludes" rows="4" placeholder="z. B. config.php oder storage/"><?= htmlspecialchars($excludesInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            <p><small>Pfadangaben beziehen sich auf die Projektwurzel. Unterordner bitte mit abschließendem Slash angeben, z.&nbsp;B. <code>storage/</code>.</small></p>

            <label>
                <input type="checkbox" name="create_backup" <?= $createBackup ? 'checked' : '' ?>> Vor dem Update ein ZIP-Backup anlegen
            </label>

            <p><strong>Workflow-Intro:</strong> Beim Absenden wird der ausgewählte Branch heruntergeladen und in das Zielverzeichnis extrahiert. Dabei werden vorhandene Dateien überschrieben.</p>
        <?php else: ?>
            <p>Nach dem Absenden werden die verfügbaren Branches des Repositories geladen.</p>
        <?php endif; ?>

        <?php if ($state === FORM_STATE_SELECT_BRANCH && $branches): ?>
            <button type="submit">Branch herunterladen und aktualisieren</button>
        <?php else: ?>
            <button type="submit">Branches laden</button>
        <?php endif; ?>
    </form>
</body>
</html>
