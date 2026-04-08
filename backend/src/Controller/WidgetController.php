<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Util\HostNormalizer;

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
        $app = $this->getApprovedApplicationForEmbed($request, $token);
        if ($app === null) {
            return Response::text(
                'console.error("widget: invalid token or host mismatch");',
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

    public function eventPhrase(Request $request): Response
    {
        $token = trim((string) ($request->query['token'] ?? ''));
        $key = trim((string) ($request->query['key'] ?? ''));
        if ($token === '' || $key === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $key)) {
            return Response::json(['error' => 'validation'], 422);
        }
        $app = $this->getApprovedApplicationForEmbed($request, $token);
        if ($app === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $st = $this->db->pdo()->prepare(
            'SELECT phrase FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
        );
        $st->execute([(int) $app['id'], $key]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }

        return Response::json(['phrase' => $row['phrase']]);
    }

    /** @return array<string, mixed>|null */
    private function getApprovedApplicationForEmbed(Request $request, string $token): ?array
    {
        $st = $this->db->pdo()->prepare(
            'SELECT id, site_url, status, widget_token FROM widget_applications
             WHERE widget_token = ? AND status = ? LIMIT 1',
        );
        $st->execute([$token, 'approved']);
        $app = $st->fetch(PDO::FETCH_ASSOC);
        if (!$app) {
            return null;
        }
        $expectedHost = HostNormalizer::fromUrl($app['site_url']);
        $refererHost = HostNormalizer::fromReferer($request->header('Referer'));
        if ($refererHost === null && $request->header('Origin') !== null && $request->header('Origin') !== '') {
            $refererHost = HostNormalizer::fromUrl($request->header('Origin'));
        }
        if ($expectedHost === null || $refererHost === null || !hash_equals($expectedHost, $refererHost)) {
            return null;
        }

        return $app;
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
  var WIDGET_H = 170;
  var FLY_FROM_RIGHT_OVERFLOW = 220;
  var FLY_FROM_BOTTOM = 0;
  var FLY_TO_RIGHT_INSET = 150;
  var FLY_TO_BOTTOM = 130;
  var FLY_MS = 900;
  var MESSAGE_DELAY_MS = 5000;
  var REMOVE_DELAY_MS = 5000;
  var INTRO_DELAY_MS = 0;
  var busy = false;
  function pageUrl(){ try { return location.href.split("#")[0]; } catch(e){ return ""; } }
  function track(){
    var url = pageUrl();
    fetch(API + "/api/track", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token: TOKEN, page_url: url, application_id: APP_ID, event: "view" })
    }).catch(function(){});
  }
  function flyFromXY(){
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    return {
      x: vw + FLY_FROM_RIGHT_OVERFLOW - WIDGET_W,
      y: vh - WIDGET_H - FLY_FROM_BOTTOM
    };
  }
  function flyToXY(){
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    return {
      x: vw - FLY_TO_RIGHT_INSET - WIDGET_W,
      y: vh - WIDGET_H - FLY_TO_BOTTOM
    };
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
  function runFairySequence(phrase, spriteOk){
    if (!spriteOk) console.warn("widget: sprite failed to load", SPRITE_URL);
    var host = document.createElement("div");
    host.setAttribute("data-widget", "ok");
    var pStart = flyFromXY();
    host.style.cssText =
      "position:fixed;left:" + pStart.x + "px;top:" + pStart.y + "px;right:auto;bottom:auto;" +
      "z-index:2147483647;width:" + WIDGET_W + "px;height:" + WIDGET_H + "px;pointer-events:none;opacity:1;";

    var fairy = document.createElement("div");
    var fairyBg =
      "background-color:transparent;background-image:url('" + SPRITE_URL + "');background-repeat:no-repeat;" +
      "background-size:" + SPRITE_W + "px " + SPRITE_H + "px;background-position:0 0;";
    var fairyFallback =
      "background:#6b3a82 linear-gradient(180deg,#9b6fb8,#4a2d5c);border-radius:12px;";
    fairy.style.cssText =
      "position:absolute;right:16px;bottom:0;width:" + FRAME_W + "px;height:" + FRAME_H + "px;" +
      "transform:scaleX(1);transform-origin:50% 100%;" +
      (spriteOk ? fairyBg : fairyFallback);

    var bubble = document.createElement("div");
    bubble.textContent = phrase;
    bubble.style.cssText =
      "position:absolute;right:30px;bottom:108px;max-width:200px;padding:8px 10px;" +
      "background:#ffffff;color:#111;border-radius:10px;font:13px/1.35 system-ui,sans-serif;" +
      "box-shadow:0 8px 24px rgba(0,0,0,.2);opacity:0;transform:translateY(4px);" +
      "transition:opacity .25s ease,transform .25s ease;word-wrap:break-word;";

    host.appendChild(fairy);
    host.appendChild(bubble);
    document.body.appendChild(host);

    var frame = 0;
    var spriteTimer = null;
    if (spriteOk) {
      spriteTimer = setInterval(function(){
        frame = (frame + 1) % FRAME_COUNT;
        fairy.style.backgroundPosition = (-frame * FRAME_W) + "px 0";
      }, 85);
    }

    function setHostXY(x, y){
      host.style.transition = "left " + FLY_MS + "ms ease-in-out, top " + FLY_MS + "ms ease-in-out";
      requestAnimationFrame(function(){
        host.style.left = x + "px";
        host.style.top = y + "px";
      });
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
      busy = false;
    }

    setTimeout(function(){
      var pEnd = flyToXY();
      setHostXY(pEnd.x, pEnd.y);
      setTimeout(function(){
        showBubble();
        setTimeout(function(){
          hideBubble();
          fairy.style.transform = "scaleX(-1)";
          var pBack = flyFromXY();
          setHostXY(pBack.x, pBack.y);
          setTimeout(function(){
            setTimeout(destroy, REMOVE_DELAY_MS);
          }, FLY_MS);
        }, MESSAGE_DELAY_MS);
      }, FLY_MS);
    }, INTRO_DELAY_MS);
  }

  function show(eventKey){
    if (busy) return;
    var key = String(eventKey || "").trim();
    if (!key) {
      console.error("myLittleFairyWidget.show: передайте ключ события (строка), см. кабинет");
      return;
    }
    busy = true;
    var q = API + "/api/widget/event-phrase?token=" + encodeURIComponent(TOKEN) + "&key=" + encodeURIComponent(key);
    fetch(q)
      .then(function(r){
        if (!r.ok) throw new Error();
        return r.json();
      })
      .then(function(data){
        var text = String(data.phrase || "");
        if (!text) throw new Error();
        preloadImage(SPRITE_URL, function(ok){
          runFairySequence(text, ok);
        });
      })
      .catch(function(){
        console.warn("myLittleFairyWidget: событие не найдено или нет доступа");
        busy = false;
      });
  }

  function boot(){
    track();
    if (window.myLittleFairyWidget) return;
    window.myLittleFairyWidget = { show: show, version: "1" };
  }
  boot();
})();
JS;
    }
}
