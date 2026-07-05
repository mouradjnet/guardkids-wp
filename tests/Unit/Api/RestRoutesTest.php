<?php

declare(strict_types=1);

namespace GuardKids\Tests\Unit\Api;

use GuardKids\Api\Controllers\ContentController;
use GuardKids\Api\RestApi;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Smoke de rotas: garante que os handlers dos controllers estão de fato ligados
 * a uma rota REST. Nasceu do bug do create de conteúdo, que existia no controller
 * mas nunca foi registrado (POST /content ausente) — os testes de método não
 * pegam isso porque chamam o handler direto, sem passar pela rota.
 *
 * `register_rest_route` é stubado no bootstrap e grava cada registro em
 * $GLOBALS['gk_routes'].
 */
final class RestRoutesTest extends TestCase
{
    /** @var array<int, array{namespace:string, route:string, args:array}> */
    private array $routes = [];

    protected function setUp(): void
    {
        // Os construtores dos controllers instanciam repositories, que exigem
        // um $wpdb global (tipado, não-nulo). Não há query no registro de rotas.
        $GLOBALS['wpdb'] = new \wpdb();
        $GLOBALS['gk_routes'] = [];
        (new RestApi())->registerRoutes();
        $this->routes = $GLOBALS['gk_routes'];
    }

    /** Todos os pares "Classe::método" que aparecem como `callback` numa rota. */
    private function registeredHandlers(): array
    {
        $handlers = [];
        foreach ($this->routes as $r) {
            $endpoints = isset($r['args']['callback']) ? [$r['args']] : $r['args'];
            foreach ($endpoints as $ep) {
                $cb = $ep['callback'] ?? null;
                if (is_array($cb) && is_object($cb[0])) {
                    $handlers[get_class($cb[0]) . '::' . $cb[1]] = true;
                }
            }
        }
        return $handlers;
    }

    /** HTTP methods (string) registrados pra um `namespace/route` exato. */
    private function methodsFor(string $namespace, string $route): string
    {
        $out = [];
        foreach ($this->routes as $r) {
            if ($r['namespace'] !== $namespace || $r['route'] !== $route) {
                continue;
            }
            $endpoints = isset($r['args']['callback']) ? [$r['args']] : $r['args'];
            foreach ($endpoints as $ep) {
                $out[] = (string) ($ep['methods'] ?? '');
            }
        }
        return implode(',', $out);
    }

    public function testRegistersAtLeastOneRoute(): void
    {
        self::assertNotEmpty($this->routes, 'registerRoutes() não registrou nenhuma rota.');
    }

    /** Regressão direta do bug: POST /content -> createContent. */
    public function testContentCreateRouteIsRegistered(): void
    {
        self::assertArrayHasKey(
            ContentController::class . '::createContent',
            $this->registeredHandlers(),
            'POST /content (createContent) não está registrado — create de conteúdo fica quebrado.',
        );
        self::assertStringContainsString('POST', $this->methodsFor(RestApi::NAMESPACE, '/content'));
    }

    /**
     * Guarda a classe do bug em TODOS os controllers: todo método público é um
     * handler de endpoint (exceto o construtor e os helpers `*Args`, que só
     * devolvem o schema de `args` pro register_rest_route). Cada handler precisa
     * estar ligado a alguma rota — se alguém adicionar um e esquecer a rota,
     * este teste falha, seja em que controller for.
     */
    public function testEveryControllerHandlerHasARoute(): void
    {
        $handlers = $this->registeredHandlers();
        $orphans = [];

        foreach (glob(dirname(__DIR__, 3) . '/api/Controllers/*.php') as $file) {
            $class = 'GuardKids\\Api\\Controllers\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isConstructor() || $m->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                // `*Args` são helpers de schema, não endpoints.
                if (str_ends_with($m->getName(), 'Args')) {
                    continue;
                }
                if (!isset($handlers[$class . '::' . $m->getName()])) {
                    $orphans[] = $class . '::' . $m->getName();
                }
            }
        }

        self::assertSame([], $orphans, 'Handlers de controller sem rota REST: ' . implode(', ', $orphans));
    }
}
