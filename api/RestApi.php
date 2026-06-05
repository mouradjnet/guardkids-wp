<?php

declare(strict_types=1);

namespace GuardKids\Api;

use GuardKids\Api\Controllers\CategoryController;
use GuardKids\Api\Controllers\ChildController;
use GuardKids\Api\Controllers\RequestController;
use GuardKids\Api\Controllers\SettingsController;
use GuardKids\Api\Controllers\SiteController;

/**
 * Registra rotas do namespace `guardkids/v1`.
 *
 * Auth: nonce do WordPress (X-WP-Nonce) — `current_user_can('manage_options')`.
 * O cliente React do plugin recebe o nonce via wp_localize_script no admin.
 */
final class RestApi
{
    public const NAMESPACE = 'guardkids/v1';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $this->registerChildrenRoutes();
        $this->registerRequestsRoutes();
        $this->registerSitesRoutes();
        $this->registerCategoriesRoutes();
        $this->registerSettingsRoutes();
    }

    /**
     * permission_callback padrão — qualquer rota administrativa.
     */
    public static function requireManage(): bool
    {
        return current_user_can('manage_options');
    }

    private function registerChildrenRoutes(): void
    {
        $controller = new ChildController();

        register_rest_route(self::NAMESPACE, '/children', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireManage'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'create'],
                'permission_callback' => [self::class, 'requireManage'],
                'args'                => $controller->createArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/children/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'show'],
                'permission_callback' => [self::class, 'requireManage'],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'update'],
                'permission_callback' => [self::class, 'requireManage'],
                'args'                => $controller->updateArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'destroy'],
                'permission_callback' => [self::class, 'requireManage'],
            ],
        ]);
    }

    private function registerRequestsRoutes(): void
    {
        $controller = new RequestController();

        register_rest_route(self::NAMESPACE, '/requests', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireManage'],
            'args'                => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'denied', 'all']],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/requests/(?P<id>\d+)/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'approve'],
            'permission_callback' => [self::class, 'requireManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/requests/(?P<id>\d+)/deny', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'deny'],
            'permission_callback' => [self::class, 'requireManage'],
        ]);
    }

    private function registerSitesRoutes(): void
    {
        $controller = new SiteController();

        register_rest_route(self::NAMESPACE, '/sites', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireManage'],
                'args'                => [
                    'list' => ['type' => 'string', 'enum' => ['whitelist', 'blacklist', 'all']],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'create'],
                'permission_callback' => [self::class, 'requireManage'],
                'args'                => $controller->createArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [$controller, 'destroy'],
            'permission_callback' => [self::class, 'requireManage'],
        ]);
    }

    private function registerCategoriesRoutes(): void
    {
        $controller = new CategoryController();

        register_rest_route(self::NAMESPACE, '/categories', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/categories/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [$controller, 'update'],
            'permission_callback' => [self::class, 'requireManage'],
            'args'                => $controller->updateArgs(),
        ]);
    }

    private function registerSettingsRoutes(): void
    {
        $controller = new SettingsController();

        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireManage'],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'update'],
                'permission_callback' => [self::class, 'requireManage'],
            ],
        ]);
    }
}
