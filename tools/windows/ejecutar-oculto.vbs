' Mantenimientos - Avisos en Windows
' Lanza MantenimientosAvisos.ps1 sin mostrar la ventana de consola.
' Lo usan la tarea programada y los botones de la notificacion (protocolo mantavisos:).
Option Explicit
Dim shell, fso, dir, args, i, a, q
Set shell = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
q = Chr(34)
dir = fso.GetParentFolderName(WScript.ScriptFullName)
args = ""
For i = 0 To WScript.Arguments.Count - 1
    a = WScript.Arguments(i)
    If InStr(a, " ") > 0 Then a = q & a & q
    args = args & " " & a
Next
shell.Run "powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File " _
    & q & dir & "\MantenimientosAvisos.ps1" & q & args, 0, False
