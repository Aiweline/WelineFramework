# Replace ternary Weline.Api pattern with direct call
$files = Get-ChildItem -Path "e:\WelineFramework\DEV-workspace\app" -Recurse -Include *.phtml,*.js
foreach ($f in $files) {
    $content = Get-Content $f.FullName -Raw -ErrorAction SilentlyContinue
    if (-not $content -or $content -notmatch 'window\.Weline && window\.Weline\.Api') { continue }
    $orig = $content
    # request(url, opts) : fetch(url, opts)...
    $content = $content -replace '\(window\.Weline && window\.Weline\.Api \? window\.Weline\.Api\.request\(([^,]+),\s*([^)]+)\)\s*:\s*fetch\(\1,\s*\2\)\.then\(function\s*\(r\)\s*\{\s*return\s*r\.json\(\)\.then\(function\s*\(d\)\s*\{\s*return\s*\{\s*ok:\s*r\.ok,\s*data:\s*d\s*\};\s*\}\);\s*\}\)\)', 'window.Weline.Api.request($1, $2)'
    # get(url, ...) : fetch(url)...
    $content = $content -replace '\(window\.Weline && window\.Weline\.Api \? window\.Weline\.Api\.get\(([^,]+),\s*([^)]+)\)\s*:\s*fetch\([^)]*\)\.then\(function\s*\(r\)\s*\{\s*return\s*r\.json\(\)\.then\(function\s*\(d\)\s*\{\s*return\s*\{\s*ok:\s*r\.ok,\s*data:\s*d\s*\};\s*\}\);\s*\}\)\)', 'window.Weline.Api.get($1, $2)'
    # await (window.Weline...
    $content = $content -replace 'await\s*\(window\.Weline && window\.Weline\.Api \? window\.Weline\.Api\.request\(([^,]+),\s*([^)]+)\)\s*:\s*fetch\([^)]+\)\.then\([^)]+\)\)', 'await window.Weline.Api.request($1, $2)'
    $content = $content -replace 'await\s*\(window\.Weline && window\.Weline\.Api \? window\.Weline\.Api\.get\(([^,]+),\s*([^)]+)\)\s*:\s*fetch\([^)]*\)\.then\([^)]+\)\)', 'await window.Weline.Api.get($1, $2)'
    if ($content -ne $orig) {
        Set-Content $f.FullName -Value $content -NoNewline
        Write-Host "Updated: $($f.FullName)"
    }
}
