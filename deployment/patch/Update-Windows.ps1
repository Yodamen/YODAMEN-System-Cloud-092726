[CmdletBinding()]
param([string]$InstallationFolder, [string]$Distribution = 'Ubuntu', [switch]$Check)
$ErrorActionPreference = 'Stop'
function Convert-ToWslPath([string]$WindowsPath) {
    $resolved = (Resolve-Path -LiteralPath $WindowsPath.Trim('"')).ProviderPath
    $converted = & wsl.exe --distribution $Distribution --exec wslpath -a -u $resolved
    if ($LASTEXITCODE -ne 0 -or -not $converted) { throw "Cannot access path through WSL: $resolved" }
    return ($converted | Select-Object -Last 1).Trim()
}
try {
    if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) { throw 'Open Docker Desktop and ensure WSL Ubuntu is installed.' }
    & wsl.exe --distribution $Distribution --exec docker info --format '{{.OSType}}'
    if ($LASTEXITCODE -ne 0) { throw 'Start Docker Desktop and enable Ubuntu integration.' }
    if ([string]::IsNullOrWhiteSpace($InstallationFolder)) {
        $InstallationFolder = Read-Host 'Full path to the EXISTING installed folder (for example C:\Yodamen)'
    }
    $script = Convert-ToWslPath (Join-Path $PSScriptRoot 'update.sh')
    $installed = Convert-ToWslPath $InstallationFolder
    $arguments = @($script, '--installation', $installed)
    if ($Check) { $arguments += '--check' }
    & wsl.exe --distribution $Distribution --exec bash @arguments
    if ($LASTEXITCODE -ne 0) { throw 'Patch stopped. Read the error above and PATCH.md. Do not reinstall or delete volumes.' }
    exit 0
} catch {
    Write-Host ('ERROR: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
