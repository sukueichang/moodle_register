#Requires -Version 5.1
<#
.SYNOPSIS
  Package local_tm_course as a Moodle-installable ZIP.

.DESCRIPTION
  Creates <repo>/local_tm_course.zip beside the plugin folder.
  ZIP root is local_tm_course/ (Moodle install plugin format).
  Uses tar.exe so ZIP entry paths use forward slashes (required by Moodle).
  Do NOT use Compress-Archive on Windows PowerShell 5.1 — it writes backslashes.
  Does not commit the ZIP (*.zip is gitignored). Excludes VCS / IDE / OS junk.

.EXAMPLE
  powershell -File tools/package_local_tm_course.ps1
#>
[CmdletBinding()]
param(
    [string]$RepoRoot = '',
    [string]$OutDir = ''
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($RepoRoot)) {
    $RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
}
if ([string]::IsNullOrWhiteSpace($OutDir)) {
    $OutDir = $RepoRoot
}

$pluginDir = Join-Path $RepoRoot 'local_tm_course'
if (-not (Test-Path (Join-Path $pluginDir 'version.php'))) {
    throw "Plugin not found: $pluginDir (missing version.php)"
}

$tar = Get-Command tar.exe -ErrorAction SilentlyContinue
if (-not $tar) {
    throw 'tar.exe not found. Moodle plugin ZIPs must be built with tar.exe (see docs/DEV_WORKFLOW.md §4).'
}

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

$zipName = 'local_tm_course.zip'
$zipPath = Join-Path $OutDir $zipName
$stagingRoot = Join-Path ([System.IO.Path]::GetTempPath()) ("tm_course_pkg_" + [guid]::NewGuid().ToString('N'))
$stagingPlugin = Join-Path $stagingRoot 'local_tm_course'

try {
    New-Item -ItemType Directory -Force -Path $stagingPlugin | Out-Null

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
    if (-not (Test-Path (Join-Path $stagingPlugin 'db\install.xml'))) {
        throw 'Staging copy missing db/install.xml'
    }

    if (Test-Path $zipPath) {
        Remove-Item -LiteralPath $zipPath -Force
    }

    # Verified Moodle-safe packer (forward-slash entry names). See DEV_WORKFLOW §4 / BUGFIX_LOG.
    & tar.exe -a -c -f $zipPath -C $stagingRoot local_tm_course
    if ($LASTEXITCODE -ne 0) {
        throw "tar.exe failed with exit code $LASTEXITCODE"
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        $entries = @($zip.Entries | ForEach-Object { $_.FullName })
        $backslashCount = @($entries | Where-Object { $_ -match '\\' }).Count
        if ($backslashCount -ne 0) {
            throw "ZIP verification failed: $backslashCount entries use backslash separators"
        }

        $normalized = @($entries | ForEach-Object { $_.Replace('\', '/') })
        if (-not ($normalized | Where-Object { $_ -eq 'local_tm_course/version.php' })) {
            throw "ZIP verification failed: local_tm_course/version.php not found. Sample: $(($normalized | Select-Object -First 8) -join ', ')"
        }
        if (-not ($normalized | Where-Object { $_ -eq 'local_tm_course/db/install.xml' })) {
            throw 'ZIP verification failed: local_tm_course/db/install.xml not found'
        }
        $badRoot = $normalized | Where-Object { $_ -and ($_ -notmatch '^local_tm_course(/|$)') }
        if ($badRoot) {
            throw "ZIP verification failed: unexpected root entries: $($badRoot | Select-Object -First 5)"
        }
        if ($normalized | Where-Object { $_ -match '(^|/)\.git(/|$)' }) {
            throw 'ZIP verification failed: .git paths found inside archive'
        }

        $versionText = Get-Content -LiteralPath (Join-Path $pluginDir 'version.php') -Raw
        $release = if ($versionText -match "release\s*=\s*'([^']+)'") { $Matches[1] } else { 'unknown' }
        $pluginVersion = if ($versionText -match 'version\s*=\s*(\d+)') { $Matches[1] } else { 'unknown' }

        Write-Host "OK packaged: $zipPath"
        Write-Host "  size_bytes=$((Get-Item -LiteralPath $zipPath).Length)"
        Write-Host "  entries=$($normalized.Count)"
        Write-Host "  plugin_release=$release"
        Write-Host "  plugin_version=$pluginVersion"
        Write-Host "  backslash_entries=0"
        Write-Host "  root=local_tm_course/"
        Write-Host "  version.php=present"
        Write-Host "  db/install.xml=present"
        Write-Output $zipPath
    } finally {
        $zip.Dispose()
    }
} finally {
    if (Test-Path $stagingRoot) {
        Remove-Item -LiteralPath $stagingRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
