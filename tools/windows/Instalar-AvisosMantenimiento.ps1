<#
.SYNOPSIS
    Instala (o quita) los avisos de mantenimiento del plugin Mantenimientos de GLPI.

.DESCRIPTION
    Se ejecuta como administrador o como SYSTEM (por ejemplo desde un paquete de
    despliegue de GLPI con la acción "Ejecutar comando"):

        powershell.exe -NoProfile -ExecutionPolicy Bypass -File Instalar-AvisosMantenimiento.ps1

    Hace lo siguiente:
      1. Copia el notificador a %ProgramData%\MantenimientosAvisos.
      2. Escribe config.json con la URL de GLPI y el token.
      3. Crea dos tareas programadas para el grupo Usuarios (corren en la sesión
         de quien inicie sesión, sin permisos de administrador):
           - "Mantenimientos - Avisos al iniciar sesión" (1 minuto después del inicio)
           - "Mantenimientos - Avisos" (cada N minutos desde la instalación y tras cada inicio de sesión)

    La versión que se descarga desde GLPI (Mantenimientos > Avisos en Windows)
    ya trae la URL, el token y los archivos incluidos. Esta plantilla del
    repositorio necesita los parámetros y los archivos en la misma carpeta.

.PARAMETER Endpoint
    URL completa de front/notify.php del plugin.

.PARAMETER Token
    Token del canal de avisos (Mantenimientos > Avisos en Windows).

.PARAMETER IntervaloMinutos
    Cada cuánto se consulta GLPI mientras el usuario tiene sesión abierta.

.PARAMETER NombreVisible
    Nombre que muestra Windows como origen de la notificación.

.PARAMETER Desinstalar
    Quita las tareas y los archivos.
#>
param(
    [string]$Endpoint = '__ENDPOINT__',
    [string]$Token = '__TOKEN__',
    [string]$IntervaloMinutos = '__INTERVALO__',
    [string]$NombreVisible = '__NOMBRE__',
    [switch]$Desinstalar
)

$ErrorActionPreference = 'Stop'

# Solo módulos del sistema: así PowerShell no recorre Documentos\WindowsPowerShell\Modules,
# que suele estar en OneDrive y obligaría a descargar archivos (p. ej. PnP.PowerShell).
$env:PSModulePath = (@(
    (Join-Path $PSHOME 'Modules'),
    (Join-Path $env:ProgramFiles 'WindowsPowerShell\Modules')
) | Select-Object -Unique) -join ';'
$Base       = Join-Path $env:ProgramData 'MantenimientosAvisos'
$TaskLogon  = 'Mantenimientos - Avisos al iniciar sesión'
$TaskRepeat = 'Mantenimientos - Avisos'
$TaskPath   = '\Mantenimientos\'
$UsersSid   = 'S-1-5-32-545' # BUILTIN\Users (independiente del idioma de Windows)

# Archivos incluidos por GLPI al descargar el instalador (base64).
$Embebidos = [ordered]@{
    'MantenimientosAvisos.ps1' = '__B64_CLIENTE__'
    'ejecutar-oculto.vbs'      = '__B64_VBS__'
}

function Test-Placeholder([string]$Value) { return $Value -like '__*__' }

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error 'Ejecute el instalador como administrador o como SYSTEM.'
    exit 1
}

# ---------------------------------------------------------------------------
# Desinstalar
# ---------------------------------------------------------------------------
if ($Desinstalar) {
    foreach ($name in @($TaskLogon, $TaskRepeat)) {
        Unregister-ScheduledTask -TaskName $name -TaskPath $TaskPath -Confirm:$false -ErrorAction SilentlyContinue
    }
    if (Test-Path $Base) { Remove-Item -Path $Base -Recurse -Force }
    Write-Output 'Avisos de mantenimiento desinstalados.'
    exit 0
}

# ---------------------------------------------------------------------------
# Validar parámetros
# ---------------------------------------------------------------------------
if ((Test-Placeholder $Endpoint) -or -not $Endpoint) { Write-Error 'Falta -Endpoint (URL de front/notify.php).'; exit 1 }
if ((Test-Placeholder $Token) -or -not $Token) { Write-Error 'Falta -Token.'; exit 1 }
$interval = 60
if (-not (Test-Placeholder $IntervaloMinutos)) { $interval = [Math]::Max(15, [int]$IntervaloMinutos) }
if ((Test-Placeholder $NombreVisible) -or -not $NombreVisible) { $NombreVisible = 'Mantenimiento de equipos' }

# ---------------------------------------------------------------------------
# Archivos
# ---------------------------------------------------------------------------
New-Item -ItemType Directory -Force -Path $Base | Out-Null

foreach ($file in $Embebidos.Keys) {
    $target = Join-Path $Base $file
    $data   = $Embebidos[$file]
    if (Test-Placeholder $data) {
        $source = Join-Path $PSScriptRoot $file
        if (-not (Test-Path $source)) { Write-Error "No se encuentra $source"; exit 1 }
        Copy-Item -Path $source -Destination $target -Force
    } else {
        [IO.File]::WriteAllBytes($target, [Convert]::FromBase64String($data))
    }
}

$config = [ordered]@{
    endpoint     = $Endpoint
    token        = $Token
    display_name = $NombreVisible
}
# UTF-8 sin BOM
[IO.File]::WriteAllText((Join-Path $Base 'config.json'), ($config | ConvertTo-Json), (New-Object Text.UTF8Encoding $false))

# Ícono de la aplicación en las notificaciones (llave sobre círculo azul)
try {
    Add-Type -AssemblyName System.Drawing
    $bmp = New-Object System.Drawing.Bitmap 128, 128
    $g   = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = 'AntiAlias'
    $g.TextRenderingHint = 'AntiAliasGridFit'
    $g.Clear([System.Drawing.Color]::Transparent)
    $g.FillEllipse((New-Object System.Drawing.SolidBrush ([System.Drawing.ColorTranslator]::FromHtml('#206BC4'))), 2, 2, 124, 124)
    $font = New-Object System.Drawing.Font 'Segoe MDL2 Assets', 60, ([System.Drawing.FontStyle]::Regular), ([System.Drawing.GraphicsUnit]::Pixel)
    $fmt  = New-Object System.Drawing.StringFormat
    $fmt.Alignment = 'Center'; $fmt.LineAlignment = 'Center'
    $g.DrawString([string][char]0xE90F, $font, [System.Drawing.Brushes]::White, (New-Object System.Drawing.RectangleF 0, 0, 128, 128), $fmt) # glifo "Repair"
    $bmp.Save((Join-Path $Base 'icono.png'), [System.Drawing.Imaging.ImageFormat]::Png)
    $g.Dispose(); $bmp.Dispose()
} catch {
    Write-Warning "No se pudo crear el ícono: $($_.Exception.Message)"
}

# ---------------------------------------------------------------------------
# Tareas programadas (grupo Usuarios = corre en la sesión de cada usuario)
# ---------------------------------------------------------------------------
$launcher = Join-Path $Base 'ejecutar-oculto.vbs'
$userPrincipal = New-ScheduledTaskPrincipal -GroupId $UsersSid -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

# 1. Al iniciar sesión (muestra lo pendiente aunque se haya mostrado hace poco)
$logonTrigger = New-ScheduledTaskTrigger -AtLogOn
$logonTrigger.Delay = 'PT1M'
Register-ScheduledTask -TaskName $TaskLogon -TaskPath $TaskPath -Force `
    -Action (New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('"{0}" -Logon' -f $launcher)) `
    -Trigger $logonTrigger -Principal $userPrincipal -Settings $settings `
    -Description 'Plugin Mantenimientos de GLPI: avisos al usuario al iniciar sesión.' | Out-Null

# 2. Repetición mientras la sesión esté abierta
$repeatTrigger = New-ScheduledTaskTrigger -AtLogOn
$repeatTrigger.Delay = 'PT10M'
$repeatTrigger.Repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes $interval)).Repetition
# Además, desde ya (2 minutos después de instalar) y cada N minutos: así no hay que
# esperar a que el usuario cierre sesión y vuelva a entrar para que empiece a consultar.
$clockTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(2) -RepetitionInterval (New-TimeSpan -Minutes $interval)
Register-ScheduledTask -TaskName $TaskRepeat -TaskPath $TaskPath -Force `
    -Action (New-ScheduledTaskAction -Execute 'wscript.exe' -Argument ('"{0}"' -f $launcher)) `
    -Trigger @($repeatTrigger, $clockTrigger) -Principal $userPrincipal -Settings $settings `
    -Description "Plugin Mantenimientos de GLPI: consulta avisos cada $interval minutos." | Out-Null

# Comprobar que GLPI responde con este token
try {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $ping = Invoke-RestMethod -Uri ('{0}?action=ping' -f $Endpoint) -Headers @{ 'X-Mant-Token' = $Token } -TimeoutSec 20 -UseBasicParsing
    Write-Output ("Avisos de mantenimiento instalados. GLPI responde (avisos {0})." -f $(if ($ping.enabled) { 'activados' } else { 'desactivados' }))
} catch {
    Write-Output "Avisos de mantenimiento instalados, pero GLPI no respondió: $($_.Exception.Message)"
}
exit 0
