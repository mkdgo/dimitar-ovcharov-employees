<?php
declare(strict_types=1);

const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parseWorkDate(string $value, string $dateMode): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $formats = [];
    if (preg_match('~^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$~', $value)) {
        $formats = ['!Y-m-d', '!Y/m/d', '!Y.m.d'];
    } elseif (preg_match('~^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})$~', $value, $parts)) {
        $separator = $value[strlen($parts[1])];
        $dayFirst = $dateMode === 'DMY' || ($dateMode === 'AUTO' && ((int) $parts[1] > 12 || ((int) $parts[2] <= 12 && (int) $parts[1] <= 12)));
        if ($dateMode === 'MDY') {
            $dayFirst = false;
        }
        $first = $dayFirst ? 'd' : 'm';
        $second = $dayFirst ? 'm' : 'd';
        $year = strlen($parts[3]) === 2 ? 'y' : 'Y';
        $dateSeparator = $separator === '.' ? '.' : $separator;
        $formats = ['!' . $first . $dateSeparator . $second . $dateSeparator . $year];
    } elseif (in_array($dateMode, ['AUTO', 'DMY'], true)) {
        $formats = ['!j M Y', '!j F Y', '!j-M-Y', '!j-F-Y'];
    }

    if ($dateMode === 'MDY') {
        $formats = array_merge($formats, ['!M j, Y', '!F j, Y', '!n/j/Y', '!n-j-Y', '!n.j.Y']);
    } elseif ($dateMode === 'YMD') {
        $formats = ['!Y-m-d', '!Y/m/d', '!Y.m.d'];
    }

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
        $issues = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($issues === false || ($issues['warning_count'] === 0 && $issues['error_count'] === 0))) {
            return $date->setTime(0, 0);
        }
    }

    if ($dateMode === 'AUTO') {
        foreach (['!M j, Y', '!F j, Y', '!j M Y', '!j F Y', '!j-M-Y', '!j-F-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));
            $issues = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($issues === false || ($issues['warning_count'] === 0 && $issues['error_count'] === 0))) {
                return $date->setTime(0, 0);
            }
        }
    }

    return null;
}

function intervalDays(DateTimeImmutable $start, DateTimeImmutable $end): int
{
    return (int) $start->diff($end)->format('%r%a');
}

function mergeWorkIntervals(array $intervals): array
{
    usort($intervals, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

    $mergedIntervals = [];
    foreach ($intervals as $interval) {
        $lastIndex = count($mergedIntervals) - 1;
        if ($lastIndex < 0 || $interval[0] > $mergedIntervals[$lastIndex][1]) {
            $mergedIntervals[] = $interval;
        } elseif ($interval[1] > $mergedIntervals[$lastIndex][1]) {
            $mergedIntervals[$lastIndex][1] = $interval[1];
        }
    }

    return $mergedIntervals;
}

function sharedWorkDays(array $firstIntervals, array $secondIntervals): int
{
    $firstIndex = 0;
    $secondIndex = 0;
    $sharedDays = 0;

    while (isset($firstIntervals[$firstIndex], $secondIntervals[$secondIndex])) {
        [$firstStart, $firstEnd] = $firstIntervals[$firstIndex];
        [$secondStart, $secondEnd] = $secondIntervals[$secondIndex];
        $overlapStart = $firstStart > $secondStart ? $firstStart : $secondStart;
        $overlapEnd = $firstEnd < $secondEnd ? $firstEnd : $secondEnd;

        if ($overlapStart < $overlapEnd) {
            $sharedDays += intervalDays($overlapStart, $overlapEnd);
        }

        if ($firstEnd <= $secondEnd) {
            $firstIndex++;
        }
        if ($secondEnd <= $firstEnd) {
            $secondIndex++;
        }
    }

    return $sharedDays;
}

function analyzeCsv(string $path, string $dateMode): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The uploaded file could not be read.');
    }

    try {
        $headers = fgetcsv($handle, null, ',', '"', '\\');
        if ($headers === false) {
            throw new RuntimeException('The CSV file is empty.');
        }
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        $headerMap = [];
        foreach ($headers as $index => $header) {
            $headerMap[strtolower(trim((string) $header))] = $index;
        }
        foreach (['empid', 'projectid', 'datefrom', 'dateto'] as $required) {
            if (!array_key_exists($required, $headerMap)) {
                throw new RuntimeException('CSV headers must include EmpID, ProjectID, DateFrom, and DateTo.');
            }
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $projects = [];
        $errors = [];
        $rowNumber = 1;
        $recordCount = 0;

        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $rowNumber++;
            if ($row === [null] || count(array_filter($row, static fn ($field): bool => trim((string) $field) !== '')) === 0) {
                continue;
            }

            $requiredIndexes = array_values($headerMap);
            if (count($row) <= max($requiredIndexes)) {
                $errors[] = "Row {$rowNumber}: not enough CSV columns.";
                continue;
            }

            $employeeId = trim((string) $row[$headerMap['empid']]);
            $projectId = trim((string) $row[$headerMap['projectid']]);
            $fromValue = trim((string) $row[$headerMap['datefrom']]);
            $toValue = trim((string) $row[$headerMap['dateto']]);
            if ($employeeId === '' || $projectId === '' || $fromValue === '') {
                $errors[] = "Row {$rowNumber}: employee, project, and start date are required.";
                continue;
            }

            $start = parseWorkDate($fromValue, $dateMode);
            $end = in_array(strtoupper($toValue), ['', 'NULL'], true) ? $today : parseWorkDate($toValue, $dateMode);
            if ($start === null || $end === null) {
                $errors[] = "Row {$rowNumber}: a date could not be parsed. Check the date format selection.";
                continue;
            }
            if ($end < $start) {
                $errors[] = "Row {$rowNumber}: DateTo is earlier than DateFrom.";
                continue;
            }

            $projects[$projectId][$employeeId][] = [$start, $end];
            $recordCount++;
        }

        if ($errors !== []) {
            throw new RuntimeException(implode("\n", array_slice($errors, 0, 8)) . (count($errors) > 8 ? "\nAdditional row errors were omitted." : ''));
        }
        if ($recordCount === 0) {
            throw new RuntimeException('No valid employee project records were found.');
        }

        $pairTotals = [];
        foreach ($projects as $projectId => $employees) {
            foreach ($employees as $employeeId => $intervals) {
                $employees[$employeeId] = mergeWorkIntervals($intervals);
            }

            $employeeIds = array_keys($employees);
            sort($employeeIds, SORT_STRING);
            for ($firstIndex = 0, $employeeCount = count($employeeIds); $firstIndex < $employeeCount; $firstIndex++) {
                for ($secondIndex = $firstIndex + 1; $secondIndex < $employeeCount; $secondIndex++) {
                    $firstId = $employeeIds[$firstIndex];
                    $secondId = $employeeIds[$secondIndex];
                    $projectDays = sharedWorkDays($employees[$firstId], $employees[$secondId]);
                    if ($projectDays === 0) {
                        continue;
                    }

                    $pairKey = json_encode([$firstId, $secondId], JSON_THROW_ON_ERROR);
                    $pairTotals[$pairKey]['employee1'] = (string) $firstId;
                    $pairTotals[$pairKey]['employee2'] = (string) $secondId;
                    $pairTotals[$pairKey]['projects'][$projectId] = $projectDays;
                    $pairTotals[$pairKey]['total'] = ($pairTotals[$pairKey]['total'] ?? 0) + $projectDays;
                }
            }
        }

        if ($pairTotals === []) {
            return ['recordCount' => $recordCount, 'winners' => [], 'topDays' => 0];
        }

        $topDays = max(array_column($pairTotals, 'total'));
        $winners = array_values(array_filter($pairTotals, static fn (array $pair): bool => $pair['total'] === $topDays));
        usort($winners, static fn (array $left, array $right): int => [$left['employee1'], $left['employee2']] <=> [$right['employee1'], $right['employee2']]);

        return ['recordCount' => $recordCount, 'winners' => $winners, 'topDays' => $topDays];
    } finally {
        fclose($handle);
    }
}

$dateMode = strtoupper((string) ($_POST['date_mode'] ?? 'AUTO'));
if (!in_array($dateMode, ['AUTO', 'DMY', 'MDY', 'YMD'], true)) {
    $dateMode = 'AUTO';
}
$errorMessage = '';
$analysis = null;
$uploadedName = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $upload = $_FILES['csv_file'] ?? null;
    $requestSize = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if (!is_array($upload) && $requestSize > MAX_UPLOAD_BYTES + 65536) {
        $errorMessage = 'The upload exceeds the 2 MB limit or the PHP post_max_size setting.';
    } elseif (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errorMessage = 'Choose a CSV file to analyze.';
    } elseif (($upload['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_FORM_SIZE) {
        $errorMessage = 'The CSV file exceeds the 2 MB upload limit.';
    } elseif (($upload['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_INI_SIZE) {
        $errorMessage = 'PHP rejected the upload. Set upload_max_filesize to at least 2M and post_max_size above 2M.';
    } elseif (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errorMessage = 'The file upload failed. Please try again.';
    } elseif ((int) ($upload['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        $errorMessage = 'The CSV file exceeds the 2 MB upload limit.';
    } else {
        $uploadedName = basename((string) ($upload['name'] ?? 'upload.csv'));
        try {
            $analysis = analyzeCsv((string) $upload['tmp_name'], $dateMode);
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f4f3ed">
    <title>Team Overlap | Employee Pair Finder</title>
    <style>
        :root {
            color-scheme: light;
            --ink: #202923;
            --muted: #68736b;
            --paper: #f4f3ed;
            --surface: #fffefa;
            --line: #d9ded5;
            --green: #286a50;
            --green-dark: #174a38;
            --lime: #d6ef83;
            --red: #963e34;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            color: var(--ink);
            background-color: var(--paper);
            background-image: radial-gradient(#c9d3c5 0.65px, transparent 0.65px);
            background-size: 18px 18px;
            font: 15px/1.5 "Segoe UI", sans-serif;
        }
        .shell { width: min(1050px, calc(100% - 40px)); margin: 0 auto; padding: 52px 0 72px; }
        .masthead { display: flex; justify-content: space-between; align-items: center; margin-bottom: 52px; }
        .brand { display: flex; align-items: center; gap: 11px; font-weight: 700; letter-spacing: .02em; }
        .brand-mark { display: grid; width: 34px; height: 34px; place-items: center; border-radius: 9px; color: var(--surface); background: var(--green-dark); font-size: 18px; }
        .edition { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .09em; }
        .intro { margin-bottom: 34px; animation: rise .45s ease-out both; }
        .eyebrow { margin: 0 0 10px; color: var(--green); font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; }
        h1, h2, p { margin-top: 0; }
        h1 { margin-bottom: 13px; font: 500 clamp(37px, 5vw, 58px)/1.02 Georgia, serif; letter-spacing: 0; }
        .intro-copy { max-width: 590px; margin-bottom: 0; color: var(--muted); font-size: 16px; }
        .upload-panel { padding: 25px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); box-shadow: 0 12px 32px #33442a0a; animation: rise .5s .08s ease-out both; }
        .upload-form { display: grid; grid-template-columns: minmax(220px, 1fr) 180px auto; align-items: end; gap: 16px; }
        label { display: block; margin-bottom: 7px; color: var(--muted); font-size: 12px; font-weight: 700; }
        input[type=file], select { width: 100%; height: 44px; padding: 9px 11px; border: 1px solid var(--line); border-radius: 5px; color: var(--ink); background: #fff; font: inherit; }
        input[type=file] { padding: 7px; font-size: 13px; }
        input:focus-visible, select:focus-visible, button:focus-visible { outline: 3px solid #9dc873; outline-offset: 2px; }
        button { height: 44px; padding: 0 20px; border: 0; border-radius: 5px; color: white; background: var(--green-dark); font: 700 14px "Segoe UI", sans-serif; cursor: pointer; }
        button:hover { background: var(--green); }
        .upload-note { margin: 15px 0 0; color: var(--muted); font-size: 12px; }
        .alert { margin-top: 20px; padding: 14px 16px; border-left: 3px solid var(--red); border-radius: 3px; color: #692e28; background: #fff1ed; white-space: pre-line; }
        .results { margin-top: 38px; animation: rise .4s ease-out both; }
        .section-head { display: flex; justify-content: space-between; align-items: end; gap: 16px; margin-bottom: 16px; }
        .section-head h2 { margin-bottom: 2px; font: 500 27px/1.15 Georgia, serif; }
        .section-head p, .file-label { margin-bottom: 0; color: var(--muted); font-size: 13px; }
        .result-block { margin-top: 23px; border: 1px solid var(--line); border-radius: 7px; overflow: hidden; background: var(--surface); }
        .winner-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 20px 22px; border-bottom: 1px solid var(--line); background: #f9faf5; }
        .winner-title { display: flex; align-items: center; gap: 12px; }
        .winner-icon { display: grid; flex: 0 0 38px; height: 38px; place-items: center; border-radius: 50%; color: var(--green-dark); background: var(--lime); font-weight: 800; }
        .winner-title h3 { margin: 0; font: 500 20px Georgia, serif; }
        .winner-title p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
        .total { text-align: right; white-space: nowrap; }
        .total strong { display: block; color: var(--green-dark); font: 600 25px Georgia, serif; }
        .total span { color: var(--muted); font-size: 11px; text-transform: uppercase; letter-spacing: .08em; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 13px 18px; border-bottom: 1px solid #e8ebe4; }
        th { color: var(--muted); background: #fffefa; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; }
        td { font-size: 14px; }
        td:last-child, th:last-child { text-align: right; }
        tbody tr:last-child td { border-bottom: 0; }
        .days { font-weight: 700; font-variant-numeric: tabular-nums; }
        .empty-state { padding: 28px 22px; border: 1px dashed #b9c4b8; border-radius: 7px; color: var(--muted); background: #fffefa; }
        .method { margin: 18px 0 0; color: var(--muted); font-size: 12px; }
        @keyframes rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        @media (max-width: 700px) {
            .shell { width: min(100% - 28px, 580px); padding-top: 28px; }
            .masthead { margin-bottom: 38px; }
            .edition { max-width: 120px; text-align: right; }
            .upload-panel { padding: 18px; }
            .upload-form { grid-template-columns: 1fr; gap: 13px; }
            .upload-form button { width: 100%; }
            .section-head { align-items: start; flex-direction: column; }
            .winner-head { align-items: start; padding: 16px; }
            .total strong { font-size: 21px; }
            th, td { padding: 11px 12px; white-space: nowrap; }
        }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; } }
    </style>
</head>
<body>
<main class="shell">
    <header class="masthead">
        <div class="brand"><span class="brand-mark" aria-hidden="true">↔</span><span>Team Overlap</span></div>
    </header>

    <section class="intro" aria-labelledby="page-title">
        <p class="eyebrow">Shared project time</p>
        <h1 id="page-title">Who worked together the longest?</h1>
        <p class="intro-copy">Upload an employee project CSV to find the pair with the most overlapping days, with a project-by-project breakdown.</p>
    </section>

    <section class="upload-panel" aria-label="Upload employee project data">
        <form class="upload-form" method="post" enctype="multipart/form-data">
            <div>
                <label for="csv-file">Employee project CSV</label>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_BYTES ?>">
                <input id="csv-file" name="csv_file" type="file" accept=".csv,text/csv" required>
            </div>
            <div>
                <label for="date-mode">Date format</label>
                <select id="date-mode" name="date_mode">
                    <option value="AUTO" <?= $dateMode === 'AUTO' ? 'selected' : '' ?>>Auto detect</option>
                    <option value="DMY" <?= $dateMode === 'DMY' ? 'selected' : '' ?>>Day / month / year</option>
                    <option value="MDY" <?= $dateMode === 'MDY' ? 'selected' : '' ?>>Month / day / year</option>
                    <option value="YMD" <?= $dateMode === 'YMD' ? 'selected' : '' ?>>Year / month / day</option>
                </select>
            </div>
            <button type="submit">Analyze file <span aria-hidden="true">→</span></button>
        </form>
        <p class="upload-note">Maximum file size: 2 MB. CSV columns: EmpID, ProjectID, DateFrom, DateTo. A blank or NULL end date means today.</p>
        <?php if ($errorMessage !== ''): ?>
            <div class="alert" role="alert"><?= escape($errorMessage) ?></div>
        <?php endif; ?>
    </section>

    <?php if ($analysis !== null): ?>
        <section class="results" aria-live="polite">
            <div class="section-head">
                <div>
                    <h2>Longest collaboration</h2>
                    <p><?= number_format($analysis['recordCount']) ?> employee records analyzed</p>
                </div>
                <p class="file-label"><?= escape($uploadedName) ?></p>
            </div>

            <?php if ($analysis['winners'] === []): ?>
                <div class="empty-state">No employee pair has overlapping time on the same project in this file.</div>
            <?php else: ?>
                <?php foreach ($analysis['winners'] as $winner): ?>
                    <?php $winnerProjects = $winner['projects']; arsort($winnerProjects, SORT_NUMERIC); ?>
                    <article class="result-block">
                        <div class="winner-head">
                            <div class="winner-title">
                                <span class="winner-icon" aria-hidden="true">#</span>
                                <div>
                                    <h3>Employee <?= escape($winner['employee1']) ?> <span aria-hidden="true">+</span> <?= escape($winner['employee2']) ?></h3>
                                    <p><?= count($winnerProjects) ?> shared <?= count($winnerProjects) === 1 ? 'project' : 'projects' ?></p>
                                </div>
                            </div>
                            <div class="total"><strong><?= number_format($winner['total']) ?></strong><span>overlap days</span></div>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Employee ID #1</th><th>Employee ID #2</th><th>Project ID</th><th>Days worked</th></tr></thead>
                                <tbody>
                                <?php foreach ($winnerProjects as $projectId => $days): ?>
                                    <tr>
                                        <td><?= escape($winner['employee1']) ?></td>
                                        <td><?= escape($winner['employee2']) ?></td>
                                        <td><?= escape((string) $projectId) ?></td>
                                        <td class="days"><?= number_format($days) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </article>
                <?php endforeach; ?>
                <p class="method">Overlap is measured as elapsed calendar days between dates (end date exclusive). Tied pairs are all shown. Each project’s overlapping intervals are counted once.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>