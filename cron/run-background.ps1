$ErrorActionPreference = 'Stop'
$projectRoot = 'C:\xampp\htdocs\fleet-management'
$phpPath = 'C:\xampp\php\php.exe'
$pipelinePath = Join-Path $projectRoot 'google\scheduled-sync.php'
$process = Start-Process -FilePath $phpPath -ArgumentList @($pipelinePath) -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -Wait
exit $process.ExitCode
