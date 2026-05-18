<?php

declare(strict_types=1);

namespace App;

use App\Controller\ApplicationController;
use App\Controller\AuthController;
use App\Controller\FairyController;
use App\Controller\ModeratorController;
use App\Controller\TrackController;
use App\Controller\WidgetController;
use App\Controller\WidgetContentController;
use App\Controller\WidgetEventController;
use App\Controller\WidgetMediaController;
use App\MediaStorage;
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
        $fairyCtrl = new FairyController($this->db);
        $modCtrl = new ModeratorController($this->db);
        $widgetCtrl = new WidgetController($this->db, $appUrl);
        $widgetEventCtrl = new WidgetEventController($this->db);
        $widgetContentCtrl = new WidgetContentController($this->db, $appUrl);
        $mediaStorage = new MediaStorage(dirname(__DIR__) . '/storage/media');
        $mediaCtrl = new WidgetMediaController($this->db, $mediaStorage, $appUrl);
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
        $this->router->add(
            'DELETE',
            '/api/applications/{id}/events/{eventId}',
            $widgetEventCtrl->delete(...),
            [$json, $auth, $log],
        );
        $this->router->add('GET', '/api/action-types', $widgetEventCtrl->listActionTypes(...), [$json, $auth, $log]);
        $this->router->add(
            'GET',
            '/api/applications/{id}/text-widgets',
            $widgetContentCtrl->listText(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'POST',
            '/api/applications/{id}/text-widgets',
            $widgetContentCtrl->createText(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'PUT',
            '/api/applications/{id}/text-widgets/{widgetId}',
            $widgetContentCtrl->updateText(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'DELETE',
            '/api/applications/{id}/text-widgets/{widgetId}',
            $widgetContentCtrl->deleteText(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'GET',
            '/api/applications/{id}/survey-widgets',
            $widgetContentCtrl->listSurvey(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'POST',
            '/api/applications/{id}/survey-widgets',
            $widgetContentCtrl->createSurvey(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'PUT',
            '/api/applications/{id}/survey-widgets/{widgetId}',
            $widgetContentCtrl->updateSurvey(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'DELETE',
            '/api/applications/{id}/survey-widgets/{widgetId}',
            $widgetContentCtrl->deleteSurvey(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'GET',
            '/api/applications/{id}/video-widgets',
            $widgetContentCtrl->listVideo(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'POST',
            '/api/applications/{id}/video-widgets',
            $widgetContentCtrl->createVideo(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'PUT',
            '/api/applications/{id}/video-widgets/{widgetId}',
            $widgetContentCtrl->updateVideo(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'DELETE',
            '/api/applications/{id}/video-widgets/{widgetId}',
            $widgetContentCtrl->deleteVideo(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'GET',
            '/api/applications/{id}/media',
            $mediaCtrl->list(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'POST',
            '/api/applications/{id}/media',
            $mediaCtrl->upload(...),
            [$auth, $log],
        );
        $this->router->add(
            'DELETE',
            '/api/applications/{id}/media/{mediaId}',
            $mediaCtrl->delete(...),
            [$json, $auth, $log],
        );
        $this->router->add(
            'GET',
            '/api/applications/{id}/media/{mediaId}/file',
            $mediaCtrl->serveForCabinet(...),
            [$auth, $log],
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
        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/widget/survey-rate',
            $widgetCtrl->surveyRate(...),
            [$corsWidget, $json, $log],
        );
        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/widget/video-dismiss',
            $widgetCtrl->videoDismiss(...),
            [$corsWidget, $json, $log],
        );
        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/widget/survey-dismiss',
            $widgetCtrl->surveyDismiss(...),
            [$corsWidget, $json, $log],
        );
        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/widget/video-progress',
            $widgetCtrl->videoProgress(...),
            [$corsWidget, $json, $log],
        );
        $this->router->add(
            ['POST', 'OPTIONS'],
            '/api/widget/video-link-click',
            $widgetCtrl->videoLinkClick(...),
            [$corsWidget, $json, $log],
        );
        $this->router->add('GET', '/widget/media/{mediaId}', $mediaCtrl->serveForWidget(...), [$corsWidget, $log]);
    }
}
