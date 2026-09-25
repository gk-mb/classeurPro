param([Parameter(Mandatory=$true)][string]$Directory)
$ErrorActionPreference = 'Stop'
$excel = $null
$beforeIds = @(Get-Process EXCEL -ErrorAction SilentlyContinue | ForEach-Object { $_.Id })
$ownedIds = @()
Add-Type -TypeDefinition 'using System; using System.Runtime.InteropServices; public static class ExcelTestWindow { [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr hWnd, out uint processId); }'
function Invoke-ExcelAction([scriptblock]$Action) {
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        try { return (& $Action) } catch {
            if ($attempt -eq 29 -or $_.Exception.Message -notmatch '800AC472|80010001|rejet|rejected') { throw }
            Start-Sleep -Milliseconds 250
        }
    }
}
try {
    $excel = New-Object -ComObject Excel.Application
    [uint32]$excelProcessId = 0
    [void][ExcelTestWindow]::GetWindowThreadProcessId([IntPtr]$excel.Hwnd, [ref]$excelProcessId)
    if ($excelProcessId -gt 0 -and $excelProcessId -notin $beforeIds) { $ownedIds = @($excelProcessId) }
    $excel.Visible = $false
    $excel.DisplayAlerts = $false
    $excel.EnableEvents = $false
    $excel.AutomationSecurity = 3
    foreach ($file in Get-ChildItem -LiteralPath $Directory -Filter '*.xlsx') {
        $book = $null
        try {
            # Ouverture normale, lecture seule, aucune actualisation des liens.
            $book = Invoke-ExcelAction { $excel.Workbooks.Open($file.FullName, 0, $true) }
            if (Invoke-ExcelAction { $book.RepairMode }) { throw "Excel a réparé le fichier $($file.Name)" }
            Write-Output "OK Excel sans réparation : $($file.Name)"
        } catch {
            Write-Output "ECHEC $($file.Name) : $($_.Exception.Message)"
            Add-Type -AssemblyName UIAutomationClient
            $windows = [Windows.Automation.AutomationElement]::RootElement.FindAll([Windows.Automation.TreeScope]::Children, [Windows.Automation.Condition]::TrueCondition)
            foreach ($window in $windows) {
                if ($window.Current.ProcessId -in $ownedIds) {
                    Write-Output ("Fenetre de test : " + $window.Current.Name)
                    $nodes = $window.FindAll([Windows.Automation.TreeScope]::Descendants, [Windows.Automation.Condition]::TrueCondition)
                    foreach ($node in $nodes) { if ($node.Current.ControlType.ProgrammaticName -eq 'ControlType.Text') { Write-Output $node.Current.Name } }
                }
            }
            throw
        } finally { if ($null -ne $book) { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($book) } }
    }
} finally {
    if ($null -ne $excel) {
        try { Invoke-ExcelAction { $excel.Quit() } } catch { Write-Warning 'Excel de test ne répond plus.' }
        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($excel)
    }
    # Ne ferme que les processus créés par ce test, jamais une session déjà ouverte.
    foreach ($processId in $ownedIds) { Stop-Process -Id $processId -ErrorAction SilentlyContinue }
}
