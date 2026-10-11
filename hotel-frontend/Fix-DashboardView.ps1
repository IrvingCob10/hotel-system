$ErrorActionPreference = 'Stop'

$target = Join-Path (Get-Location) 'src\views\dashboard\DashboardView.vue'

if (-not (Test-Path $target)) {
    Write-Host "No encuentro: $target" -ForegroundColor Red
    Write-Host 'Abre PowerShell en la carpeta hotel-frontend y vuelve a ejecutar este script.' -ForegroundColor Yellow
    exit 1
}

$content = [System.IO.File]::ReadAllText($target)
$original = $content

$content = $content.Replace(
    '{{ room.room_type.name }}',
    "{{ room.room_type?.name ?? 'Tipo no definido' }}"
)
$content = $content.Replace(
    'Capacidad: {{ room.room_type.capacity }} personas',
    "Capacidad: {{ room.room_type?.capacity ?? '—' }} personas"
)

if ($content -eq $original) {
    if ($content.Contains("{{ room.room_type?.name ?? 'Tipo no definido' }}") -and $content.Contains("Capacidad: {{ room.room_type?.capacity ?? '—' }} personas")) {
        Write-Host 'DashboardView.vue ya contiene ambas correcciones; no fue necesario modificarlo.' -ForegroundColor Green
        exit 0
    }

    Write-Host 'No encontré los textos exactos esperados. No modifiqué el archivo; comparte DashboardView.vue para corregirlo manualmente.' -ForegroundColor Red
    exit 2
}

$backup = "$target.bak-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
Copy-Item $target $backup
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($target, $content, $utf8NoBom)

Write-Host 'DashboardView.vue quedó corregido.' -ForegroundColor Green
Write-Host "Copia de seguridad: $backup" -ForegroundColor Cyan
Write-Host 'Ahora ejecuta: npm run build' -ForegroundColor Yellow
