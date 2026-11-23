Option Explicit

Dim shell, command, projectRoot, phpPath, pipelinePath, exitCode
Set shell = CreateObject("WScript.Shell")

projectRoot = "C:\xampp\htdocs\fleet-management"
phpPath = "C:\xampp\php\php.exe"
pipelinePath = projectRoot & "\google\scheduled-sync.php"
command = Chr(34) & phpPath & Chr(34) & " " & Chr(34) & pipelinePath & Chr(34)

' Window style 0 keeps the console application fully hidden; wait so Task Scheduler
' can track the real pipeline exit code and prevent overlapping instances.
exitCode = shell.Run(command, 0, True)
WScript.Quit exitCode
