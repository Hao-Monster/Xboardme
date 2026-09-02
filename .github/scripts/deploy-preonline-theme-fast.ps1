[CmdletBinding()]
param(
    [ValidatePattern('^[A-Za-z0-9._-]+$')]
    [string] $SshTarget = 'bingo-dev',
    [switch] $Force
)

$ErrorActionPreference = 'Stop'
$repoRoot = (& git rev-parse --show-toplevel).Trim()
if ($LASTEXITCODE -ne 0 -or -not $repoRoot) { throw 'Not inside a Git repository.' }
Push-Location $repoRoot

$temporaryRoot = $null
$totalTimer = [Diagnostics.Stopwatch]::StartNew()
try {
    $assetSha = (& git rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0 -or $assetSha -notmatch '^[a-f0-9]{40}$') { throw 'HEAD must be a full commit SHA.' }

    $trackedChanges = @(& git status --porcelain=v1 --untracked-files=no)
    if ($LASTEXITCODE -ne 0) { throw 'Unable to inspect the working tree.' }
    if ($trackedChanges.Count -gt 0) { throw 'Fast deployment requires all tracked changes to be committed.' }

    $baseReleaseSha = (& ssh $SshTarget "set -eu; source /home/bingo/apps/xboardme-pre-online/release-state.env; printf '%s' `"`$RELEASE_SHA`"").Trim()
    if ($LASTEXITCODE -ne 0 -or $baseReleaseSha -notmatch '^[a-f0-9]{40}$') { throw 'Unable to resolve bingo-dev base release.' }
    & git cat-file -e "$baseReleaseSha^{commit}"
    if ($LASTEXITCODE -ne 0) { throw "Base release $baseReleaseSha is not present locally." }
    & git merge-base --is-ancestor $baseReleaseSha $assetSha
    if ($LASTEXITCODE -ne 0) { throw 'HEAD is not descended from the deployed bingo-dev release; use a full image deployment.' }
    $currentAssetSha = (& ssh $SshTarget "if test -f /home/bingo/apps/xboardme-pre-online/fast-theme-releases/current.env; then source /home/bingo/apps/xboardme-pre-online/fast-theme-releases/current.env; printf '%s' `"`$CURRENT_ASSET_SHA`"; else printf '%s' '$baseReleaseSha'; fi").Trim()
    if ($LASTEXITCODE -ne 0 -or $currentAssetSha -notmatch '^[a-f0-9]{40}$') { throw 'Unable to resolve the current bingo-dev asset revision.' }
    if ($currentAssetSha -eq $assetSha) {
        Write-Output "PREONLINE_THEME_FAST=NOOP asset_sha=$assetSha"
        exit 0
    }

    $allowedPatterns = @(
        '^theme/Xboard/',
        '^tests/',
        '^\.github/scripts/deploy-preonline-theme-fast\.(ps1|sh)$',
        '^\.github/scripts/rollback-preonline-theme-fast\.sh$'
    )
    $changedFiles = @(& git diff --name-only $baseReleaseSha $assetSha)
    $disallowed = @($changedFiles | Where-Object {
        $path = $_
        -not ($allowedPatterns | Where-Object { $path -match $_ })
    })
    if ($disallowed.Count -gt 0) {
        throw "Fast deployment refused non-theme changes: $($disallowed -join ', ')"
    }
    if (-not $Force -and -not ($changedFiles | Where-Object { $_ -match '^theme/Xboard/' })) {
        Write-Output "PREONLINE_THEME_FAST=NO_THEME_CHANGES asset_sha=$assetSha"
        exit 0
    }

    $testTimer = [Diagnostics.Stopwatch]::StartNew()
    $javascriptTests = @(Get-ChildItem 'tests/JavaScript/distributor*.test.js' | ForEach-Object { $_.FullName })
    & node --test @javascriptTests
    if ($LASTEXITCODE -ne 0) { throw 'Distributor JavaScript tests failed.' }
    & php vendor/bin/phpunit tests/Feature/ThemeAssetReleaseIntegrityTest.php tests/Feature/PreOnlineFastThemeDeploymentTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Fast theme deployment tests failed.' }
    Get-ChildItem 'theme/Xboard/assets/*.js' | ForEach-Object {
        & node --check $_.FullName
        if ($LASTEXITCODE -ne 0) { throw "JavaScript syntax check failed: $($_.Name)" }
    }
    & git diff --check $baseReleaseSha $assetSha
    if ($LASTEXITCODE -ne 0) { throw 'Git whitespace validation failed.' }
    $testTimer.Stop()

    $temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) "xboardme-theme-$assetSha"
    $resolvedTempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    $resolvedWork = [IO.Path]::GetFullPath($temporaryRoot)
    if (-not $resolvedWork.StartsWith($resolvedTempRoot, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'Unsafe temporary path.'
    }
    New-Item -ItemType Directory -Path (Join-Path $temporaryRoot 'payload/theme') -Force | Out-Null
    Copy-Item 'theme/Xboard' (Join-Path $temporaryRoot 'payload/theme/Xboard') -Recurse

    $dashboardPath = Join-Path $temporaryRoot 'payload/theme/Xboard/dashboard.blade.php'
    $dashboard = [IO.File]::ReadAllText($dashboardPath)
    $pattern = '(/theme/\{\{\$theme\}\}/assets/[^"?]+)\?v=\{\{\$version\}\}'
    $matches = [regex]::Matches($dashboard, $pattern)
    if ($matches.Count -ne 7) { throw "Expected 7 versioned Xboard theme references, found $($matches.Count)." }
    $dashboard = [regex]::Replace($dashboard, $pattern, "`$1?v=$assetSha")
    [IO.File]::WriteAllText($dashboardPath, $dashboard, [Text.UTF8Encoding]::new($false))

    $manifestPath = Join-Path $temporaryRoot 'payload/theme/Xboard/assets/release-manifest.json'
    & php .github/scripts/build-theme-asset-manifest.php $assetSha $manifestPath
    if ($LASTEXITCODE -ne 0) { throw 'Unable to build the theme asset manifest.' }

    $archive = Join-Path $temporaryRoot "theme-$assetSha.tar.gz"
    & tar -czf $archive -C (Join-Path $temporaryRoot 'payload') theme/Xboard
    if ($LASTEXITCODE -ne 0) { throw 'Unable to package theme assets.' }

    $incomingRoot = '/home/bingo/apps/xboardme-pre-online/incoming'
    & ssh $SshTarget "install -d -m 700 $incomingRoot"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to prepare bingo-dev incoming directory.' }
    & scp $archive "${SshTarget}:$incomingRoot/theme-$assetSha.tar.gz"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to upload the theme payload.' }
    & scp '.github/scripts/deploy-preonline-theme-fast.sh' "${SshTarget}:$incomingRoot/deploy-theme-$assetSha.sh"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to upload the deployment script.' }
    & scp '.github/scripts/rollback-preonline-theme-fast.sh' "${SshTarget}:$incomingRoot/rollback-theme-$assetSha.sh"
    if ($LASTEXITCODE -ne 0) { throw 'Unable to upload the rollback script.' }

    $deployTimer = [Diagnostics.Stopwatch]::StartNew()
    $remote = "chmod 700 $incomingRoot/deploy-theme-$assetSha.sh; ASSET_SHA='$assetSha' BASE_RELEASE_SHA='$baseReleaseSha' bash $incomingRoot/deploy-theme-$assetSha.sh"
    & ssh $SshTarget $remote
    if ($LASTEXITCODE -ne 0) { throw 'bingo-dev fast theme deployment failed.' }
    & ssh $SshTarget '/home/bingo/apps/xboardme-pre-online/smoke.sh'
    if ($LASTEXITCODE -ne 0) { throw 'Post-deployment smoke test failed.' }
    & ssh $SshTarget "curl -sS --fail --max-time 20 https://pre-online.openal.uk/ | grep -Fq '?v=$assetSha'"
    if ($LASTEXITCODE -ne 0) { throw 'Public asset revision verification failed.' }
    $deployTimer.Stop()
    $totalTimer.Stop()
    Write-Output "PREONLINE_THEME_FAST_TEST_SECONDS=$([Math]::Round($testTimer.Elapsed.TotalSeconds, 2))"
    Write-Output "PREONLINE_THEME_FAST_DEPLOY_SECONDS=$([Math]::Round($deployTimer.Elapsed.TotalSeconds, 2))"
    Write-Output "PREONLINE_THEME_FAST_TOTAL_SECONDS=$([Math]::Round($totalTimer.Elapsed.TotalSeconds, 2))"
    Write-Output "PREONLINE_THEME_FAST_WRAPPER=PASS asset_sha=$assetSha"
}
finally {
    Pop-Location
    if ($temporaryRoot -and (Test-Path -LiteralPath $temporaryRoot)) {
        $resolvedTempRoot = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
        $resolvedWork = [IO.Path]::GetFullPath($temporaryRoot)
        if ($resolvedWork.StartsWith($resolvedTempRoot, [StringComparison]::OrdinalIgnoreCase)) {
            Remove-Item -LiteralPath $resolvedWork -Recurse -Force
        }
    }
}
