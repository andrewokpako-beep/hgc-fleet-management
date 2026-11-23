$ErrorActionPreference = 'Stop'
$taskName = 'Fleet Management Auto Sync'
$wscriptPath = Join-Path $env:WINDIR 'System32\wscript.exe'
$projectRoot = 'C:\xampp\htdocs\fleet-management'
$scriptPath = Join-Path $projectRoot 'cron\run-background.vbs'

foreach ($path in @($wscriptPath, $scriptPath)) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        throw "Required file not found: $path"
    }
}

$user = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$start = (Get-Date).AddMinutes(1).ToString('s')
$xml = @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>Sync approved certificate expiries to the shared certificate register, read changed source snapshots, reconcile, and import only safe new assets. Runs once per minute in the background and skips overlapping executions.</Description></RegistrationInfo>
  <Triggers><CalendarTrigger><StartBoundary>$start</StartBoundary><Enabled>true</Enabled><ScheduleByDay><DaysInterval>1</DaysInterval></ScheduleByDay><Repetition><Interval>PT1M</Interval><Duration>P1D</Duration><StopAtDurationEnd>false</StopAtDurationEnd></Repetition></CalendarTrigger></Triggers>
  <Principals><Principal id="Author"><UserId>$user</UserId><LogonType>InteractiveToken</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><StartWhenAvailable>true</StartWhenAvailable><ExecutionTimeLimit>PT30M</ExecutionTimeLimit><RestartOnFailure><Interval>PT5M</Interval><Count>3</Count></RestartOnFailure><Priority>7</Priority></Settings>
  <Actions Context="Author"><Exec><Command>$wscriptPath</Command><Arguments>//B //NoLogo "$scriptPath"</Arguments><WorkingDirectory>$projectRoot</WorkingDirectory></Exec></Actions>
</Task>
"@

Register-ScheduledTask -TaskName $taskName -Xml $xml -Force | Out-Null
$task = Get-ScheduledTask -TaskName $taskName
Write-Output "Registered '$taskName' for $user. State: $($task.State). Trigger: every 1 minute while this user is signed in."
