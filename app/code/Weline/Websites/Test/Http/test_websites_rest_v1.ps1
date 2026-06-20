$BaseUrl = $env:WEBSITES_API_BASE_URL
if ([string]::IsNullOrWhiteSpace($BaseUrl)) {
    $BaseUrl = "http://127.0.0.1:9502/websites/rest/v1"
}

Write-Host "Using base url: $BaseUrl"

function Invoke-WebsitesApi {
    param(
        [string]$Path,
        [hashtable]$Body = @{}
    )

    $uri = "$BaseUrl/$Path"
    $jsonBody = $Body | ConvertTo-Json -Depth 8
    Write-Host "POST $uri"
    try {
        $response = Invoke-RestMethod -Method Post -Uri $uri -ContentType "application/json" -Body $jsonBody
        $response | ConvertTo-Json -Depth 8
    } catch {
        Write-Host "Request failed: $($_.Exception.Message)"
    }
    Write-Host "----------------------------------------"
}

# 1) domain_pool/list
Invoke-WebsitesApi -Path "domain_pool/list" -Body @{
    limit = 10
    grouped = $true
}

# 2) domain_pool/add
Invoke-WebsitesApi -Path "domain_pool/add" -Body @{
    domain = "api-smoke-demo.example.com"
    description = "rest v1 smoke"
}

# 3) domain_pool/delete
Invoke-WebsitesApi -Path "domain_pool/delete" -Body @{
    pool_id = 0
}

# 4) domain_registrar/check
Invoke-WebsitesApi -Path "domain_registrar/check" -Body @{
    account_id = 1
    domains = @("example-demo-check.com")
}

# 5) domain_registrar/purchase
Invoke-WebsitesApi -Path "domain_registrar/purchase" -Body @{
    account_id = 1
    domain = "example-demo-buy.com"
    years = 1
}

# 6) provisioning/start
Invoke-WebsitesApi -Path "provisioning/start" -Body @{
    domain = "example-demo-provision.com"
    registrar_account_id = 1
    skip_purchase = $true
}

# 7) provisioning/status
Invoke-WebsitesApi -Path "provisioning/status" -Body @{
    domain = "example-demo-provision.com"
}
