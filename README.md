# Team Overlap

A PHP application that finds the employee pair with the most shared calendar days on common projects. Upload a CSV in the browser to see the leading pair and its shared days for each project.

## Run

Requires PHP 8.1 or later. From this folder, start PHP's built-in web server:

```powershell
& 'C:\UniServerZ\core\php81\php.exe' -S 127.0.0.1:8000 -t .
```

Open <http://127.0.0.1:8000> and upload `sample.csv`. With UniServer's Apache, the folder can also be opened at `http://localhost/sirma/`.

## CSV format

The first row must contain `EmpID,ProjectID,DateFrom,DateTo`. Dates support year-first forms such as `2024-03-15`, day-first and month-first numeric forms with `/`, `-`, or `.`, and common month-name forms such as `15 Mar 2024` or `March 15, 2024`. Select DMY or MDY in the form when slash dates are ambiguous. Auto detection treats ambiguous numeric dates as day-first.

A blank or `NULL` `DateTo` is treated as today. Invalid rows are reported instead of producing a partial result. Overlap is measured as elapsed calendar days between dates, with the end date exclusive; duplicate or overlapping assignment rows do not double-count a project's days. Every pair tied for the most days is displayed.

## Check

Run the focused smoke test with:

```powershell
& 'C:\UniServerZ\core\php81\php.exe' .\tests\smoke.php
```

## GitHub repository

The requested repository name format is `{FirstName}-{LastName}-employees`. Replace the name placeholders with the submitter's name when creating the GitHub repository.