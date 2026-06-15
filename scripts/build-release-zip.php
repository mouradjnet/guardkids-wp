<?php

declare(strict_types=1);

/**
 * Empacota o plugin pra distribuição (zip pronto pra wp-admin → instalar).
 *
 * Usa ZipArchive com forward-slashes nas entries — evita o bug histórico do
 * Compress-Archive/CreateFromDirectory do PowerShell no Windows que gera
 * paths com `\` e quebra a extração do WordPress.
 *
 * Uso:
 *   php scripts/build-release-zip.php [output.zip]
 *
 * Sem argumento: output em $HOME/OneDrive/Documentos/guardkids-wp/.
 */

$root    = dirname(__DIR__);
$version = '1.5.2';

$argvOut = $argv[1] ?? null;
$home    = getenv('USERPROFILE') ?: getenv('HOME') ?: $root;
$default = $home . '/OneDrive/Documentos/guardkids-wp/guardkids-wp-' . $version . '.zip';
$output  = $argvOut !== null ? $argvOut : str_replace('\\', '/', $default);

$outDir = dirname($output);
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
if (file_exists($output)) {
    unlink($output);
}

$excludePrefixes = [
    '.git/', '.github/', '.idea/', '.vscode/', '.phpunit.cache/',
    'tests/', 'docs/', 'vendor/', 'scripts/',
    'public/app-parent/src/', 'public/app-parent/node_modules/',
    'public/app-parent/test/', 'public/app-parent/public/',
    'public/app-child/src/', 'public/app-child/node_modules/',
    'public/app-child/test/', 'public/app-child/public/',
    'public/app-child/tests/',
];
$excludeFiles = [
    '.gitignore', '.gitattributes', '.env', '.env.local', '.env.example',
    'composer.json', 'composer.lock',
    'phpunit.xml', 'phpunit.xml.dist',
    'phpunit-integration.xml', 'phpunit-integration.xml.dist',
    'docker-compose.test.yml', 'docker-compose.yml',
    'public/README.md',
    'public/app-parent/package.json', 'public/app-parent/package-lock.json',
    'public/app-parent/pnpm-lock.yaml', 'public/app-parent/vite.config.ts',
    'public/app-parent/vite.config.js', 'public/app-parent/tsconfig.json',
    'public/app-parent/tsconfig.node.json', 'public/app-parent/tailwind.config.ts',
    'public/app-parent/tailwind.config.js', 'public/app-parent/postcss.config.js',
    'public/app-parent/index.html', 'public/app-parent/.gitignore',
    'public/app-child/package.json', 'public/app-child/package-lock.json',
    'public/app-child/pnpm-lock.yaml', 'public/app-child/vite.config.ts',
    'public/app-child/vite.config.js', 'public/app-child/tsconfig.json',
    'public/app-child/tsconfig.node.json', 'public/app-child/tailwind.config.ts',
    'public/app-child/tailwind.config.js', 'public/app-child/postcss.config.js',
    'public/app-child/index.html', 'public/app-child/.gitignore',
    'public/app-child/playwright.config.ts',
    'public/app-child/pwa-assets.config.ts',
];

function shouldExclude(string $relative, bool $isDir, array $prefixes, array $files): bool
{
    // Diretórios chegam sem trailing slash; comparamos `$relative/` pra casar com
    // prefixes (que são "dir/"). Sem isso, empty-dir entries de pastas excluídas
    // vazam no zip (caso `.git/` quando todos os filhos são pulados).
    $check = $isDir ? rtrim($relative, '/') . '/' : $relative;
    foreach ($prefixes as $prefix) {
        if (str_starts_with($check, $prefix)) {
            return true;
        }
    }
    if (in_array($relative, $files, true)) {
        return true;
    }
    $basename = basename($relative);
    if (in_array($basename, ['.DS_Store', 'Thumbs.db'], true)) {
        return true;
    }
    return false;
}

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Falha ao abrir zip pra escrita: {$output}\n");
    exit(1);
}

$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

$count = 0;
$skipped = 0;
foreach ($iter as $path => $info) {
    /** @var SplFileInfo $info */
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    if ($relative === '') {
        continue;
    }
    if (shouldExclude($relative, $info->isDir(), $excludePrefixes, $excludeFiles)) {
        $skipped++;
        continue;
    }
    // Entry com prefixo "guardkids-wp/" (assim o zip extrai numa pasta correta
    // em wp-content/plugins/).
    $entry = 'guardkids-wp/' . $relative;
    if ($info->isDir()) {
        $zip->addEmptyDir($entry);
    } elseif ($info->isFile()) {
        $zip->addFile($path, $entry);
        $count++;
    }
}

if (! $zip->close()) {
    fwrite(STDERR, "Falha ao fechar o zip.\n");
    exit(1);
}

$bytes = filesize($output);
echo "OK\n";
echo "  Output: {$output}\n";
echo "  Files:  {$count} (skipped {$skipped})\n";
echo "  Size:   " . number_format($bytes / 1024, 1) . " KB\n";
