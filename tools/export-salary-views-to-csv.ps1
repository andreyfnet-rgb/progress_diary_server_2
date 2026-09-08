<#
.SYNOPSIS
  Exports the two Access VIEWS get_dat_sal actually reads
  (wv_stpprep_week, wv_datein_group_week) from 1cdbgdsweek1c.mdb to CSV,
  for the daily refresh of the separate salary MySQL database.

.DESCRIPTION
  Deliberately exports the views' OUTPUT rows (SELECT * FROM the view),
  not the underlying raw tables -- Access's week-bucketing view definition
  (DatePart("ww", date, 2, 1) & Year(date), Access-specific syntax) is left
  to do the computation, sidestepping the need to hand-port a
  byte-identical week-numbering formula into MySQL. See
  migrations/salary_schema.sql for the destination tables (plain tables,
  refreshed wholesale by this + import-salary-csv.php, not MySQL views).

  Same CSV conventions as export-access-to-csv.ps1: UTF-8 no BOM, every
  field quoted, NULL as a bare unquoted \N, read-only connection.

.EXAMPLE
  .\export-salary-views-to-csv.ps1 -MdbPath "C:\...\1cdbgdsweek1c.mdb" -OutDir "C:\...\salary_csv_export"
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$MdbPath,

    [Parameter(Mandatory = $true)]
    [string]$OutDir
)

$ErrorActionPreference = 'Stop'

$Views = @('wv_stpprep_week', 'wv_datein_group_week')

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

foreach ($view in $Views) {
    Write-Host "Exporting $view..."
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = "SELECT * FROM [$view]"
    $reader = $cmd.ExecuteReader()

    $columnNames = @()
    for ($i = 0; $i -lt $reader.FieldCount; $i++) {
        $columnNames += $reader.GetName($i)
    }

    $outPath = Join-Path $OutDir "$view.csv"
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
    $summary += [PSCustomObject]@{ View = $view; Rows = $rowCount }
}

$conn.Close()

Write-Host "`nSummary:"
$summary | Format-Table -AutoSize
