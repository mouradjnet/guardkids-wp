<?php

declare(strict_types=1);

namespace GuardKids;

/**
 * Autoloader PSR-4 self-contained do GuardKids WP.
 *
 * O plugin não depende do Composer em runtime: este autoloader cobre todas
 * as classes do plugin. O Composer é usado apenas em desenvolvimento (PHPUnit).
 *
 * Mapeia múltiplos roots PSR-4 para preservar a árvore de pastas do projeto:
 *   GuardKids\Api\…       → api/
 *   GuardKids\Database\…  → database/
 *   GuardKids\…           → includes/
 */
final class Autoloader
{
    /**
     * Prefixo de namespace → diretório base, do mais específico ao mais genérico.
     *
     * @var array<string, string>
     */
    private array $prefixes;

    public function __construct()
    {
        $this->prefixes = [
            'GuardKids\\Api\\'      => GUARDKIDS_DIR . 'api/',
            'GuardKids\\Database\\' => GUARDKIDS_DIR . 'database/',
            'GuardKids\\'           => GUARDKIDS_DIR . 'includes/',
        ];
    }

    /**
     * Registra o autoloader na pilha do SPL.
     */
    public function register(): void
    {
        spl_autoload_register([$this, 'load']);
    }

    /**
     * Resolve e carrega o arquivo da classe, se ela pertencer ao namespace GuardKids.
     */
    public function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

            if (is_readable($file)) {
                require $file;
            }

            return;
        }
    }
}
