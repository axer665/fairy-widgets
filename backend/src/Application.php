<?php

declare(strict_types=1);

namespace App;

use App\Controller\ApplicationController;
use App\Controller\AuthController;
use App\Controller\ModeratorController;
use App\Controller\TrackController;
use App\Controller\WidgetController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsTrackMiddleware;
use App\Middleware\JsonBodyMiddleware;
use App\Middleware\ModeratorAuthMiddleware;
use App\Middleware\RequestLogMiddleware;

final class Application
{
    public function __construct(
        private readonly Router $router,
        private readonly Database $db,
        /** @var array{jwt_secret:string,app_url:string} */
        private readonly array $config,
    ) {
    }

    public function registerRoutes(): void
    {
        $secret = $this->config['jwt_secret'];
        $appUrl = $this->config['app_url'];

        $json = new JsonBodyMiddleware();
        $log = new RequestLogMiddleware();
        $auth = new AuthMiddleware($secret);
        $modAuth = new ModeratorAuthMiddleware($secret);

        $authCtrl = new AuthController($this->db, $secret);
        $appCtrl = new ApplicationController($this->db, $appUrl);
        $modCtrl = new ModeratorController($this->db);
        $widgetCtrl = new WidgetController($this->db, $appUrl);
        $trackCtrl = new TrackController($this->db);

        $this->router->add('POST', '/api/register', $authCtrl->register(...), [$json, $log]);
        $this->router->add('POST', '/api/login', $authCtrl->login(...), [$json, $log]);

        $this->router->add('GET', '/api/me', $authCtrl->me(...), [$json, $auth, $log]);
        $this->router->add('GET', '/api/applications', $appCtrl->list(...), [$json, $auth, $log]);
        $this->router->add('POST', '/api/applications', $appCtrl->create(...), [$json, $auth, $log]);

        $this->router->add('GET', '/api/mod/applications', $modCtrl->list(...), [$json, $modAuth, $log]);
        $this->router->add(
            'PUT',
            '/api/mod/applications/{id}/approve',
            $modCtrl->approve(...),
            [$json, $modAuth, $log],
        );
        $this->router->add(
            'PUT',
            '/api/mod/applications/{id}/reject',
            $modCtrl->reject(...),
            [$json, $modAuth, $log],
        );
        $this->router->add(
            'GET',
            '/api/mod/applications/{id}/stats',
            $modCtrl->stats(...),
            [$json, $modAuth, $log],
        );

        $this->router->add('GET', '/widget-loader', $widgetCtrl->serve(...), []);

        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/track',
            $trackCtrl->track(...),
            [new CorsTrackMiddleware(), $json],
        );
    }
}
