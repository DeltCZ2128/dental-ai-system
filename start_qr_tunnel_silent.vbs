On Error Resume Next
Set objWMIService = GetObject("winmgmts:\\.\root\cimv2")

' 1. Ensure MySQL is running silently
Set colMysql = objWMIService.ExecQuery("Select * From Win32_Process Where Name = 'mysqld.exe'")
If colMysql.Count = 0 Then
    Set objStartupM = objWMIService.Get("Win32_ProcessStartup")
    Set objConfigM = objStartupM.SpawnInstance_
    objConfigM.ShowWindow = 0
    Set objProcessM = objWMIService.Get("Win32_Process")
    intPidM = 0
    objProcessM.Create """C:\xampp\mysql\bin\mysqld.exe"" --defaults-file=""C:\xampp\mysql\bin\my.ini"" --standalone", "C:\xampp\mysql\bin", objConfigM, intPidM
End If

' 2. Ensure Apache is running silently
Set colHttpd = objWMIService.ExecQuery("Select * From Win32_Process Where Name = 'httpd.exe'")
If colHttpd.Count = 0 Then
    Set objStartupA = objWMIService.Get("Win32_ProcessStartup")
    Set objConfigA = objStartupA.SpawnInstance_
    objConfigA.ShowWindow = 0
    Set objProcessA = objWMIService.Get("Win32_Process")
    intPidA = 0
    objProcessA.Create """C:\xampp\apache\bin\httpd.exe""", "C:\xampp\apache\bin", objConfigA, intPidA
End If

' 3. Ensure Permanent QR Tunnel (ngrok) is running silently
Set colNgrok = objWMIService.ExecQuery("Select * From Win32_Process Where Name = 'ngrok.exe'")
If colNgrok.Count = 0 Then
    Set objStartupN = objWMIService.Get("Win32_ProcessStartup")
    Set objConfigN = objStartupN.SpawnInstance_
    objConfigN.ShowWindow = 0
    Set objProcessN = objWMIService.Get("Win32_Process")
    intPidN = 0
    objProcessN.Create """C:\xampp\htdocs\dental-ai-system-fixed\ngrok.exe"" http --url=appendage-pastrami-untracked.ngrok-free.dev 80", "C:\xampp\htdocs\dental-ai-system-fixed", objConfigN, intPidN
End If
