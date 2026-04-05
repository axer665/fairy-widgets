<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Util\HostNormalizer;
use PDO;

final class WidgetController
{
    public function __construct(
        private readonly Database $db,
        private readonly string $appUrl,
    ) {
    }

    public function serve(Request $request): Response
    {
        $token = trim((string) ($request->query['token'] ?? ''));
        if ($token === '') {
            return Response::text('console.error("widget: missing token");', 400, [
                'Content-Type' => 'application/javascript; charset=utf-8',
            ]);
        }
        $st = $this->db->pdo()->prepare(
            'SELECT id, site_url, status, widget_token FROM widget_applications
             WHERE widget_token = ? AND status = ? LIMIT 1',
        );
        $st->execute([$token, 'approved']);
        $app = $st->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            return Response::text('console.error("widget: invalid token");', 403, [
                'Content-Type' => 'application/javascript; charset=utf-8',
            ]);
        }
        $expectedHost = HostNormalizer::fromUrl($app['site_url']);
        $refererHost = HostNormalizer::fromReferer($request->header('Referer'));
        if ($expectedHost === null || $refererHost === null || !hash_equals($expectedHost, $refererHost)) {
            return Response::text(
                'console.error("widget: host mismatch");',
                403,
                ['Content-Type' => 'application/javascript; charset=utf-8'],
            );
        }

        $apiBase = $this->appUrl;
        $appId = (int) $app['id'];
        $js = $this->buildWidgetJs($apiBase, $token, $appId);
        return Response::text($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function buildWidgetJs(string $apiBase, string $widgetToken, int $applicationId): string
    {
        $api = addslashes($apiBase);
        $tok = addslashes($widgetToken);
        return <<<JS
(function(){
  var API = "{$api}";
  var TOKEN = "{$tok}";
  var APP_ID = {$applicationId};
  function pageUrl(){ try { return location.href.split("#")[0]; } catch(e){ return ""; } }
  function track(){
    var url = pageUrl();
    fetch(API + "/api/track", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token: TOKEN, page_url: url, application_id: APP_ID, event: "view" })
    }).catch(function(){});
  }
  function mount(){
    var el = document.createElement("div");
    el.setAttribute("data-widget", "ok");
    el.textContent = "Всё ок";
    el.style.cssText = "position:fixed;right:16px;bottom:16px;z-index:2147483647;padding:10px 14px;" +
      "background:#111;color:#fff;font:14px/1.4 system-ui,sans-serif;border-radius:8px;box-shadow:0 4px 20px rgba(0,0,0,.25);";
    document.body.appendChild(el);
    track();
    setTimeout(function(){ try { el.remove(); } catch(e){} }, 10000);
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", mount);
  } else {
    mount();
  }
})();
JS;
    }
}
