<#
.SYNOPSIS
    Keeps this server's copy of the MEL System in sync with GitHub.

.DESCRIPTION
    Runs on the server (normally every 5 minutes from the "MEL System - Pull from GitHub"
    scheduled task created by install-server.ps1). Each run:
      1. fetches the tracked branch from GitHub;
      2. if there is new code, saves any local edits to tracked files as a patch in the log
         folder, then moves the working copy to exactly match GitHub;
      3. if database/schema.sql changed, applies it (idempotent: only adds what is missing).

    Never touched: uploaded files in storage\, config\config.local.php (this server's database
    settings) and config\app_key.php — all git-ignored, and this script never runs "git clean".

    Log: C:\ProgramData\MEL-Sync\pull.log (outside the web root).
#>
param(
    [string]$AppPath = 'C:\xampp\htdocs\mel_system',
    [string]$Branch = 'main',
    [string]$PhpExe = 'C:\xampp\php\php.exe',
    [switch]$SkipSchema
)

$ErrorActionPreference = 'Stop'
$logDir = Join-Path $env:ProgramData 'MEL-Sync'
$log = Join-Path $logDir 'pull.log'
New-Item -ItemType Directory -Force $logDir | Out-Null
if ((Test-Path $log) -and (Get-Item $log).Length -gt 5MB) { Move-Item -Force $log "$log.1" }

function Write-Log([string]$msg) {
    $line = '{0:yyyy-MM-dd HH:mm:ss}  {1}' -f (Get-Date), $msg
    Add-Content -Path $log -Value $line -Encoding UTF8
    Write-Output $line
}

# One run at a time.
$lockPath = Join-Path $logDir 'pull.lock'
try { $lock = [IO.File]::Open($lockPath, 'OpenOrCreate', 'ReadWrite', 'None') }
catch { Write-Output 'Another pull is running; skipping.'; exit 0 }

try {
    $git = (Get-Command git.exe -ErrorAction SilentlyContinue).Source
    if (-not $git) { $git = 'C:\Program Files\Git\cmd\git.exe' }
    if (-not (Test-Path $git)) { throw 'Git for Windows is not installed (git.exe not found).' }

    # The task runs as SYSTEM while the folder is owned by an administrator; mark it safe for this call only.
    $safe = ($AppPath -replace '\\', '/')
    function Git { & $git -c "safe.directory=$safe" -C $AppPath @args 2>&1; if ($LASTEXITCODE) { throw "git $($args -join ' ') failed (exit $LASTEXITCODE)" } }

    Git fetch --prune --quiet origin $Branch | Out-Null
    $local = (Git rev-parse HEAD).Trim()
    $remote = (Git rev-parse "origin/$Branch").Trim()
    if ($local -eq $remote) { exit 0 }   # already in sync — stay quiet

    Write-Log "Update available: $($local.Substring(0,7)) -> $($remote.Substring(0,7)) ($Branch)"

    # Keep a copy of anything edited directly on this server before overwriting it.
    $dirty = Git status --porcelain --untracked-files=no
    if ($dirty) {
        $patch = Join-Path $logDir ('local-changes_{0:yyyyMMdd_HHmmss}.patch' -f (Get-Date))
        Git diff HEAD | Set-Content -Path $patch -Encoding UTF8
        Write-Log "Local edits to tracked files were saved to $patch and will be replaced by the GitHub version."
    }

    $schemaChanged = [bool](Git diff --name-only $local $remote -- database/schema.sql)

    Git checkout --quiet --force -B $Branch "origin/$Branch" | Out-Null
    Git reset --quiet --hard "origin/$Branch" | Out-Null
    Write-Log "Code updated to $($remote.Substring(0,7)): $((Git log -1 --format=%s).Trim())"

    if ($schemaChanged -and -not $SkipSchema) {
        if (-not (Test-Path $PhpExe)) { throw "schema.sql changed but PHP was not found at $PhpExe" }
        $out = & $PhpExe (Join-Path $AppPath 'deploy\apply_schema.php') 2>&1
        if ($LASTEXITCODE) { throw "Applying schema.sql failed: $out" }
        Write-Log ($out -join ' ')
    }
}
catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
}
finally {
    $lock.Close()
}
