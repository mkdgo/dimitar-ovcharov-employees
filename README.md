# Team Overlap

A PHP application that finds the pair of employees with the most shared days on common projects. Select a CSV file in the browser to see the leading pair and their shared days per project.

## Requirements and Setup

PHP 8.1 or later is required. In PowerShell, from the project root, start the built-in web server:

```powershell
& 'C:\UniServerZ\core\php81\php.exe' -S 127.0.0.1:8000 -t .
```

Open <http://127.0.0.1:8000>. With UniServer and Apache, the application is available at <http://localhost/sirma/>.

The maximum CSV file size is 2 MiB (2,097,152 bytes). PHP must allow at least `upload_max_filesize=2M` and a `post_max_size` greater than 2M.

## CSV Format

The first row must contain the columns `EmpID,ProjectID,DateFrom,DateTo`. Example CSV files:

- `sample.csv` — 100 records.
- `sample_mixed_dates.csv` — 20 records with different date formats.
- `sample_invalid_data.csv` — examples of invalid data.

Supported formats include ISO dates such as `2024-03-15`, numeric dates with `/`, `-`, and `.` separators, and common month-name forms such as `15 Mar 2024` and `March 15, 2024`. In Auto mode, ambiguous numeric dates are interpreted as day/month/year. Select DMY or MDY in the form when needed.

A blank `DateTo` or the value `NULL` means today. If a row is invalid, the application reports an error and does not calculate partial results. Duration is the number of elapsed calendar days between the start and end dates; the end date is exclusive. Duplicate or overlapping records for the same project do not double-count days. All pairs tied for the highest total are shown.

## Application Functions

Functions defined in `index.php`:

| Function | Purpose |
| --- | --- |
| `escape($value)` | Escapes text with `htmlspecialchars` before rendering it in HTML, preventing input from being interpreted as HTML or JavaScript. |
| `parseWorkDate($value, $dateMode)` | Parses supported date formats for the selected mode and returns a `DateTimeImmutable`. Returns `null` if the date is empty or invalid. |
| `intervalDays($start, $end)` | Calculates the elapsed calendar days between two `DateTimeImmutable` values. |
| `analyzeCsv($path, $dateMode)` | Opens and validates the CSV, groups intervals by project and employee, calculates overlaps, and returns the totals. Throws `RuntimeException` if records are invalid. |

## PHP Functions Used

| Function or method | How it is used |
| --- | --- |
| `fopen()`, `fgetcsv()`, `fclose()` | Open, read rows from, and close the uploaded CSV. `fgetcsv()` also handles quoted values. |
| `trim()`, `strtolower()` | Remove surrounding whitespace and normalize CSV header names. |
| `preg_match()` | Identifies numeric date patterns. |
| `DateTimeImmutable::createFromFormat()` | Parses a date using a specific format. |
| `DateTimeImmutable::getLastErrors()` | Checks whether date parsing produced errors or warnings. |
| `DateTimeImmutable::diff()`, `format()`, `setTime()` | Calculate date differences and normalize the time to midnight. |
| `array_key_exists()` | Checks whether the required CSV columns are present. |
| `array_filter()`, `array_values()`, `array_keys()`, `array_column()` | Filter, reindex, and extract data from record and result arrays. |
| `array_merge()`, `array_slice()` | Combine date parsing formats and limit the number of displayed errors. |
| `sort()`, `usort()`, `arsort()` | Sort employee IDs, pairs, overlap intervals, and projects. |
| `count()`, `max()` | Count records and find the highest total number of days. |
| `json_encode()` | Creates a key for each unique employee pair. |
| `basename()` | Gets the uploaded file name without its directory path. |
| `number_format()` | Formats record and day counts for display. |
| `htmlspecialchars()` | Escapes dynamic values rendered in HTML. |

## Tests

Run the focused smoke test with:

```powershell
& 'C:\UniServerZ\core\php81\php.exe' .\tests\smoke.php
```

## GitHub

The source code is available at [mkdgo/dimitar-ovcharov-employees](https://github.com/mkdgo/dimitar-ovcharov-employees).