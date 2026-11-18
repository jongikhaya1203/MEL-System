<#
.SYNOPSIS
    One-time setup of GitHub sync on a server (USAWINAPP04, USAWINPRS01, ...).

.DESCRIPTION
    Run once on each server, in an elevated PowerShell, signed in as an administrator:

        powershell -ExecutionPolicy Bypass -File install-server.ps1

    It:
      1. checks Git for Windows is installed;
      2. links C:\xampp\htdocs\mel_system to the GitHub repository — cloning it when the folder is
         empty or missing, or attaching an existing copy (its uploaded files are kept);
      3. creates config\config.local.php from the example if it doesn't exist yet (edit it!);
      4. registers the "MEL System - Pull from GitHub" scheduled task, which runs deploy\pull.ps1
         as SYSTEM every 5 minutes (no password is stored anywhere);
      5. runs a first pull.

    No credentials are needed or saved: the task runs as the built-in SYSTEM account, and the
    repository is read over HTTPS.
#>
param(
    [string]$AppPath = 'C:\xampp\htdocs\mel_system',
    [string]$RepoUrl = 'https://github.com/jongikhaya1203/MEL-System.git',
    [string]$Branch = 'main',
    [int]$EveryMinutes = 5
)

$ErrorActionPreference = 'Stop'
$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated PowerShell (Run as administrator).'
}

# 1. Git
$git = (Get-Command git.exe -ErrorAction SilentlyContinue).Source
if (-not $git -and (Test-Path 'C:\Program Files\Git\cmd\git.exe')) { $git = 'C:\Program Files\Git\cmd\git.exe' }
if (-not $git) {
    throw 'Git for Windows is not installed. Install it from https://git-scm.com/download/win (or: winget install --id Git.Git -e), then re-run this script.'
}
$safe = ($AppPath -replace '\\', '/')
& $git config --system --get-all safe.directory 2>$null | Out-Null
if (-not ((& $git config --system --get-all safe.directory 2>$null) -contains $safe)) {
    & $git config --system --add safe.directory $safe
}
Write-Host "Git: $git"

# 2. Repository
if (-not (Test-Path (Join-Path $AppPath '.git'))) {
    $existing = (Test-Path $AppPath) -and (Get-ChildItem -Force $AppPath | Select-Object -First 1)
    if (-not $existing) {
        Write-Host "Cloning $RepoUrl ($Branch) into $AppPath ..."
        & $git clone --branch $Branch $RepoUrl $AppPath
        if ($LASTEXITCODE) { throw 'git clone failed.' }
    }
    else {
        # An existing copy (e.g. copied over manually): back it up, then attach it to GitHub in place.
        $backup = "$AppPath.backup_{0:yyyyMMdd_HHmmss}" -f (Get-Date)
        Write-Host "Existing files found. Backing up to $backup ..."
        robocopy $AppPath $backup /E /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -ge 8) { throw 'Backup copy failed — nothing was changed.' }
        & $git -C $AppPath init --quiet
        & $git -C $AppPath remote add origin $RepoUrl
        & $git -C $AppPath fetch --quiet origin $Branch
        if ($LASTEXITCODE) { throw 'git fetch failed — check internet access to github.com.' }
        & $git -C $AppPath checkout --quiet --force -B $Branch "origin/$Branch"
        Write-Host "Attached to GitHub. Code files now match GitHub; uploads and config.local.php were kept."
    }
}
else {
    $origin = (& $git -C $AppPath remote get-url origin 2>$null)
    if ($origin -ne $RepoUrl) { & $git -C $AppPath remote set-url origin $RepoUrl 2>$null; if ($LASTEXITCODE) { & $git -C $AppPath remote add origin $RepoUrl } }
    Write-Host "Repository already present at $AppPath."
}

# 3. Per-server config
$local = Join-Path $AppPath 'config\config.local.php'
if (-not (Test-Path $local)) {
    Copy-Item (Join-Path $AppPath 'config\config.local.example.php') $local
    Write-Warning "Created $local — edit it now with this server's database port, user and password."
}

# 4. Scheduled task (runs as SYSTEM — no stored password)
$taskName = 'MEL System - Pull from GitHub'
$script = Join-Path $AppPath 'deploy\pull.ps1'
$action = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$script`" -AppPath `"$AppPath`" -Branch $Branch"
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes $EveryMinutes) -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -StartWhenAvailable
$system = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $system -Force | Out-Null
Write-Host "Scheduled task '$taskName' runs every $EveryMinutes minutes."

# 5. First pull
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $script -AppPath $AppPath -Branch $Branch
Write-Host ''
Write-Host "Done. Log: $env:ProgramData\MEL-Sync\pull.log"
Write-Host "Check status any time with:  powershell -File `"$AppPath\deploy\status.ps1`""
