param(
    [string]$Action = "check"
)

$version = $env:TERRAFORM_VERSION
if ([string]::IsNullOrWhiteSpace($version)) {
    $version = "1.9.8"
}

$defaultDir = Join-Path $env:LOCALAPPDATA "Terraform"
$defaultExe = Join-Path $defaultDir "terraform.exe"
if (Test-Path $defaultExe) {
    $env:Path = $defaultDir + ";" + $env:Path
    $userPath = [Environment]::GetEnvironmentVariable("Path", "User")
    if ($userPath -notlike "*$defaultDir*") {
        $newPath = if ([string]::IsNullOrEmpty($userPath)) { $defaultDir } else { $userPath + ";" + $defaultDir }
        [Environment]::SetEnvironmentVariable("Path", $newPath, "User")
    }
}

$tfCmd = Get-Command terraform -ErrorAction SilentlyContinue
if ($null -ne $tfCmd) {
    Write-Output "INSTALLED"
    terraform -version | Select-Object -First 1
    exit 0
}

if ($Action -eq "check") {
    Write-Output "MISSING"
    Write-Output "terraform not found in PATH"
    exit 1
}

if ($Action -ne "install") {
    Write-Output "MISSING"
    Write-Output ("unknown action: " + $Action)
    exit 1
}

$arch = "amd64"
if (-not [Environment]::Is64BitOperatingSystem) {
    $arch = "386"
}

$url = "https://releases.hashicorp.com/terraform/$version/terraform_${version}_windows_${arch}.zip"
$destDir = Join-Path $env:LOCALAPPDATA "Terraform"
$zipFile = Join-Path $env:TEMP ("terraform_" + $version + ".zip")

try {
    New-Item -ItemType Directory -Force -Path $destDir | Out-Null
    Invoke-WebRequest -Uri $url -OutFile $zipFile -UseBasicParsing
    Expand-Archive -Path $zipFile -DestinationPath $destDir -Force
} catch {
    Write-Output "MISSING"
    Write-Output ("download or extract failed: " + $_.Exception.Message)
    exit 1
}

$exe = Join-Path $destDir "terraform.exe"
if (-not (Test-Path $exe)) {
    Write-Output "MISSING"
    Write-Output "terraform.exe not found after extraction"
    exit 1
}

$userPath = [Environment]::GetEnvironmentVariable("Path", "User")
if ($userPath -notlike "*$destDir*") {
    $newPath = if ([string]::IsNullOrEmpty($userPath)) { $destDir } else { $userPath + ";" + $destDir }
    [Environment]::SetEnvironmentVariable("Path", $newPath, "User")
}

$env:Path = $destDir + ";" + $env:Path

$tfCmd = Get-Command terraform -ErrorAction SilentlyContinue
if ($null -ne $tfCmd) {
    Write-Output "INSTALLED"
    terraform -version | Select-Object -First 1
    exit 0
}

Write-Output "MISSING"
Write-Output "terraform install failed"
exit 1
