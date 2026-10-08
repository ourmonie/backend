$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8000/api/v1'

function Show($label, $json) {
    Write-Output "----- $label -----"
    Write-Output $json
    Write-Output ""
}

$login = Invoke-RestMethod -Uri "$base/auth/login" -Method Post `
    -ContentType 'application/json' `
    -Body '{"login":"member@ourmonie.test","password":"Password123!"}'

Show 'LOGIN (token + member)' (ConvertTo-Json $login.member -Depth 6)

$headers = @{ Authorization = "Bearer $($login.token)" }

$dash = Invoke-RestMethod -Uri "$base/dashboard" -Headers $headers
Show 'DASHBOARD balances' (ConvertTo-Json $dash.balances)
Show 'DASHBOARD growth' (ConvertTo-Json $dash.growth)
Show 'DASHBOARD allocation' (ConvertTo-Json $dash.allocation)
Show 'DASHBOARD membership' (ConvertTo-Json $dash.membership)
Show 'DASHBOARD rewards' (ConvertTo-Json $dash.rewards)
Write-Output "----- DASHBOARD recent_transactions ($($dash.recent_transactions.Count)) -----"
$dash.recent_transactions | Select-Object occurred_label, description, direction, amount, status | Format-Table -AutoSize | Out-String | Write-Output

$chart = Invoke-RestMethod -Uri "$base/dashboard/chart" -Headers $headers
Write-Output '----- CHART -----'
$chart.points | Format-Table -AutoSize | Out-String | Write-Output

$wallet = Invoke-RestMethod -Uri "$base/wallet" -Headers $headers
Show 'WALLET' (ConvertTo-Json $wallet -Depth 6)

$tx = Invoke-RestMethod -Uri "$base/transactions?per_page=10" -Headers $headers
Write-Output "----- TRANSACTIONS (total $($tx.meta.total)) -----"
$tx.data | Select-Object occurred_label, type_label, direction, amount | Format-Table -AutoSize | Out-String | Write-Output
Show 'TX summary' (ConvertTo-Json $tx.summary)

$savings = Invoke-RestMethod -Uri "$base/savings" -Headers $headers
Show 'SAVINGS totals' (ConvertTo-Json $savings.totals)

$rewards = Invoke-RestMethod -Uri "$base/rewards" -Headers $headers
Show 'REWARDS' (ConvertTo-Json ($rewards | Select-Object points_balance, cash_value, convertible, conversion_threshold, points_to_threshold, tier_multiplier))

$goals = Invoke-RestMethod -Uri "$base/savings/goals" -Headers $headers
Show 'GOALS' (ConvertTo-Json $goals.data -Depth 5)

$ref = Invoke-RestMethod -Uri "$base/referrals" -Headers $headers
Show 'REFERRALS' (ConvertTo-Json ($ref | Select-Object referral_code, referral_link, stats, reward_rule))

$notifs = Invoke-RestMethod -Uri "$base/notifications" -Headers $headers
Show 'NOTIFICATIONS' (ConvertTo-Json ($notifs | Select-Object unread, data))

$ann = Invoke-RestMethod -Uri "$base/announcements" -Headers $headers
Show 'ANNOUNCEMENTS' (ConvertTo-Json $ann.data)

# CORS preflight check
Write-Output '----- CORS PREFLIGHT -----'
try {
    $req = [System.Net.HttpWebRequest]::Create("$base/dashboard")
    $req.Method = 'OPTIONS'
    $req.Headers.Add('Origin', 'http://localhost:5173')
    $req.Headers.Add('Access-Control-Request-Method', 'GET')
    $resp = $req.GetResponse()
    Write-Output "Status: $([int]$resp.StatusCode)"
    foreach ($h in $resp.Headers.AllKeys) { if ($h -like 'Access-Control*') { Write-Output "$h : $($resp.Headers[$h])" } }
    $resp.Close()
} catch {
    Write-Output "preflight error: $($_.Exception.Message)"
    if ($_.Exception.Response) {
        foreach ($h in $_.Exception.Response.Headers.AllKeys) { if ($h -like 'Access-Control*') { Write-Output "$h : $($_.Exception.Response.Headers[$h])" } }
    }
}
