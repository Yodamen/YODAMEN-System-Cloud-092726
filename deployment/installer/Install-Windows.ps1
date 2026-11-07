# Windows 11 launcher for the same installer used on macOS/Linux.
# Requires WSL Ubuntu and Docker Desktop's Ubuntu integration.
[CmdletBinding()]
param(
    [switch]$CliSetup,
    [switch]$Clean,
    [switch]$Restore,
    [string]$SetupFile,
    [string]$DumpFolder,
    [string]$UploadsFolder,
    [string]$KeyFile,
    [ValidatePattern('^[a-z0-9][a-z0-9_-]*$')]
    [string]$Project = 'yodamen',
    [ValidateRange(1024,65535)]
    [int]$Port = 8080,
    [string]$Distribution = 'Ubuntu'
)
$ErrorActionPreference = 'Stop'

function Convert-ToWslPath([string]$WindowsPath) {
    $resolved = (Resolve-Path -LiteralPath $WindowsPath.Trim('"')).ProviderPath
    $converted = & wsl.exe --distribution $Distribution --exec wslpath -a -u $resolved
    if ($LASTEXITCODE -ne 0 -or -not $converted) {
        throw "Cannot access this path from WSL: $resolved. Copy the files to a local drive first."
    }
    return ($converted | Select-Object -Last 1).Trim()
}

try {
    if (-not (Get-Command wsl.exe -ErrorAction SilentlyContinue)) {
        throw 'WSL is missing. In Administrator PowerShell run: wsl --install -d Ubuntu. Restart Windows and complete Ubuntu setup.'
    }
    & wsl.exe --distribution $Distribution --exec true
    if ($LASTEXITCODE -ne 0) {
        throw "WSL distribution '$Distribution' is not ready. Install it and complete its first-run setup."
    }
    & wsl.exe --distribution $Distribution --exec docker compose version
    if ($LASTEXITCODE -ne 0) {
        throw 'Enable Docker Desktop > Settings > Resources > WSL Integration for Ubuntu, then retry.'
    }
    $engineType = & wsl.exe --distribution $Distribution --exec docker info --format '{{.OSType}}'
    if ($LASTEXITCODE -ne 0) {
        throw 'Start Docker Desktop, select Linux containers, and wait for its engine to be ready.'
    }
    if (($engineType | Select-Object -Last 1) -ne 'linux') {
        throw 'This package requires Linux containers. Switch Docker Desktop to Linux containers and retry.'
    }

    $fresh = -not (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.env'))
    if ($Clean -and $Restore) { throw 'Choose Clean or Restore, not both.' }
    $mode = ''
    $modeFile = Join-Path $PSScriptRoot '.install-state/mode'
    if (Test-Path -LiteralPath $modeFile) { $mode = (Get-Content -LiteralPath $modeFile -Raw).Trim() }
    if ($Clean) { if ($mode -eq 'restore') { throw 'This installation is already in restore mode.' }; $mode = 'clean' }
    if ($Restore) { if ($mode -eq 'clean') { throw 'This installation is already in clean mode.' }; $mode = 'restore' }
    if (-not $mode) {
        if ($DumpFolder -or -not $fresh) { $mode = 'restore' }
        else {
            $choice = Read-Host 'New client without data, or restore a dump? Enter clean or restore (default clean)'
            $mode = if ([string]::IsNullOrWhiteSpace($choice)) { 'clean' } else { $choice.Trim().ToLowerInvariant() }
        }
    }
    if ($mode -notin @('clean', 'restore')) { throw 'Mode must be clean or restore.' }

    $needsData = -not (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.install-state/import-complete')) -and -not (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.install-state/ready'))
    if ($mode -eq 'restore' -and $needsData -and [string]::IsNullOrWhiteSpace($DumpFolder)) {
        $DumpFolder = Read-Host 'Full Windows path to your SQL dump folder (for example C:\Yodamen-Data\Dump20261101)'
    }
    if ($mode -eq 'restore' -and $needsData -and [string]::IsNullOrWhiteSpace($UploadsFolder)) {
        $UploadsFolder = Read-Host 'Full path to original storage\app folder, or press Enter to skip missing uploads'
    }
    if ($mode -eq 'restore' -and $fresh -and [string]::IsNullOrWhiteSpace($KeyFile)) {
        $KeyFile = Read-Host 'Full path to original backend .env to preserve its key, or Enter to generate a new key'
    }

    $script = Convert-ToWslPath (Join-Path $PSScriptRoot 'install.sh')
    $installArguments = @($script, "--$mode")
    if ($CliSetup) { $installArguments += '--cli-setup' }
    if (-not [string]::IsNullOrWhiteSpace($SetupFile)) {
        $installArguments += @('--setup-file', (Convert-ToWslPath $SetupFile))
    }
    if (-not [string]::IsNullOrWhiteSpace($DumpFolder)) {
        $installArguments += @('--dump', (Convert-ToWslPath $DumpFolder))
    }
    if (-not [string]::IsNullOrWhiteSpace($UploadsFolder)) {
        $installArguments += @('--uploads', (Convert-ToWslPath $UploadsFolder))
    }
    if (-not [string]::IsNullOrWhiteSpace($KeyFile)) {
        $installArguments += @('--key-file', (Convert-ToWslPath $KeyFile))
    }
    # Only pass overrides explicitly requested; preserve existing configuration.
    if ($PSBoundParameters.ContainsKey('Project')) {
        $installArguments += @('--project', $Project)
    }
    if ($PSBoundParameters.ContainsKey('Port')) {
        $installArguments += @('--port', [string]$Port)
    }
    & wsl.exe --distribution $Distribution --exec bash @installArguments
    if ($LASTEXITCODE -ne 0) {
        throw 'Installer stopped. Review the error above before retrying; do not delete database volumes.'
    }
    Write-Host 'Windows launcher finished. Open the localhost address printed by the installer.'
    exit 0
}
catch {
    Write-Host ("ERROR: " + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
