<#
.SYNOPSIS
  Exports the two Access VIEWS get_dat_sal actually reads
  (wv_stpprep_week, wv_datein_group_week) from 1cdbgdsweek1c.mdb to CSV,
  for the daily refresh of the separate salary MySQL database.

.DESCRIPTION
  Deliberately exports the views' OUTPUT rows (SELECT ... FROM the view),
  not the underlying raw tables -- Access's week-bucketing view definition
  (DatePart("ww", date, 2, 1) & Year(date), Access-specific syntax) is left
  to do the computation, sidestepping the need to hand-port a
  byte-identical week-numbering formula into MySQL. See
  migrations/salary_schema.sql for the destination tables (plain tables,
  refreshed wholesale by this + import-salary-csv.php, not MySQL views).

  Also exports wv_prepod/wv_datain/wv_stvprep/stavka -- feeding
  get_act_sal/getdatashow0722 (the "Мой доход" screen), added alongside
  the original get_dat_sal pair above. These are exported with an explicit
  column list (not SELECT *) because the Access views also join in a full
  redundant copy of prepod's/stavka's own columns, only a handful of which
  getdatashow0722 actually reads.

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

$Sources = [ordered]@{
    'wv_stpprep_week'      = 'SELECT * FROM [wv_stpprep_week]'
    'wv_datein_group_week' = 'SELECT * FROM [wv_datein_group_week]'
    'wv_prepod'            = 'SELECT [prepod.id] AS prepod_id, phone, nameprep, namecat, nameclb FROM [wv_prepod]'
    'wv_datain'            = 'SELECT idprep, idstv, dataindo, pok, sumplan FROM [wv_datain]'
    'wv_stvprep'           = 'SELECT idprep, idstav, datado, minpok, midpok, maxpok, mnojstv, trio, plan, raschet FROM [wv_stvprep]'
    'stavka'               = 'SELECT id, idplus, mnojstv, namestv, notinclud, plan, raschet, showsg1, sqlfield, sqlfield_1c, sumplan, trio, [wherein_1с], [wherein_1с_parent] FROM [stavka]'
}

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

foreach ($view in $Sources.Keys) {
    Write-Host "Exporting $view..."
    $cmd = $conn.CreateCommand()
    $cmd.CommandText = $Sources[$view]
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
