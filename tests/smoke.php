<?php
declare(strict_types=1);

$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start();
require dirname(__DIR__) . '/index.php';
ob_end_clean();

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$path = tempnam(sys_get_temp_dir(), 'team-overlap-');
if ($path === false) {
    throw new RuntimeException('Could not create a temporary test CSV.');
}

try {
    $rows = [
        'EmpID,ProjectID,DateFrom,DateTo',
        '143,10,2020-01-01,2020-01-11',
        '143,10,2020-01-01,2020-01-11',
        '218,10,2020-01-05,2020-01-15',
        '143,12,2020-02-01,2020-02-05',
        '218,12,2020-02-03,2020-02-06',
    ];
    if (file_put_contents($path, implode(PHP_EOL, $rows)) === false) {
        throw new RuntimeException('Could not write the temporary test CSV.');
    }

    $analysis = analyzeCsv($path, 'AUTO');
    check($analysis['topDays'] === 8, 'project overlap days should add to 8');
    check(count($analysis['winners']) === 1, 'there should be one leading pair');
    check($analysis['winners'][0]['employee1'] === '143', 'first employee ID should be preserved');
    check($analysis['winners'][0]['employee2'] === '218', 'second employee ID should be preserved');
    check($analysis['winners'][0]['projects']['10'] === 6, 'duplicate assignment days should count once');
    check($analysis['winners'][0]['projects']['12'] === 2, 'second project overlap should be counted');
    check(parseWorkDate('03/04/2020', 'DMY')?->format('Y-m-d') === '2020-04-03', 'DMY selection should parse slash dates');
    check(parseWorkDate('03/04/2020', 'MDY')?->format('Y-m-d') === '2020-03-04', 'MDY selection should parse slash dates');
    check(parseWorkDate('2020.04.03', 'AUTO')?->format('Y-m-d') === '2020-04-03', 'auto mode should parse year-first dates');
    check(parseWorkDate('March 4, 2020', 'AUTO')?->format('Y-m-d') === '2020-03-04', 'auto mode should parse month-name dates');

    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    $openRows = [
        'EmpID,ProjectID,DateFrom,DateTo',
        '143,20,' . $today->modify('-5 days')->format('Y-m-d') . ',NULL',
        '218,20,' . $today->modify('-5 days')->format('Y-m-d') . ',' . $today->format('Y-m-d'),
    ];
    file_put_contents($path, implode(PHP_EOL, $openRows));
    $openAnalysis = analyzeCsv($path, 'AUTO');
    check($openAnalysis['topDays'] === 5, 'NULL end dates should use today');
    check($openAnalysis['winners'][0]['projects']['20'] === 5, 'open-ended project overlap should be reported');

    echo "PASS: pair totals, project breakdown, duplicate rows, and date formats\n";
} finally {
    unlink($path);
}