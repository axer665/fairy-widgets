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
        if ($refererHost === null && $request->header('Origin') !== null && $request->header('Origin') !== '') {
            $refererHost = HostNormalizer::fromUrl($request->header('Origin'));
        }
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
  var SPRITE_URL = API + "/widget/fairy-sprite.png";
  var FRAME_W = 128;
  var FRAME_H = 106;
  var FRAME_COUNT = 8;
  var SPRITE_W = 1024;
  var SPRITE_H = 106;
  var WIDGET_W = 180;
  var START_RIGHT = -220;
  var FLY_IN_RIGHT = -20;
  var FLY_MS = 900;
  var MESSAGE_DELAY_MS = 5000;
  var REMOVE_DELAY_MS = 5000;
  var WAIT_BEFORE_FLY_MS = 10000;
  function pageUrl(){ try { return location.href.split("#")[0]; } catch(e){ return ""; } }
  function track(){
    var url = pageUrl();
    fetch(API + "/api/track", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token: TOKEN, page_url: url, application_id: APP_ID, event: "view" })
    }).catch(function(){});
  }
  function preloadImage(url, onDone){
    var img = new Image();
    var done = false;
    function finish(ok){
      if (done) return;
      done = true;
      onDone(ok);
    }
    img.onload = function(){ finish(true); };
    img.onerror = function(){ finish(false); };
    img.src = url;
    if (img.complete && img.naturalWidth > 0) finish(true);
  }
  function mount(){
    preloadImage(SPRITE_URL, function(ok){
      if (!ok) console.warn("widget: sprite failed to load", SPRITE_URL);
      var host = document.createElement("div");
      host.setAttribute("data-widget", "ok");
      host.style.cssText =
        "position:fixed;right:" + START_RIGHT + "px;bottom:16px;z-index:2147483647;" +
        "width:" + WIDGET_W + "px;height:170px;pointer-events:none;opacity:1;";

      var fairy = document.createElement("div");
      var fairyBg =
        "background-image:url('" + SPRITE_URL + "');background-repeat:no-repeat;" +
        "background-size:" + SPRITE_W + "px " + SPRITE_H + "px;background-position:0 0;";
      var fairyFallback =
        "background:#6b3a82 linear-gradient(180deg,#9b6fb8,#4a2d5c);border-radius:12px;";
      fairy.style.cssText =
        "position:absolute;right:16px;bottom:0;width:" + FRAME_W + "px;height:" + FRAME_H + "px;" +
        (ok ? fairyBg : fairyFallback);

      var bubble = document.createElement("div");
      bubble.textContent = "Привет! Я фея виджета.";
      bubble.style.cssText =
        "position:absolute;right:30px;bottom:108px;max-width:200px;padding:8px 10px;" +
        "background:#ffffff;color:#111;border-radius:10px;font:13px/1.35 system-ui,sans-serif;" +
        "box-shadow:0 8px 24px rgba(0,0,0,.2);opacity:0;transform:translateY(4px);" +
        "transition:opacity .25s ease,transform .25s ease;";

      host.appendChild(fairy);
      host.appendChild(bubble);
      document.body.appendChild(host);
      track();

      var frame = 0;
      var spriteTimer = null;
      if (ok) {
        spriteTimer = setInterval(function(){
          frame = (frame + 1) % FRAME_COUNT;
          fairy.style.backgroundPosition = (-frame * FRAME_W) + "px 0";
        }, 85);
      }

      function setRight(px){
        host.style.transition = "right " + FLY_MS + "ms ease-in-out";
        requestAnimationFrame(function(){ host.style.right = px + "px"; });
      }

      function showBubble(){
        bubble.style.opacity = "1";
        bubble.style.transform = "translateY(0)";
      }

      function hideBubble(){
        bubble.style.opacity = "0";
        bubble.style.transform = "translateY(4px)";
      }

      function destroy(){
        if (spriteTimer) clearInterval(spriteTimer);
        try { host.remove(); } catch(e){}
      }

      setTimeout(function(){
        setRight(FLY_IN_RIGHT);
        setTimeout(function(){
          showBubble();
          setTimeout(function(){
            hideBubble();
            setRight(START_RIGHT);
            setTimeout(function(){
              setTimeout(destroy, REMOVE_DELAY_MS);
            }, FLY_MS);
          }, MESSAGE_DELAY_MS);
        }, FLY_MS);
      }, WAIT_BEFORE_FLY_MS);
    });
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
