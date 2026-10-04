# Runs Job Finder on this PC at http://jobfinder.localhost:8080 (its own name, so password managers keep it apart).
# Stop it with Ctrl+C.
$ext = Join-Path (Split-Path (Get-Command php).Source) 'ext'
Start-Process 'http://jobfinder.localhost:8080/login'
php -d "extension_dir=$ext" -d extension=pdo_sqlite -d max_execution_time=120 -S 127.0.0.1:8080 -t $PSScriptRoot (Join-Path $PSScriptRoot 'app.php')
