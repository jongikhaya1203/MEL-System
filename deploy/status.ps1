<#
.SYNOPSIS
    Shows whether this server is in sync with GitHub, and the last pull log entries.
    Usage (on the server):  powershell -File C:\xampp\htdocs\mel_system\deploy\status.ps1
#>
param([string]$AppPath = 'C:\xampp\htdocs\mel_system', [string]$Branch = 'main')

$git = (Get-Command git.exe -ErrorAction SilentlyContinue).Source
if (-not $git) { $git = 'C:\Program Files\Git\cmd\git.exe' }
& $git -C $AppPath fetch --quiet origin $Branch
$local = (& $git -C $AppPath rev-parse --short HEAD).Trim()
$remote = (& $git -C $AppPath rev-parse --short "origin/$Branch").Trim()
"Server:  $env:COMPUTERNAME"
"Local:   $local  $((& $git -C $AppPath log -1 --format='%s (%cr)').Trim())"
"GitHub:  $remote  ($Branch)"
if ($local -eq $remote) { 'Status:  IN SYNC' } else { 'Status:  BEHIND — the next scheduled pull will update it (or run deploy\pull.ps1 now)' }
$task = Get-ScheduledTask -TaskName 'MEL System - Pull from GitHub' -ErrorAction SilentlyContinue
if ($task) { $i = $task | Get-ScheduledTaskInfo; "Task:    $($task.State), last run $($i.LastRunTime) (result $($i.LastTaskResult)), next $($i.NextRunTime)" }
else { 'Task:    NOT INSTALLED — run deploy\install-server.ps1' }
$log = Join-Path $env:ProgramData 'MEL-Sync\pull.log'
if (Test-Path $log) { ''; 'Last log entries:'; Get-Content $log -Tail 8 }
