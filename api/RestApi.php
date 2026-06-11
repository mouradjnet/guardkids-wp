<?php

declare(strict_types=1);

namespace GuardKids\Api;

use GuardKids\Api\Controllers\CategoryController;
use GuardKids\Api\Controllers\ChildController;
use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Api\Controllers\GuardianController;
use GuardKids\Api\Controllers\LicenseController;
use GuardKids\Api\Controllers\LocationController;
use GuardKids\Api\Controllers\ReportsController;
use GuardKids\Api\Controllers\RequestController;
use GuardKids\Api\Controllers\SafeZoneController;
use GuardKids\Api\Controllers\SettingsController;
use GuardKids\Api\Controllers\SiteController;
use GuardKids\Auth\ChildAuth;

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
        $this->registerChildSelfRoutes();
        $this->registerReportsRoutes();
        $this->registerLocationsRoutes();
        $this->registerSafeZonesRoutes();
        $this->registerLicenseRoutes();
        $this->registerGuardiansRoutes();
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

        register_rest_route(self::NAMESPACE, '/children/(?P<id>\d+)/pair', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'issueDeviceToken'],
            'permission_callback' => [self::class, 'requireManage'],
            'args'                => [
                'label' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/children/(?P<id>\d+)/pause', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'pause'],
            'permission_callback' => [self::class, 'requireManage'],
        ]);

        register_rest_route(self::NAMESPACE, '/children/(?P<id>\d+)/resume', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'resume'],
            'permission_callback' => [self::class, 'requireManage'],
        ]);
    }

    private function registerChildSelfRoutes(): void
    {
        $controller = new ChildSelfController();
        $requireToken = (new ChildAuth())->requireToken();

        register_rest_route(self::NAMESPACE, '/child/me', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'me'],
            'permission_callback' => $requireToken,
        ]);

        register_rest_route(self::NAMESPACE, '/child/requests', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'requestsIndex'],
                'permission_callback' => $requireToken,
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'requestsCreate'],
                'permission_callback' => $requireToken,
                'args'                => $controller->createArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/child/events', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'eventsCreate'],
            'permission_callback' => $requireToken,
            'args'                => $controller->createEventsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/child/location', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'reportLocation'],
            'permission_callback' => $requireToken,
            'args'                => $controller->createLocationArgs(),
        ]);
    }

    private function registerLocationsRoutes(): void
    {
        $controller = new LocationController();

        register_rest_route(self::NAMESPACE, '/locations', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireManage'],
            'args'                => [
                'child_id' => ['type' => 'integer', 'required' => true],
                'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 1],
            ],
        ]);
    }

    private function registerSafeZonesRoutes(): void
    {
        $controller = new SafeZoneController();

        register_rest_route(self::NAMESPACE, '/safe-zones', [
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

        register_rest_route(self::NAMESPACE, '/safe-zones/(?P<id>\d+)', [
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

    private function registerReportsRoutes(): void
    {
        $controller = new ReportsController();

        register_rest_route(self::NAMESPACE, '/reports', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireManage'],
            'args'                => [
                'range' => [
                    'type'    => 'string',
                    'enum'    => ['week', 'month'],
                    'default' => 'week',
                ],
                'child_id' => [
                    'type' => 'integer',
                ],
            ],
        ]);
    }

    private function registerLicenseRoutes(): void
    {
        $controller = new LicenseController();

        register_rest_route(self::NAMESPACE, '/license', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireManage'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'activate'],
                'permission_callback' => [self::class, 'requireManage'],
                'args'                => $controller->activateArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'deactivate'],
                'permission_callback' => [self::class, 'requireManage'],
            ],
        ]);
    }

    private function registerGuardiansRoutes(): void
    {
        $controller = new GuardianController();

        register_rest_route(self::NAMESPACE, '/guardians', [
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

        register_rest_route(self::NAMESPACE, '/guardians/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'updateRole'],
                'permission_callback' => [self::class, 'requireManage'],
                'args'                => $controller->updateRoleArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'destroy'],
                'permission_callback' => [self::class, 'requireManage'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/guardians/(?P<id>\d+)/activate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'activate'],
            'permission_callback' => [self::class, 'requireManage'],
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
