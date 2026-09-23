#Requires -Version 5.1
<#
.SYNOPSIS
  Package local_tm_course as a Moodle-installable ZIP.

.DESCRIPTION
  Creates <repo>/local_tm_course.zip beside the plugin folder — same place as
  Windows Explorer "Compress to ZIP" on the local_tm_course directory.
  ZIP root is local_tm_course/ (Moodle install plugin format).
  Does not commit the ZIP (*.zip is gitignored). Excludes VCS / IDE / OS junk.

.EXAMPLE
  powershell -File tools/package_local_tm_course.ps1
#>
[CmdletBinding()]
param(
    [string]$RepoRoot = '',
    # Default: repo root (same as right-click pack → local_tm_course.zip next to the folder)
    [string]$OutDir = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($RepoRoot)) {
    $RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
}
# Match manual workflow: ZIP sits next to the local_tm_course folder.
if ([string]::IsNullOrWhiteSpace($OutDir)) {
    $OutDir = $RepoRoot
}

$pluginDir = Join-Path $RepoRoot 'local_tm_course'
if (-not (Test-Path (Join-Path $pluginDir 'version.php'))) {
    throw "Plugin not found: $pluginDir (missing version.php)"
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$zipName = 'local_tm_course.zip'
$zipPath = Join-Path $OutDir $zipName
$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("tm_course_pkg_" + [guid]::NewGuid().ToString('N'))
$stagingPlugin = Join-Path $stagingRoot 'local_tm_course'

try {
    New-Item -ItemType Directory -Force -Path $stagingPlugin | Out-Null

    # Copy plugin tree; skip VCS / IDE / OS noise. Keep Moodle runtime + tests/fixtures.
    $excludeDirNames = @(
        '.git', '.github', '.idea', '.vscode', '.cursor',
        '__pycache__', 'node_modules', '.phpunit.cache'
    )
    $excludeFileNames = @(
        'Thumbs.db', '.DS_Store', 'desktop.ini',
        '.gitignore', '.gitattributes', '.editorconfig'
    )
    $excludeExtensions = @('.log', '.tmp', '.bak', '.swp')

    Get-ChildItem -LiteralPath $pluginDir -Force | ForEach-Object {
        $name = $_.Name
        if ($_.PSIsContainer) {
            if ($excludeDirNames -contains $name) { return }
            Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $stagingPlugin $name) -Recurse -Force
        } else {
            if ($excludeFileNames -contains $name) { return }
            $ext = $_.Extension.ToLowerInvariant()
            if ($excludeExtensions -contains $ext) { return }
            Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $stagingPlugin $name) -Force
        }
    }

    # Remove nested junk that may have been copied inside subfolders.
    Get-ChildItem -LiteralPath $stagingPlugin -Recurse -Force -ErrorAction SilentlyContinue |
        Where-Object {
            ($_.PSIsContainer -and ($excludeDirNames -contains $_.Name)) -or
            ((-not $_.PSIsContainer) -and (
                ($excludeFileNames -contains $_.Name) -or
                ($excludeExtensions -contains $_.Extension.ToLowerInvariant())
            ))
        } |
        ForEach-Object {
            Remove-Item -LiteralPath $_.FullName -Recurse -Force -ErrorAction SilentlyContinue
        }

    if (-not (Test-Path (Join-Path $stagingPlugin 'version.php'))) {
        throw 'Staging copy missing version.php'
    }

    if (Test-Path $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    # Compress-Archive with the folder path puts that folder name at ZIP root.
    Compress-Archive -Path $stagingPlugin -DestinationPath $zipPath -CompressionLevel Optimal -Force

    # --- Verify ZIP structure ---
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        $entries = @($zip.Entries | ForEach-Object { $_.FullName.Replace('\', '/') })
        $hasVersion = $entries | Where-Object { $_ -eq 'local_tm_course/version.php' -or $_ -eq 'local_tm_course\version.php' }
        if (-not $hasVersion) {
            # Normalize check
            $hasVersion = $entries | Where-Object { $_ -match '^local_tm_course/version\.php$' }
        }
        if (-not $hasVersion) {
            throw "ZIP verification failed: local_tm_course/version.php not found. Sample entries: $(($entries | Select-Object -First 8) -join ', ')"
        }
        $badRoot = $entries | Where-Object {
            $_ -and ($_ -notmatch '^local_tm_course(/|$)')
        }
        if ($badRoot) {
            throw "ZIP verification failed: unexpected root entries: $($badRoot | Select-Object -First 5)"
        }
        $hasGit = $entries | Where-Object { $_ -match '(^|/)\.git(/|$)' }
        if ($hasGit) {
            throw 'ZIP verification failed: .git paths found inside archive'
        }

        $versionText = Get-Content -LiteralPath (Join-Path $pluginDir 'version.php') -Raw
        $release = if ($versionText -match "release\s*=\s*'([^']+)'") { $Matches[1] } else { 'unknown' }
        $pluginVersion = if ($versionText -match 'version\s*=\s*(\d+)') { $Matches[1] } else { 'unknown' }

        Write-Host "OK packaged: $zipPath"
        Write-Host "  size_bytes=$((Get-Item -LiteralPath $zipPath).Length)"
        Write-Host "  entries=$($entries.Count)"
        Write-Host "  plugin_release=$release"
        Write-Host "  plugin_version=$pluginVersion"
        Write-Host "  root=local_tm_course/"
        Write-Host "  version.php=present"
        Write-Output $zipPath
    } finally {
        $zip.Dispose()
    }
} finally {
    if (Test-Path $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
