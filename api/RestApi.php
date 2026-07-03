<?php

declare(strict_types=1);

namespace GuardKids\Api;

use GuardKids\Api\Controllers\CategoryController;
use GuardKids\Api\Controllers\ChildController;
use GuardKids\Api\Controllers\ChildSelfController;
use GuardKids\Api\Controllers\CompanionController;
use GuardKids\Api\Controllers\GuardianController;
use GuardKids\Api\Controllers\LicenseController;
use GuardKids\Api\Controllers\LocationController;
use GuardKids\Api\Controllers\ReportsController;
use GuardKids\Api\Controllers\RequestController;
use GuardKids\Api\Controllers\SafeZoneController;
use GuardKids\Api\Controllers\SecurityController;
use GuardKids\Api\Controllers\SettingsController;
use GuardKids\Api\Controllers\SiteController;
use GuardKids\Api\Controllers\SessionsController;
use GuardKids\Api\Controllers\TwoFactorController;
use GuardKids\Api\Controllers\MeController;
use GuardKids\Api\Controllers\PrivacyController;
use GuardKids\Api\Controllers\ContentController;
use GuardKids\Api\Controllers\GamificationController;
use GuardKids\Api\Controllers\MissionController;
use GuardKids\Api\Controllers\MedalController;
use GuardKids\Api\Controllers\RewardController;
use GuardKids\Api\Controllers\RedemptionController;
use GuardKids\Auth\ChildAuth;
use GuardKids\Auth\GuardianAuth;

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
        $this->registerSecurityRoutes();
        $this->registerTwoFactorRoutes();
        $this->registerSessionsRoutes();
        $this->registerChildSelfRoutes();
        $this->registerReportsRoutes();
        $this->registerLocationsRoutes();
        $this->registerSafeZonesRoutes();
        $this->registerLicenseRoutes();
        $this->registerGuardiansRoutes();
        $this->registerMeRoute();
        $this->registerPrivacyRoutes();
        $this->registerCompanionRoutes();
        $this->registerContentRoutes();
        $this->registerGamificationRoutes();
        $this->registerRewardsRoutes();
    }

    private function registerGamificationRoutes(): void
    {
        $controller = new GamificationController();

        register_rest_route(self::NAMESPACE, '/child/progression', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'childProgression'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);

        register_rest_route(self::NAMESPACE, '/progression', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'progression'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        $missions = new MissionController();
        register_rest_route(self::NAMESPACE, '/child/missions', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$missions, 'childMissions'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);

        $medals = new MedalController();
        register_rest_route(self::NAMESPACE, '/child/medals', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$medals, 'childMedals'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);
    }

    private function registerRewardsRoutes(): void
    {
        $rewards     = new RewardController();
        $redemptions = new RedemptionController();

        register_rest_route(self::NAMESPACE, '/rewards', [
            ['methods' => \WP_REST_Server::READABLE,  'callback' => [$rewards, 'index'],  'permission_callback' => [self::class, 'requireAdmin']],
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$rewards, 'create'], 'permission_callback' => [self::class, 'requireAdmin']],
        ]);
        register_rest_route(self::NAMESPACE, '/rewards/(?P<id>\d+)', [
            ['methods' => \WP_REST_Server::EDITABLE,  'callback' => [$rewards, 'update'],  'permission_callback' => [self::class, 'requireAdmin']],
            ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$rewards, 'destroy'], 'permission_callback' => [self::class, 'requireAdmin']],
        ]);
        register_rest_route(self::NAMESPACE, '/child/rewards', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$rewards, 'childRewards'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);

        register_rest_route(self::NAMESPACE, '/child/redemptions', [
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$redemptions, 'childCreate'], 'permission_callback' => (new ChildAuth())->requireToken()],
            ['methods' => \WP_REST_Server::READABLE,  'callback' => [$redemptions, 'childIndex'],  'permission_callback' => (new ChildAuth())->requireToken()],
        ]);
        register_rest_route(self::NAMESPACE, '/redemptions', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$redemptions, 'index'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
        register_rest_route(self::NAMESPACE, '/redemptions/(?P<id>\d+)/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$redemptions, 'approve'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
        register_rest_route(self::NAMESPACE, '/redemptions/(?P<id>\d+)/deny', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$redemptions, 'deny'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
    }

    private function registerContentRoutes(): void
    {
        $controller = new ContentController();

        $adminGet = static fn (string $path, string $cb) => register_rest_route(self::NAMESPACE, $path, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, $cb],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
        $adminGet('/content/categories', 'categories');
        $adminGet('/content', 'listContents');
        $adminGet('/content/favorites', 'favoritesList');
        $adminGet('/content/recommendations', 'recommendationsList');
        $adminGet('/content/summary', 'summary');
        $adminGet('/content/analytics', 'analytics');

        register_rest_route(self::NAMESPACE, '/content/recommendations', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'createRecommendation'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/content/recommendations/reorder', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'reorderRecommendations'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/content/recommendations/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'updateRecommendation'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'deleteRecommendation'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/content/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'getContent'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'updateContent'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'deleteContent'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/content/favorites', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'addFavorite'],
            'permission_callback' => (new ChildAuth())->requireToken(),
        ]);

        $token = (new ChildAuth())->requireToken();
        register_rest_route(self::NAMESPACE, '/child/library', [
            'methods' => \WP_REST_Server::READABLE, 'callback' => [$controller, 'childLibrary'], 'permission_callback' => $token,
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/categories', [
            'methods' => \WP_REST_Server::READABLE, 'callback' => [$controller, 'childLibraryCategories'], 'permission_callback' => $token,
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/recommendations', [
            'methods' => \WP_REST_Server::READABLE, 'callback' => [$controller, 'childRecommendations'], 'permission_callback' => $token,
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/favorites', [
            ['methods' => \WP_REST_Server::READABLE, 'callback' => [$controller, 'childFavorites'], 'permission_callback' => $token],
            ['methods' => \WP_REST_Server::CREATABLE, 'callback' => [$controller, 'childAddFavorite'], 'permission_callback' => $token],
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/favorites/(?P<contentId>\d+)', [
            'methods' => \WP_REST_Server::DELETABLE, 'callback' => [$controller, 'childRemoveFavorite'], 'permission_callback' => $token,
        ]);
        register_rest_route(self::NAMESPACE, '/child/library/history', [
            'methods' => \WP_REST_Server::CREATABLE, 'callback' => [$controller, 'childHistory'], 'permission_callback' => $token,
        ]);
    }

    private function registerPrivacyRoutes(): void
    {
        $controller = new PrivacyController();

        register_rest_route(self::NAMESPACE, '/privacy/export', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'export'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/privacy/clear-history', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'clearHistory'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/privacy/delete-all', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'deleteAll'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
    }

    private function registerCompanionRoutes(): void
    {
        $controller = new CompanionController();

        register_rest_route(self::NAMESPACE, '/protection-mode', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'getMode'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'setMode'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->setModeArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/companion/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'status'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => $controller->statusArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/companion/pair', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'pair'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => $controller->pairArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/companion/enroll', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'enroll'],
            // Auth via pairing token no header — validado dentro do handler.
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/companion/sync', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'sync'],
            // Auth via token de sessão no header — não usar nonce admin.
            'permission_callback' => '__return_true',
            'args'                => $controller->syncArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/companion/heartbeat', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'heartbeat'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/companion/verify-pin', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'verifyPin'],
            'permission_callback' => '__return_true',
            'args'                => $controller->verifyPinArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/companion/blocked-apps', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'setBlockedApps'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => $controller->setBlockedAppsArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/companion/request-site', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'requestSite'],
            'permission_callback' => '__return_true',
            'args'                => $controller->requestSiteArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/companion/revoke', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'revoke'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => $controller->revokeArgs(),
        ]);
    }

    /**
     * permission_callback — exige role efetiva `admin` no guardkids
     * (WP `manage_options` OU guardian com role=admin status=active).
     */
    public static function requireAdmin(): bool
    {
        return GuardianAuth::isAdmin();
    }

    /**
     * permission_callback — admin ou collaborator com guardian ativo.
     * Usado nas rotas que collaborator precisa pra fazer Approvals
     * (GET /children, GET /requests, POST approve/deny).
     */
    public static function requireCollaboratorOrAbove(): bool
    {
        return GuardianAuth::isCollaboratorOrAbove();
    }

    private function registerChildrenRoutes(): void
    {
        $controller = new ChildController();

        register_rest_route(self::NAMESPACE, '/children', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireCollaboratorOrAbove'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'create'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->createArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/children/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'show'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'update'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->updateArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'destroy'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/children/(?P<id>\d+)/pair', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'issueDeviceToken'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => [
                'label' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/children/(?P<id>\d+)/pause', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'pause'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/children/(?P<id>\d+)/resume', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'resume'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
    }

    private function registerSecurityRoutes(): void
    {
        $controller = new SecurityController();

        register_rest_route(self::NAMESPACE, '/security/pin', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'status'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'setPin'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->setPinArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'clearPin'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);
    }

    private function registerTwoFactorRoutes(): void
    {
        $controller = new TwoFactorController();

        register_rest_route(self::NAMESPACE, '/security/2fa', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'status'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'disable'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->codeArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/security/2fa/setup', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'setup'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/security/2fa/activate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'activate'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => $controller->codeArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/security/2fa/recovery-codes', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'regenerateRecovery'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => $controller->codeArgs(),
        ]);
    }

    private function registerSessionsRoutes(): void
    {
        $controller = new SessionsController();

        register_rest_route(self::NAMESPACE, '/security/sessions', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/security/sessions/destroy-others', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'destroyOthers'],
            'permission_callback' => [self::class, 'requireAdmin'],
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

        register_rest_route(self::NAMESPACE, '/child/security/pin/verify', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'verifyPin'],
            'permission_callback' => $requireToken,
            'args'                => $controller->verifyPinArgs(),
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

        register_rest_route(self::NAMESPACE, '/child/sites', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'sitesIndex'],
            'permission_callback' => $requireToken,
        ]);

        register_rest_route(self::NAMESPACE, '/child/notifications', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'notificationsIndex'],
            'permission_callback' => $requireToken,
        ]);

        register_rest_route(self::NAMESPACE, '/child/notifications/read', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'notificationsRead'],
            'permission_callback' => $requireToken,
        ]);

        register_rest_route(self::NAMESPACE, '/child/push/key', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'pushKey'],
            'permission_callback' => $requireToken,
        ]);

        register_rest_route(self::NAMESPACE, '/child/push/subscribe', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'pushSubscribe'],
            'permission_callback' => $requireToken,
        ]);

        register_rest_route(self::NAMESPACE, '/child/push/unsubscribe', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'pushUnsubscribe'],
            'permission_callback' => $requireToken,
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
            'permission_callback' => [self::class, 'requireAdmin'],
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
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'create'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->createArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/safe-zones/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'update'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->updateArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'destroy'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);
    }

    private function registerRequestsRoutes(): void
    {
        $controller = new RequestController();

        register_rest_route(self::NAMESPACE, '/requests', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireCollaboratorOrAbove'],
            'args'                => [
                'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'denied', 'all']],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/requests/(?P<id>\d+)/approve', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'approve'],
            'permission_callback' => [self::class, 'requireCollaboratorOrAbove'],
        ]);

        register_rest_route(self::NAMESPACE, '/requests/(?P<id>\d+)/deny', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'deny'],
            'permission_callback' => [self::class, 'requireCollaboratorOrAbove'],
        ]);
    }

    private function registerSitesRoutes(): void
    {
        $controller = new SiteController();

        register_rest_route(self::NAMESPACE, '/sites', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => [
                    'list' => ['type' => 'string', 'enum' => ['whitelist', 'blacklist', 'all']],
                ],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'create'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->createArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/sites/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [$controller, 'destroy'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
    }

    private function registerCategoriesRoutes(): void
    {
        $controller = new CategoryController();

        register_rest_route(self::NAMESPACE, '/categories', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/categories/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [$controller, 'update'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => $controller->updateArgs(),
        ]);
    }

    private function registerReportsRoutes(): void
    {
        $controller = new ReportsController();

        register_rest_route(self::NAMESPACE, '/reports', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => [self::class, 'requireAdmin'],
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

        register_rest_route(self::NAMESPACE, '/blocks/recent', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'recentBlocks'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => [
                'limit' => [
                    'type'    => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/usage/hourly', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'usageHourly'],
            'permission_callback' => [self::class, 'requireAdmin'],
            'args'                => [
                'child_id' => [
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ],
                'date' => [
                    'type'    => 'string',
                    'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
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
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'activate'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->activateArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'deactivate'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);
    }

    private function registerMeRoute(): void
    {
        $controller = new MeController();
        register_rest_route(self::NAMESPACE, '/me', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$controller, 'index'],
            'permission_callback' => '__return_true',
        ]);
    }

    private function registerGuardiansRoutes(): void
    {
        $controller = new GuardianController();

        register_rest_route(self::NAMESPACE, '/guardians', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$controller, 'create'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->createArgs(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/guardians/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'updateRole'],
                'permission_callback' => [self::class, 'requireAdmin'],
                'args'                => $controller->updateRoleArgs(),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$controller, 'destroy'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/guardians/(?P<id>\d+)/activate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'activate'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);

        register_rest_route(self::NAMESPACE, '/guardians/(?P<id>\d+)/resend', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$controller, 'resend'],
            'permission_callback' => [self::class, 'requireAdmin'],
        ]);
    }

    private function registerSettingsRoutes(): void
    {
        $controller = new SettingsController();

        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$controller, 'index'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [$controller, 'update'],
                'permission_callback' => [self::class, 'requireAdmin'],
            ],
        ]);
    }
}
