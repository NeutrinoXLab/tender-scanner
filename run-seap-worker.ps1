param(
    [Parameter(Mandatory = $true)]
    [string] $PhpPath
)

$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw 'Specify the absolute path to the PHP 8.3 executable.'
}

Push-Location $PSScriptRoot
try {
    & $PhpPath (Join-Path $PSScriptRoot 'artisan') seap:worker --no-interaction
    $workerExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}
exit $workerExitCode
