<#
.SYNOPSIS
    Avisos de mantenimiento del plugin Mantenimientos de GLPI.

.DESCRIPTION
    Corre en la sesión del usuario (tarea programada que crea el instalador).
    Consulta GLPI y muestra notificaciones nativas de Windows 10/11 con los
    botones "Entendido" y "Recordar más tarde". La respuesta vuelve a GLPI y
    queda registrada en Mantenimientos > Avisos en Windows.

    Archivos (los deja el instalador en %ProgramData%\MantenimientosAvisos):
      MantenimientosAvisos.ps1   este script
      ejecutar-oculto.vbs        lo lanza sin mostrar consola
      config.json                endpoint, token y nombre visible
    Registro local: %LOCALAPPDATA%\MantenimientosAvisos\avisos.log

.PARAMETER Logon
    Ejecución al iniciar sesión: muestra los avisos pendientes aunque se hayan
    mostrado hace poco.

.PARAMETER Uri
    Lo usa Windows al pulsar un botón de la notificación (mantavisos:done/ID).

.PARAMETER Prueba
    Muestra una notificación de ejemplo sin consultar GLPI.
#>
param(
    [switch]$Logon,
    [string]$Uri = '',
    [switch]$Prueba
)

$ErrorActionPreference = 'Stop'

# Solo módulos del sistema: así PowerShell no recorre Documentos\WindowsPowerShell\Modules,
# que suele estar en OneDrive y obligaría a descargar archivos (p. ej. PnP.PowerShell).
$env:PSModulePath = (@(
    (Join-Path $PSHOME 'Modules'),
    (Join-Path $env:ProgramFiles 'WindowsPowerShell\Modules')
) | Select-Object -Unique) -join ';'
$Base       = Join-Path $env:ProgramData 'MantenimientosAvisos'
$ConfigFile = Join-Path $Base 'config.json'
$Launcher   = Join-Path $Base 'ejecutar-oculto.vbs'
$AppId      = 'MantenimientosTI.Avisos'
$Protocol   = 'mantavisos'
$LocalDir   = Join-Path $env:LOCALAPPDATA 'MantenimientosAvisos'
$LogFile    = Join-Path $LocalDir 'avisos.log'

New-Item -ItemType Directory -Force -Path $LocalDir | Out-Null

function Write-Log([string]$Message) {
    try {
        if ((Test-Path $LogFile) -and (Get-Item $LogFile).Length -gt 512KB) {
            Move-Item $LogFile "$LogFile.1" -Force
        }
        Add-Content -Path $LogFile -Encoding UTF8 -Value ('{0:yyyy-MM-dd HH:mm:ss} {1}' -f (Get-Date), $Message)
    } catch { }
}

# Colores iguales a los del semáforo del plugin
$Levels = @{
    warning   = @{ Color = '#F59F00'; Glyph = '!' }
    critical  = @{ Color = '#F76707'; Glyph = '!' }
    overdue   = @{ Color = '#D63939'; Glyph = '!' }
    scheduled = @{ Color = '#4263EB'; Glyph = 'i' }
}

# ---------------------------------------------------------------------------
# Configuración y registro de la aplicación en la sesión del usuario
# ---------------------------------------------------------------------------

function Get-Config {
    if (-not (Test-Path $ConfigFile)) { throw "No existe $ConfigFile. Ejecute el instalador." }
    $cfg = Get-Content -Path $ConfigFile -Raw -Encoding UTF8 | ConvertFrom-Json
    if (-not $cfg.endpoint -or -not $cfg.token) { throw 'config.json incompleto (endpoint o token).' }
    if (-not $cfg.display_name) { $cfg | Add-Member -NotePropertyName display_name -NotePropertyValue 'Mantenimiento de equipos' -Force }
    return $cfg
}

# Registra (por usuario) el nombre que muestra Windows en la notificación y el
# protocolo mantavisos: que usan los botones. No requiere permisos de administrador.
function Register-App($cfg) {
    $app = "HKCU:\Software\Classes\AppUserModelId\$AppId"
    if (-not (Test-Path $app)) { New-Item -Path $app -Force | Out-Null }
    Set-ItemProperty -Path $app -Name DisplayName -Value $cfg.display_name
    $icon = Join-Path $Base 'icono.png'
    if (Test-Path $icon) { Set-ItemProperty -Path $app -Name IconUri -Value $icon }

    $proto   = "HKCU:\Software\Classes\$Protocol"
    $command = 'wscript.exe "{0}" -Uri "%1"' -f $Launcher
    if (-not (Test-Path "$proto\shell\open\command")) { New-Item -Path "$proto\shell\open\command" -Force | Out-Null }
    Set-ItemProperty -Path $proto -Name '(default)' -Value 'URL:Avisos de mantenimiento'
    Set-ItemProperty -Path $proto -Name 'URL Protocol' -Value ''
    Set-ItemProperty -Path "$proto\shell\open\command" -Name '(default)' -Value $command
}

# ---------------------------------------------------------------------------
# Comunicación con GLPI
# ---------------------------------------------------------------------------

function Get-Identity {
    $serial = ''
    try { $serial = [string](Get-CimInstance -ClassName Win32_BIOS).SerialNumber } catch { }
    # En equipos unidos a Entra ID la cuenta útil es el UPN (usuario@dominio);
    # en cuentas locales o de dominio clásico se usa DOMINIO\usuario.
    $user = '{0}\{1}' -f $env:USERDOMAIN, $env:USERNAME
    try {
        $upn = [string](& "$env:SystemRoot\System32\whoami.exe" /upn 2>$null)
        if ($upn -match '^[^@\s]+@[^@\s]+$') { $user = $upn.Trim() }
    } catch { }
    return @{
        serial = $serial.Trim()
        name   = $env:COMPUTERNAME
        user   = $user
    }
}

function Invoke-Glpi($cfg, [hashtable]$Query) {
    [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
    $pairs = foreach ($key in $Query.Keys) { '{0}={1}' -f $key, [uri]::EscapeDataString([string]$Query[$key]) }
    $url   = '{0}?{1}' -f $cfg.endpoint, ($pairs -join '&')
    return Invoke-RestMethod -Uri $url -Method Get -Headers @{ 'X-Mant-Token' = [string]$cfg.token } -TimeoutSec 20 -UseBasicParsing
}

# ---------------------------------------------------------------------------
# Notificación
# ---------------------------------------------------------------------------

# Ícono redondo del color del nivel (se genera una vez y se guarda en caché).
function Get-LevelIcon([string]$Level) {
    $def  = $Levels[$Level]
    if (-not $def) { $def = $Levels['warning'] }
    $path = Join-Path $LocalDir ("nivel-$Level.png")
    if (Test-Path $path) { return $path }
    try {
        Add-Type -AssemblyName System.Drawing
        $bmp = New-Object System.Drawing.Bitmap 96, 96
        $g   = [System.Drawing.Graphics]::FromImage($bmp)
        $g.SmoothingMode     = 'AntiAlias'
        $g.TextRenderingHint = 'AntiAliasGridFit'
        $g.Clear([System.Drawing.Color]::Transparent)
        $g.FillEllipse((New-Object System.Drawing.SolidBrush ([System.Drawing.ColorTranslator]::FromHtml($def.Color))), 2, 2, 92, 92)
        $font = New-Object System.Drawing.Font 'Segoe UI', 46, ([System.Drawing.FontStyle]::Bold), ([System.Drawing.GraphicsUnit]::Pixel)
        $fmt  = New-Object System.Drawing.StringFormat
        $fmt.Alignment = 'Center'; $fmt.LineAlignment = 'Center'
        $g.DrawString($def.Glyph, $font, [System.Drawing.Brushes]::White, (New-Object System.Drawing.RectangleF 0, 2, 96, 96), $fmt)
        $bmp.Save($path, [System.Drawing.Imaging.ImageFormat]::Png)
        $g.Dispose(); $bmp.Dispose()
        return $path
    } catch {
        return $null
    }
}

function Show-Toast([int]$Id, [string]$Level, [string]$Title, [string]$Message, [bool]$Sticky) {
    $esc  = { param($s) [Security.SecurityElement]::Escape([string]$s) }
    $icon = Get-LevelIcon $Level
    $img  = if ($icon) { "<image placement='appLogoOverride' hint-crop='circle' src='$(& $esc ([uri]$icon).AbsoluteUri)'/>" } else { '' }
    $scenario = if ($Sticky) { " scenario='reminder'" } else { '' }
    $audio    = if ($Sticky) { 'ms-winsoundevent:Notification.Reminder' } else { 'ms-winsoundevent:Notification.Default' }

    $xmlText = @"
<toast$scenario>
  <visual>
    <binding template='ToastGeneric'>
      <text>$(& $esc $Title)</text>
      <text>$(& $esc $Message)</text>
      $img
    </binding>
  </visual>
  <actions>
    <action content='Entendido' activationType='protocol' arguments='$($Protocol):done/$Id'/>
    <action content='Recordar más tarde' activationType='protocol' arguments='$($Protocol):snooze/$Id'/>
  </actions>
  <audio src='$audio'/>
</toast>
"@

    try {
        [void][Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime]
        [void][Windows.Data.Xml.Dom.XmlDocument, Windows.Data.Xml.Dom.XmlDocument, ContentType = WindowsRuntime]
        $xml = New-Object Windows.Data.Xml.Dom.XmlDocument
        $xml.LoadXml($xmlText)
        $toast       = [Windows.UI.Notifications.ToastNotification]::new($xml)
        $toast.Tag   = "mant$Id"
        $toast.Group = 'mantenimientos'
        [Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier($AppId).Show($toast)
        return $null
    } catch {
        Write-Log "Notificación nativa no disponible ($($_.Exception.Message)); se usa ventana emergente."
        # Plan B: ventana clásica. Sí = Entendido, No = Recordar más tarde.
        $shell   = New-Object -ComObject WScript.Shell
        $timeout = if ($Sticky) { 0 } else { 120 }
        $answer  = $shell.Popup("$Message`n`n¿Entendido?  (No = recordar más tarde)", $timeout, $Title, 4 + 48 + 4096)
        switch ($answer) { 6 { return 'done' } 7 { return 'snooze' } default { return $null } }
    }
}

function Remove-Toast([int]$Id) {
    try {
        [void][Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime]
        [Windows.UI.Notifications.ToastNotificationManager]::History.Remove("mant$Id", 'mantenimientos', $AppId)
    } catch { }
}

function Send-Answer($cfg, [int]$Id, [string]$Answer) {
    $who = Get-Identity
    $res = Invoke-Glpi $cfg @{ action = 'respond'; id = $Id; answer = $Answer; serial = $who.serial; name = $who.name; user = $who.user }
    Write-Log "Respuesta '$Answer' al aviso $Id -> ok=$($res.ok)"
}

# ---------------------------------------------------------------------------
# Principal
# ---------------------------------------------------------------------------

try {
    $cfg = Get-Config
    Register-App $cfg

    if ($Prueba) {
        Show-Toast 0 'critical' 'Mantenimiento preventivo muy próximo' 'Hola, esta es una notificación de prueba del plugin Mantenimientos. Si la ves, el equipo está listo para recibir avisos.' $false | Out-Null
        Write-Log 'Notificación de prueba mostrada.'
        return
    }

    # Respuesta desde un botón de la notificación
    if ($Uri) {
        if ($Uri -match "^${Protocol}:/*(done|snooze)/(\d+)") {
            $id = [int]$Matches[2]
            Remove-Toast $id
            if ($id -gt 0) { Send-Answer $cfg $id $Matches[1] }
        } else {
            Write-Log "Uri no reconocida: $Uri"
        }
        return
    }

    # Consulta periódica / al iniciar sesión
    $who   = Get-Identity
    $query = @{ action = 'pending'; serial = $who.serial; name = $who.name; user = $who.user }
    if ($Logon) { $query.logon = '1' }
    $res = Invoke-Glpi $cfg $query

    if (-not $res.enabled) { Write-Log 'Avisos desactivados en GLPI.'; return }
    if ($res.found -eq $false) { Write-Log "Equipo no encontrado en GLPI (serial '$($who.serial)', nombre '$($who.name)')."; return }

    foreach ($n in @($res.notices)) {
        if (-not $n) { continue }
        Write-Log "Aviso $($n.id) [$($n.level)] $($n.title)"
        $answer = Show-Toast ([int]$n.id) ([string]$n.level) ([string]$n.title) ([string]$n.message) ([bool]$n.sticky)
        if ($answer) { Send-Answer $cfg ([int]$n.id) $answer }
    }
} catch {
    Write-Log "Error: $($_.Exception.Message)"
    exit 1
}
