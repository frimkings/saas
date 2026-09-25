# =============================================================
#  Eye Clinic - HTTP Load Test  (Apache Bench)
#
#  Usage:
#    powershell -NoProfile -ExecutionPolicy Bypass -File stress-test.ps1
# =============================================================

$base        = "http://eyeclinicproject.test"
$ab          = "C:\xampp\apache\bin\ab.exe"
$requests    = 300
$concurrency = 30

$routeLabels = @("Admin Dashboard", "Secretary Patients", "Admin Reports", "Doctor Queue", "Daily Cash Summary")
$routePaths  = @("/admin/dashboard", "/secretary/patients", "/admin/reports", "/doctor/patient-awaiting", "/admin/daily-cash-summary")

# ---- Verify ab.exe exists --------------------------------
if (-not (Test-Path $ab)) {
    Write-Host "FAIL: ab.exe not found at $ab" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "  ============================================================" -ForegroundColor Blue
Write-Host "    Eye Clinic - HTTP Load Test" -ForegroundColor Blue
Write-Host "    $requests requests / $concurrency concurrent" -ForegroundColor Blue
Write-Host "  ============================================================" -ForegroundColor Blue
Write-Host ""

# ---- Step 1: Get CSRF token ----------------------------------
Write-Host "  [1] Authenticating as admin@eyeclinic.com..." -ForegroundColor Cyan

try {
    $page  = Invoke-WebRequest "$base/login" -SessionVariable sess -UseBasicParsing
    $token = [regex]::Match($page.Content, 'name="_token"\s+value="([^"]+)"').Groups[1].Value

    if ([string]::IsNullOrEmpty($token)) {
        Write-Host "  WARN: CSRF token not found. Is the app running at $base ?" -ForegroundColor Yellow
        exit 1
    }
} catch {
    Write-Host "  FAIL: Cannot reach $base/login - is Apache running?" -ForegroundColor Red
    exit 1
}

# ---- Step 2: Login -------------------------------------------
try {
    Invoke-WebRequest "$base/login" -Method Post -WebSession $sess -UseBasicParsing -Body @{
        _token   = $token
        login    = "admin@eyeclinic.com"
        password = "password"
    } | Out-Null
} catch {
    Write-Host "  FAIL: Login POST failed - $_" -ForegroundColor Red
    exit 1
}

# ---- Step 3: Extract session cookie for ab ------------------
$cookieHeader = ($sess.Cookies.GetCookies($base) |
    ForEach-Object { "$($_.Name)=$($_.Value)" }) -join "; "

if ([string]::IsNullOrEmpty($cookieHeader)) {
    Write-Host "  FAIL: No session cookie returned - login may have failed." -ForegroundColor Red
    exit 1
}

Write-Host "      OK - session cookie obtained" -ForegroundColor Green
Write-Host ""

# ---- Step 4: Run ab against each route ----------------------
$results = @()

for ($i = 0; $i -lt $routeLabels.Count; $i++) {
    $label = $routeLabels[$i]
    $route = $routePaths[$i]
    $url   = "$base$route"

    Write-Host "  ---- $label ----------------------------------------" -ForegroundColor DarkCyan
    Write-Host "       $url" -ForegroundColor Gray

    # -l disables content-length mismatch check (expected for dynamic Livewire pages)
    $output = & $ab -n $requests -c $concurrency -l -C $cookieHeader $url 2>&1

    $rpsMatch    = [regex]::Match(($output -join "`n"), 'Requests per second:\s+([\d.]+)')
    $meanMatch   = [regex]::Match(($output -join "`n"), 'Time per request:\s+([\d.]+).*mean\)')
    $failMatch   = [regex]::Match(($output -join "`n"), 'Failed requests:\s+(\d+)')
    $p95Match    = [regex]::Match(($output -join "`n"), '\s+95\s+(\d+)')

    $rps    = if ($rpsMatch.Success)  { $rpsMatch.Groups[1].Value  } else { "n/a" }
    $mean   = if ($meanMatch.Success) { $meanMatch.Groups[1].Value } else { "n/a" }
    $failed = if ($failMatch.Success) { $failMatch.Groups[1].Value } else { "n/a" }
    $p95    = if ($p95Match.Success)  { $p95Match.Groups[1].Value  } else { "n/a" }

    $meanInt   = if ($mean   -ne "n/a") { [int][double]$mean   } else { 0 }
    $failedInt = if ($failed -ne "n/a") { [int]$failed          } else { 0 }

    $meanColor   = if ($meanInt -gt 2000) { "Red" } elseif ($meanInt -gt 1000) { "Yellow" } else { "Green" }
    $failedColor = if ($failedInt -gt 0)  { "Red" } else { "Green" }

    Write-Host ("       RPS:    {0,10} req/s" -f $rps)    -ForegroundColor Green
    Write-Host ("       Mean:   {0,10} ms"    -f $mean)   -ForegroundColor $meanColor
    Write-Host ("       p95:    {0,10} ms"    -f $p95)    -ForegroundColor Cyan
    Write-Host ("       Failed: {0,10}"       -f $failed) -ForegroundColor $failedColor
    Write-Host ""

    $results += [PSCustomObject]@{
        Route   = $label
        RPS     = $rps
        Mean_ms = $mean
        P95_ms  = $p95
        Failed  = $failed
    }
}

# ---- Summary -------------------------------------------------
Write-Host "  ============================================================" -ForegroundColor Blue
Write-Host "    Results Summary" -ForegroundColor Blue
Write-Host "  ============================================================" -ForegroundColor Blue
$results | Format-Table -AutoSize

Write-Host ""
Write-Host "  Thresholds:" -ForegroundColor White
Write-Host "    Mean < 500ms    Excellent" -ForegroundColor Green
Write-Host "    Mean 500-2000ms Acceptable" -ForegroundColor Yellow
Write-Host "    Mean > 2000ms   Investigate (N+1 query or missing index)" -ForegroundColor Red
Write-Host "    Failed > 0      Errors under load - check laravel.log" -ForegroundColor Red
Write-Host ""
