# Builds afristream-portal.zip from dist/afristream-portal/ with forward-slash
# entry names. Windows PowerShell's Compress-Archive and .NET Framework's
# ZipFile.CreateFromDirectory both write backslash separators, which WordPress's
# extractor mishandles (the plugin lands as a stray file, not a folder). Building
# each entry explicitly with CreateEntryFromFile lets us control the name.
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$src  = Join-Path $root 'dist\afristream-portal'
$zip  = Join-Path $root 'afristream-portal.zip'

if (-not (Test-Path $src)) { throw "Staged plugin not found at $src - run 'npm run build' first." }
if (Test-Path $zip) { Remove-Item $zip -Force }

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$fwd = [char]0x2F  # forward slash, kept out of a regex to avoid shell quoting issues
$fs = [System.IO.File]::Open($zip, [System.IO.FileMode]::CreateNew)
$archive = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Create)
try {
  $base = (Resolve-Path $src).Path
  foreach ($f in (Get-ChildItem -Path $src -Recurse -File)) {
    $rel = $f.FullName.Substring($base.Length + 1).Replace([char]0x5C, $fwd)
    $entryName = 'afristream-portal' + $fwd + $rel
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $f.FullName, $entryName, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
    Write-Output "added $entryName"
  }
} finally {
  $archive.Dispose()
  $fs.Dispose()
}
Write-Output "Wrote $zip"
