<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Dedicated presentation conversion worker. Never bootstrap Moodle or run inside PHP-FPM.
 *
 * @package mod_icontent
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use Symfony\Component\Process\Process;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['workdir:', 'autoload:', 'office:', 'sandbox:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php presentation_worker.php --workdir=/site/temp/icontent-presentations " .
        "--autoload=/moodle/vendor/autoload.php --office=/usr/bin/libreoffice --sandbox=/usr/bin/bwrap\n";
    exit;
}
foreach (['workdir', 'autoload', 'office', 'sandbox'] as $option) {
    if (empty($options[$option]) || !is_string($options[$option]) || $options[$option][0] !== '/') {
        fwrite(STDERR, "A valid absolute --$option path is required.\n");
        exit(1);
    }
}
if (!is_executable($options['office']) || !is_executable($options['sandbox'])) {
    fwrite(STDERR, "LibreOffice and Bubblewrap must be executable.\n");
    exit(1);
}
require($options['autoload']);
if (!is_dir($options['workdir']) && !mkdir($options['workdir'], 0700, true)) {
    throw new RuntimeException('Cannot create the worker workspace.');
}
$base = realpath($options['workdir']);
if ($base === false || is_link($options['workdir'])) {
    throw new RuntimeException('The worker workspace must be a real directory.');
}
umask(0077);
$lock = fopen($base . '/worker.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another presentation worker already owns this workspace.');
}
$heartbeat = $base . '/worker.ready';
register_shutdown_function(static function() use ($heartbeat): void {
    if (is_file($heartbeat)) {
        unlink($heartbeat);
    }
});
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function(): void {
        exit;
    });
    pcntl_signal(SIGINT, static function(): void {
        exit;
    });
}

/**
 * Validate a job before exposing only its directory to the sandbox.
 *
 * @param string $directory Job directory.
 * @param array $options Trusted service arguments.
 */
function icontent_worker_convert(string $directory, array $options): void {
    $entries = array_diff(scandir($directory), ['.', '..', 'request.json']);
    if (count($entries) !== 1 || is_link($directory . '/request.json') ||
        filesize($directory . '/request.json') > 1024) {
        throw new RuntimeException('Invalid presentation job contents.');
    }
    $request = json_decode(file_get_contents($directory . '/request.json'), true, 8, JSON_THROW_ON_ERROR);
    $extension = $request['extension'] ?? '';
    if (!in_array($extension, ['pptx', 'ppt', 'odp', 'sxi'], true)) {
        throw new RuntimeException('Invalid presentation job format.');
    }
    $source = $directory . '/presentation.' . $extension;
    if (realpath($source) !== $source || !is_file($source) || filesize($source) > 52428800) {
        throw new RuntimeException('Invalid presentation source.');
    }
    if (!mkdir($directory . '/output', 0700)) {
        throw new RuntimeException('Cannot create conversion output directory.');
    }
    $command = [
        $options['sandbox'],
        '--die-with-parent', '--unshare-all', '--new-session', '--clearenv',
        '--ro-bind', '/usr', '/usr',
        '--ro-bind', '/etc/fonts', '/etc/fonts',
        '--ro-bind', '/etc/ld.so.cache', '/etc/ld.so.cache',
        '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
        '--bind', $directory, '/work',
        '--setenv', 'HOME', '/work', '--setenv', 'XDG_RUNTIME_DIR', '/tmp',
        '--setenv', 'PATH', '/usr/bin:/bin', '--setenv', 'LANG', 'C.UTF-8',
    ];
    foreach (['/lib', '/lib64'] as $path) {
        if (file_exists($path)) {
            array_push($command, '--ro-bind', $path, $path);
        }
    }
    array_push(
        $command, $options['office'], '-env:UserInstallation=file:///work/profile',
        '--headless', '--nologo', '--nodefault', '--nofirststartwizard',
        '--convert-to', 'odp:impress8', '--outdir', '/work/output', '/work/presentation.' . $extension
    );
    $process = new Process($command);
    $process->setTimeout(120);
    $diagnostics = '';
    $process->start(static function(string $type, string $buffer) use (&$diagnostics, $process): void {
        $diagnostics = substr($diagnostics . $buffer, -8192);
        $process->clearOutput();
        $process->clearErrorOutput();
    });
    while ($process->isRunning()) {
        if (!touch($options['workdir'] . '/worker.ready')) {
            $process->stop(1);
            throw new RuntimeException('Cannot update the worker heartbeat during conversion.');
        }
        $process->checkTimeout();
        usleep(200000);
    }
    $converted = $directory . '/output/presentation.odp';
    if (!$process->isSuccessful() || !is_file($converted) || filesize($converted) > 52428800) {
        throw new RuntimeException('Sandbox conversion failed: ' . $diagnostics);
    }
}

while (true) {
    if (!touch($heartbeat)) {
        throw new RuntimeException('Cannot update the worker heartbeat.');
    }
    foreach (new DirectoryIterator($base) as $entry) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $entry->getFilename()) || !$entry->isDir() || $entry->isLink()) {
            continue;
        }
        $directory = $base . '/' . $entry->getFilename();
        if (!is_file($directory . '/request.json') || file_exists($directory . '/result.json')) {
            continue;
        }
        try {
            icontent_worker_convert($directory, $options);
            $result = ['success' => true];
        } catch (Throwable $exception) {
            $detail = substr($exception->getMessage(), 0, 8192);
            fwrite(STDERR, $entry->getFilename() . ': ' . $detail . "\n");
            $result = ['success' => false, 'detail' => $detail];
        }
        if (is_dir($directory)) {
            if (file_put_contents($directory . '/result.tmp', json_encode($result, JSON_THROW_ON_ERROR)) === false ||
                !rename($directory . '/result.tmp', $directory . '/result.json')) {
                throw new RuntimeException('Cannot publish the presentation conversion result.');
            }
        }
    }
    usleep(200000);
}
