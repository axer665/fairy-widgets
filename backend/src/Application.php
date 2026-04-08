<?php

declare(strict_types=1);

namespace App;

use App\Controller\ApplicationController;
use App\Controller\AuthController;
use App\Controller\FairyController;
use App\Controller\ModeratorController;
use App\Controller\TrackController;
use App\Controller\WidgetController;
use App\Controller\WidgetEventController;
use App\Http\Request;
use App\Http\Response;
use App\Middleware\AuthMiddleware;
use App\Middleware\CorsTrackMiddleware;
use App\Middleware\CorsWidgetPublicMiddleware;
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
        $fairyCtrl = new FairyController($this->db, $appUrl);
        $modCtrl = new ModeratorController($this->db);
        $widgetCtrl = new WidgetController($this->db, $appUrl);
        $widgetEventCtrl = new WidgetEventController($this->db);
        $trackCtrl = new TrackController($this->db);

        $corsWidget = new CorsWidgetPublicMiddleware();

        $this->router->add('POST', '/api/register', $authCtrl->register(...), [$json, $log]);
        $this->router->add('POST', '/api/login', $authCtrl->login(...), [$json, $log]);

        $this->router->add('GET', '/api/me', $authCtrl->me(...), [$json, $auth, $log]);
        $this->router->add('GET', '/api/applications', $appCtrl->list(...), [$json, $auth, $log]);
        $this->router->add('POST', '/api/applications', $appCtrl->create(...), [$json, $auth, $log]);
        $this->router->add(
            'GET',
            '/api/applications/{id}/fairies',
            $fairyCtrl->listByApplication(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'POST',
            '/api/applications/{id}/fairies',
            $fairyCtrl->create(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'GET',
            '/api/applications/{id}/event-failures',
            $fairyCtrl->listFailures(...),
            [$json, $auth, $log],
        );
        $this->router->add('PUT', '/api/fairies/{id}', $fairyCtrl->update(...), [$json, $auth, $log]);
        $this->router->add(
            'PUT',
            '/api/fairies/{id}/events',
            $fairyCtrl->putAssignments(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'GET',
            '/api/applications/{id}/events',
            $widgetEventCtrl->list(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'POST',
            '/api/applications/{id}/events',
            $widgetEventCtrl->create(...),
            [$json, $auth, $log],
        );

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
        $this->router->add('GET', '/', static function (Request $request) use ($widgetCtrl): Response {
            if (trim((string) ($request->query['token'] ?? '')) === '') {
                return Response::json(['error' => 'not_found'], 404);
            }

            return $widgetCtrl->serve($request);
        }, []);

        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/track',
            $trackCtrl->track(...),
            [new CorsTrackMiddleware(), $json],
        );
        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/widget/event-begin',
            $widgetCtrl->eventBegin(...),
            [$corsWidget, $json, $log],
        );
        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/widget/event-complete',
            $widgetCtrl->eventComplete(...),
            [$corsWidget, $json, $log],
        );
    }
}
