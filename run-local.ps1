# Runs Job Finder on this PC at http://jobfinder.localhost:8080 (its own name, so password managers keep it apart).
# Stop it with Ctrl+C.
$ext = Join-Path (Split-Path (Get-Command php).Source) 'ext'
Start-Process 'http://jobfinder.localhost:8080/login'
php -d "extension_dir=$ext" -d extension=pdo_sqlite -d max_execution_time=120 -d upload_max_filesize=12M -d post_max_size=14M -S 127.0.0.1:8080 -t $PSScriptRoot (Join-Path $PSScriptRoot 'app.php')
