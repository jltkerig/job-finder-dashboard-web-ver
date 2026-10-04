<?php
// Jobs saved by the Web Job Scraper extension. On the desktop they arrive as files in Downloads, which a web
// server cannot see; on the web the extension will send them here instead. Until it does, nothing is waiting.

declare(strict_types=1);

if ($path === '/captures/pending') {
    json_out(['count' => 0, 'jobs' => 0, 'from' => '', 'to' => '', 'signature' => '']);
}
api_error('E2121', 'Importing captured jobs is not available on the web version yet.');
