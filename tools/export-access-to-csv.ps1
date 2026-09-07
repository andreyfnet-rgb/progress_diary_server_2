<#
.SYNOPSIS
  One-time export of every table in the production DB_PD2.mdb to CSV, for
  the initial MySQL data migration (see migrations/schema.sql for the
  target table list/columns -- kept in the same order here).

.DESCRIPTION
  Opens the source .mdb strictly read-only (Mode=Read) -- this script
  never writes to the production Access file. For each table it writes
  one CSV file (UTF-8, no BOM) using a format the companion
  tools/import-csv.php script expects:
    - Header row: exact column names (used to build the INSERT column
      list on import, so column order differences don't matter).
    - Every field double-quoted, embedded quotes doubled (RFC 4180).
    - NULL is written as a bare, UNQUOTED `\N` token (not `""`, which
      means "empty string") -- chosen to unambiguously distinguish NULL
      from an empty string for nullable text columns, and because several
      of this app's views filter on "IS NULL" (e.g. purpose.clpp_dateoff
      meaning "still open"), so the distinction has to survive the export.
    - DATETIME columns as "yyyy-MM-dd HH:mm:ss".
    - Boolean columns as "0"/"1".

.EXAMPLE
  .\export-access-to-csv.ps1 -MdbPath "C:\...\DB_PD2.mdb" -OutDir "C:\...\csv_export"
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$MdbPath,

    [Parameter(Mandatory = $true)]
    [string]$OutDir
)

$ErrorActionPreference = 'Stop'

# Table list mirrors migrations/schema.sql exactly.
$Tables = @(
    'Client', 'clubs', 'dance', 'dancetype', 'DP_blok1_less', 'DP_blok2_figur',
    'exclude_1c', 'figura', 'html_blok', 'html_blokin', 'html_page', 'html_tabcell',
    'LessObj', 'LessType', 'LessWrk', 'lvl', 'muscul', 'practik', 'prepod',
    'progress', 'purpose', 'schedule', 'shownum', 'smssendlog', 'sys_tab', 'trenertype'
)

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$connStr = "Provider=Microsoft.ACE.OLEDB.12.0;Data Source=$MdbPath;Mode=Read;Persist Security Info=False;"
$conn = New-Object System.Data.OleDb.OleDbConnection($connStr)
$conn.Open()

function Format-Csv-Field($value) {
    if ($null -eq $value -or $value -is [System.DBNull]) {
        return '\N'
    }
    if ($value -is [DateTime]) {
        return '"' + $value.ToString('yyyy-MM-dd HH:mm:ss') + '"'
    }
    if ($value -is [bool]) {
        return '"' + $(if ($value) { '1' } else { '0' }) + '"'
    }
    $text = [string]$value
    $text = $text.Replace('"', '""')
    return '"' + $text + '"'
}

$summary = @()

foreach ($table in $Tables) {
    Write-Host "Exporting $table..."
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = "SELECT * FROM [$table]"
    $reader = $cmd.ExecuteReader()

    $columnNames = @()
    for ($i = 0; $i -lt $reader.FieldCount; $i++) {
        $columnNames += $reader.GetName($i)
    }

    $outPath = Join-Path $OutDir "$table.csv"
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    $writer = New-Object System.IO.StreamWriter($outPath, $false, $utf8NoBom)

    $writer.WriteLine(($columnNames | ForEach-Object { '"' + $_.Replace('"', '""') + '"' }) -join ',')

    $rowCount = 0
    while ($reader.Read()) {
        $fields = @()
        for ($i = 0; $i -lt $reader.FieldCount; $i++) {
            $fields += Format-Csv-Field $reader.GetValue($i)
        }
        $writer.WriteLine($fields -join ',')
        $rowCount++
    }

    $writer.Close()
    $reader.Close()
    Write-Host "  $rowCount rows -> $outPath"
    $summary += [PSCustomObject]@{ Table = $table; Rows = $rowCount }
}

$conn.Close()

Write-Host "`nSummary:"
$summary | Format-Table -AutoSize
$summary | Export-Csv -Path (Join-Path $OutDir '_export_summary.csv') -NoTypeInformation -Encoding UTF8
