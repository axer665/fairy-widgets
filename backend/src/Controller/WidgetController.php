<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Http\Request;
use App\Http\Response;
use App\Util\HostNormalizer;
use PDO;
use PDOException;

final class WidgetController
{
    private const STALE_EXECUTION_SECONDS = 900;

    public const REASON_FAIRY_BUSY = 'fairy_busy';
    public const REASON_EVENT_LOCKED = 'event_locked_other_fairy';
    public const REASON_NOT_ASSIGNED = 'event_not_assigned';
    public const REASON_NOT_FOUND = 'event_not_found';
    public const REASON_STANDARD_DISABLED = 'standard_not_enabled';

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
        $fairyIdQ = isset($request->query['fairy_id']) ? (int) $request->query['fairy_id'] : 0;
        $ctx = $this->resolveEmbedContext($request, $token, $fairyIdQ > 0 ? $fairyIdQ : null);
        if ($ctx === null) {
            return Response::text(
                'console.error("widget: invalid token, fairy_id or host mismatch");',
                403,
                ['Content-Type' => 'application/javascript; charset=utf-8'],
            );
        }

        $apiBase = $this->appUrl;
        $fairyId = (int) $ctx['fairy_id'];
        $appId = (int) $ctx['application_id'];
        $standardBehavior = (bool) (int) ($ctx['standard_behavior'] ?? 0);
        $js = $this->buildWidgetJs($apiBase, $token, $fairyId, $appId, $standardBehavior);
        return Response::text($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function eventBegin(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $kind = trim((string) ($b['kind'] ?? ''));
        $eventKey = trim((string) ($b['event_key'] ?? ''));
        $fairyIdBody = isset($b['fairy_id']) ? (int) $b['fairy_id'] : 0;
        if ($token === '' || $fairyIdBody < 1) {
            return Response::json(['error' => 'validation', 'message' => 'token и fairy_id обязательны'], 422);
        }
        $ctx = $this->resolveEmbedContext($request, $token, $fairyIdBody);
        if ($ctx === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $fairyId = (int) $ctx['fairy_id'];
        $appId = (int) $ctx['application_id'];

        if ($kind === 'standard') {
            return $this->beginStandard($fairyId, $appId, $ctx);
        }
        if ($eventKey === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $eventKey)) {
            return Response::json(['error' => 'validation'], 422);
        }

        return $this->beginEvent($fairyId, $appId, $eventKey, $ctx);
    }

    public function eventComplete(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $executionId = isset($b['execution_id']) ? (int) $b['execution_id'] : 0;
        $fairyIdBody = isset($b['fairy_id']) ? (int) $b['fairy_id'] : 0;
        if ($token === '' || $executionId < 1 || $fairyIdBody < 1) {
            return Response::json(['error' => 'validation', 'message' => 'token, fairy_id и execution_id обязательны'], 422);
        }
        $ctx = $this->resolveEmbedContext($request, $token, $fairyIdBody);
        if ($ctx === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $fairyId = (int) $ctx['fairy_id'];
        $pdo = $this->db->pdo();
        try {
            $pdo->beginTransaction();
            $fst = $pdo->prepare(
                'SELECT id, current_execution_id FROM widget_fairies WHERE id = ? FOR UPDATE',
            );
            $fst->execute([$fairyId]);
            $fairyRow = $fst->fetch(PDO::FETCH_ASSOC);
            if (!$fairyRow) {
                $pdo->rollBack();

                return Response::json(['error' => 'not_found'], 404);
            }
            $st = $pdo->prepare(
                'SELECT id, fairy_id, completed_at FROM widget_event_executions WHERE id = ? FOR UPDATE',
            );
            $st->execute([$executionId]);
            $ex = $st->fetch(PDO::FETCH_ASSOC);
            if (!$ex || (int) $ex['fairy_id'] !== $fairyId) {
                $pdo->rollBack();

                return Response::json(['error' => 'not_found'], 404);
            }
            if ($ex['completed_at'] !== null) {
                $pdo->rollBack();

                return Response::json(['ok' => true]);
            }
            $pdo->prepare(
                'UPDATE widget_event_executions SET completed_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([$executionId]);
            if ((int) ($fairyRow['current_execution_id'] ?? 0) === $executionId) {
                $pdo->prepare(
                    'UPDATE widget_fairies SET current_execution_id = NULL WHERE id = ?',
                )->execute([$fairyId]);
            }
            $pdo->commit();
        } catch (PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return Response::json(['error' => 'server'], 500);
        }

        return Response::json(['ok' => true]);
    }

    /** @return array<string, mixed>|null */
    private function resolveEmbedContext(Request $request, string $appToken, ?int $fairyId): ?array
    {
        $pdo = $this->db->pdo();
        if ($fairyId !== null && $fairyId > 0) {
            $st = $pdo->prepare(
                'SELECT f.id AS fairy_id, f.application_id, f.standard_behavior, a.site_url, a.status
                 FROM widget_fairies f
                 INNER JOIN widget_applications a ON a.id = f.application_id
                 WHERE a.widget_token = ? AND a.status = ? AND f.id = ? LIMIT 1',
            );
            $st->execute([$appToken, 'approved', $fairyId]);
        } else {
            $st = $pdo->prepare(
                'SELECT f.id AS fairy_id, f.application_id, f.standard_behavior, a.site_url, a.status
                 FROM widget_fairies f
                 INNER JOIN widget_applications a ON a.id = f.application_id
                 WHERE a.widget_token = ? AND a.status = ?
                 ORDER BY f.id ASC
                 LIMIT 1',
            );
            $st->execute([$appToken, 'approved']);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $expectedHost = HostNormalizer::fromUrl($row['site_url']);
        $refererHost = HostNormalizer::fromReferer($request->header('Referer'));
        if ($refererHost === null && $request->header('Origin') !== null && $request->header('Origin') !== '') {
            $refererHost = HostNormalizer::fromUrl($request->header('Origin'));
        }
        if ($expectedHost === null || $refererHost === null || !hash_equals($expectedHost, $refererHost)) {
            return null;
        }

        return $row;
    }

    /** @param array<string, mixed> $ctx */
    private function beginStandard(int $fairyId, int $appId, array $ctx): Response
    {
        if (!(bool) (int) ($ctx['standard_behavior'] ?? 0)) {
            return Response::json(['error' => 'forbidden', 'reason' => self::REASON_STANDARD_DISABLED], 403);
        }

        $pdo = $this->db->pdo();
        $defaultPhrase = 'Привет! Я фея виджета.';
        try {
            $pdo->beginTransaction();
            $this->releaseStaleExecution($pdo, $fairyId);
            $st = $pdo->prepare(
                'SELECT id, current_execution_id FROM widget_fairies WHERE id = ? FOR UPDATE',
            );
            $st->execute([$fairyId]);
            $fairy = $st->fetch(PDO::FETCH_ASSOC);
            if (!$fairy) {
                $pdo->rollBack();

                return Response::json(['error' => 'not_found'], 404);
            }
            if (!empty($fairy['current_execution_id'])) {
                $this->logFailureFromFairyBusy(
                    $pdo,
                    $appId,
                    $fairyId,
                    '_standard',
                    (int) $fairy['current_execution_id'],
                    null,
                );
                $pdo->commit();

                return Response::json(
                    ['error' => 'conflict', 'reason' => self::REASON_FAIRY_BUSY],
                    409,
                );
            }
            $pdo->prepare(
                'INSERT INTO widget_event_executions (fairy_id, widget_event_id, kind) VALUES (?,?,?)',
            )->execute([$fairyId, null, 'standard']);
            $execId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE widget_fairies SET current_execution_id = ? WHERE id = ?',
            )->execute([$execId, $fairyId]);
            $pdo->commit();
        } catch (PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return Response::json(['error' => 'server'], 500);
        }

        return Response::json([
            'execution_id' => $execId,
            'phrase' => $defaultPhrase,
        ]);
    }

    /** @param array<string, mixed> $ctx */
    private function beginEvent(int $fairyId, int $appId, string $eventKey, array $ctx): Response
    {
        $pdo = $this->db->pdo();
        $st = $pdo->prepare(
            'SELECT id, phrase FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
        );
        $st->execute([$appId, $eventKey]);
        $ev = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ev) {
            $this->insertFailure(
                $pdo,
                $appId,
                $fairyId,
                null,
                $eventKey,
                self::REASON_NOT_FOUND,
                'Событие с таким ключом не найдено',
                null,
            );

            return Response::json(['error' => 'conflict', 'reason' => self::REASON_NOT_FOUND], 409);
        }
        $widgetEventId = (int) $ev['id'];
        $phrase = (string) $ev['phrase'];
        $as = $pdo->prepare(
            'SELECT 1 FROM fairy_events WHERE fairy_id = ? AND widget_event_id = ? LIMIT 1',
        );
        $as->execute([$fairyId, $widgetEventId]);
        if (!$as->fetchColumn()) {
            $this->insertFailure(
                $pdo,
                $appId,
                $fairyId,
                $widgetEventId,
                $eventKey,
                self::REASON_NOT_ASSIGNED,
                'Событие не назначено этой фее',
                null,
            );

            return Response::json(['error' => 'conflict', 'reason' => self::REASON_NOT_ASSIGNED], 409);
        }

        $lockName = 'wevt_' . $widgetEventId;
        try {
            $pdo->beginTransaction();
            $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 8)')->fetchColumn();
            if ((int) $lk !== 1) {
                $pdo->rollBack();

                return Response::json(['error' => 'server', 'message' => 'lock'], 503);
            }
            try {
                $this->releaseStaleExecution($pdo, $fairyId);
                $fst = $pdo->prepare(
                    'SELECT id, current_execution_id FROM widget_fairies WHERE id = ? FOR UPDATE',
                );
                $fst->execute([$fairyId]);
                $fairy = $fst->fetch(PDO::FETCH_ASSOC);
                if (!$fairy) {
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                    $pdo->rollBack();

                    return Response::json(['error' => 'not_found'], 404);
                }
                if (!empty($fairy['current_execution_id'])) {
                    $this->logFailureFromFairyBusy(
                        $pdo,
                        $appId,
                        $fairyId,
                        $eventKey,
                        (int) $fairy['current_execution_id'],
                        $widgetEventId,
                    );
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                    $pdo->commit();

                    return Response::json(
                        ['error' => 'conflict', 'reason' => self::REASON_FAIRY_BUSY],
                        409,
                    );
                }
                $ost = $pdo->prepare(
                    'SELECT id, fairy_id FROM widget_event_executions
                     WHERE widget_event_id = ? AND completed_at IS NULL LIMIT 1 FOR UPDATE',
                );
                $ost->execute([$widgetEventId]);
                $other = $ost->fetch(PDO::FETCH_ASSOC);
                if ($other) {
                    $otherFairyId = (int) $other['fairy_id'];
                    $bk = $this->blockerEventKey($pdo, (int) $other['id']);
                    $this->insertFailure(
                        $pdo,
                        $appId,
                        $fairyId,
                        $widgetEventId,
                        $eventKey,
                        self::REASON_EVENT_LOCKED,
                        'Тот же event уже выполняет другая фея',
                        [
                            'blocker_execution_id' => (int) $other['id'],
                            'blocker_fairy_id' => $otherFairyId,
                            'blocker_widget_event_id' => $widgetEventId,
                            'blocker_event_key' => $bk,
                        ],
                    );
                    $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                    $pdo->commit();

                    return Response::json(
                        ['error' => 'conflict', 'reason' => self::REASON_EVENT_LOCKED],
                        409,
                    );
                }
                $pdo->prepare(
                    'INSERT INTO widget_event_executions (fairy_id, widget_event_id, kind) VALUES (?,?,?)',
                )->execute([$fairyId, $widgetEventId, 'event']);
                $execId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'UPDATE widget_fairies SET current_execution_id = ? WHERE id = ?',
                )->execute([$execId, $fairyId]);
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')');
                throw $e;
            }
        } catch (PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return Response::json(['error' => 'server'], 500);
        }

        return Response::json([
            'execution_id' => $execId,
            'phrase' => $phrase,
        ]);
    }

    private function blockerEventKey(PDO $pdo, int $executionId): ?string
    {
        $st = $pdo->prepare(
            'SELECT e.event_key FROM widget_event_executions x
             LEFT JOIN widget_events e ON e.id = x.widget_event_id
             WHERE x.id = ? LIMIT 1',
        );
        $st->execute([$executionId]);
        $k = $st->fetchColumn();

        return $k !== false ? (string) $k : null;
    }

    private function logFailureFromFairyBusy(
        PDO $pdo,
        int $appId,
        int $fairyId,
        string $attemptedKey,
        int $blockerExecutionId,
        ?int $attemptedWidgetEventId = null,
    ): void {
        $bst = $pdo->prepare(
            'SELECT fairy_id, widget_event_id, kind FROM widget_event_executions WHERE id = ? LIMIT 1',
        );
        $bst->execute([$blockerExecutionId]);
        $b = $bst->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return;
        }
        $bk = $this->blockerEventKey($pdo, $blockerExecutionId);
        $detail = $b['kind'] === 'standard'
            ? 'Фея выполняла стандартное приветствие'
            : ('Фея выполняла событие' . ($bk !== null && $bk !== '' ? ' «' . $bk . '»' : ''));
        $this->insertFailure(
            $pdo,
            $appId,
            $fairyId,
            $attemptedWidgetEventId,
            $attemptedKey !== '' ? $attemptedKey : '(стандартное)',
            self::REASON_FAIRY_BUSY,
            $detail,
            [
                'blocker_execution_id' => $blockerExecutionId,
                'blocker_fairy_id' => (int) $b['fairy_id'],
                'blocker_widget_event_id' => $b['widget_event_id'] !== null ? (int) $b['widget_event_id'] : null,
                'blocker_event_key' => $bk,
            ],
        );
    }

    /** @param array<string, mixed>|null $blocker */
    private function insertFailure(
        PDO $pdo,
        int $appId,
        int $fairyId,
        ?int $widgetEventId,
        string $eventKey,
        string $reasonCode,
        string $detail,
        ?array $blocker,
    ): void {
        $blocker = $blocker ?? [];
        $ins = $pdo->prepare(
            'INSERT INTO widget_event_failures (
                application_id, fairy_id, widget_event_id, event_key, reason_code, detail,
                blocker_execution_id, blocker_fairy_id, blocker_widget_event_id, blocker_event_key
            ) VALUES (?,?,?,?,?,?,?,?,?,?)',
        );
        $ins->execute([
            $appId,
            $fairyId,
            $widgetEventId,
            $eventKey,
            $reasonCode,
            $detail,
            $blocker['blocker_execution_id'] ?? null,
            $blocker['blocker_fairy_id'] ?? null,
            $blocker['blocker_widget_event_id'] ?? null,
            $blocker['blocker_event_key'] ?? null,
        ]);
    }

    private function releaseStaleExecution(PDO $pdo, int $fairyId): void
    {
        $st = $pdo->prepare(
            'SELECT current_execution_id FROM widget_fairies WHERE id = ? FOR UPDATE',
        );
        $st->execute([$fairyId]);
        $cid = $st->fetchColumn();
        if (!$cid) {
            return;
        }
        $execId = (int) $cid;
        $est = $pdo->prepare(
            'SELECT id, started_at, completed_at FROM widget_event_executions WHERE id = ? LIMIT 1',
        );
        $est->execute([$execId]);
        $ex = $est->fetch(PDO::FETCH_ASSOC);
        if (!$ex || $ex['completed_at'] !== null) {
            $pdo->prepare('UPDATE widget_fairies SET current_execution_id = NULL WHERE id = ?')->execute([$fairyId]);

            return;
        }
        $started = strtotime((string) $ex['started_at']);
        if ($started !== false && (time() - $started) > self::STALE_EXECUTION_SECONDS) {
            $pdo->prepare(
                'UPDATE widget_event_executions SET completed_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([$execId]);
            $pdo->prepare('UPDATE widget_fairies SET current_execution_id = NULL WHERE id = ?')->execute([$fairyId]);
        }
    }

    private function buildWidgetJs(
        string $apiBase,
        string $widgetToken,
        int $fairyId,
        int $applicationId,
        bool $standardBehavior,
    ): string {
        $api = addslashes($apiBase);
        $tok = addslashes($widgetToken);
        $stdJs = $standardBehavior ? 'true' : 'false';
        $defaultPhrase = addslashes('Привет! Я фея виджета.');
        return <<<JS
(function(){
  var API = "{$api}";
  var TOKEN = "{$tok}";
  var FAIRY_ID = {$fairyId};
  var APP_ID = {$applicationId};
  var STANDARD_BEHAVIOR = {$stdJs};
  var DEFAULT_PHRASE = "{$defaultPhrase}";
  var WAIT_BEFORE_FLY_MS = 10000;
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
  var autoTimer = null;
  function pageUrl(){ try { return location.href.split("#")[0]; } catch(e){ return ""; } }
  function track(){
    var url = pageUrl();
    fetch(API + "/api/track", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token: TOKEN, page_url: url, fairy_id: FAIRY_ID, application_id: APP_ID })
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
  function postJson(path, body){
    return fetch(API + path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    });
  }
  function completeExecution(executionId){
    postJson("/api/widget/event-complete", { token: TOKEN, fairy_id: FAIRY_ID, execution_id: executionId }).catch(function(){});
  }
  function runFairySequence(phrase, spriteOk, introMs, executionId){
    introMs = introMs || 0;
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
      completeExecution(executionId);
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
    }, introMs);
  }

  function beginAndPlay(body, onFail){
    postJson("/api/widget/event-begin", body)
      .then(function(r){
        if (r.status === 409) {
          if (onFail) onFail();
          return null;
        }
        if (!r.ok) throw new Error();
        return r.json();
      })
      .then(function(data){
        if (!data) return;
        var eid = data.execution_id;
        var text = String(data.phrase || "");
        if (!eid || !text) throw new Error();
        preloadImage(SPRITE_URL, function(ok){
          runFairySequence(text, ok, 0, eid);
        });
      })
      .catch(function(){
        if (onFail) onFail();
      });
  }

  function show(eventKey){
    var key = String(eventKey || "").trim();
    if (!key) {
      console.error("myLittleFairyWidget.show: передайте ключ события (строка), см. кабинет");
      return;
    }
    beginAndPlay({ token: TOKEN, fairy_id: FAIRY_ID, event_key: key }, function(){
      console.warn("myLittleFairyWidget: событие не выполнено (фея занята, событие у другой феи и т.д.) — см. кабинет");
    });
  }

  function boot(){
    track();
    if (window.myLittleFairyWidget) return;
    window.myLittleFairyWidget = { show: show, version: "2" };
    if (STANDARD_BEHAVIOR) {
      preloadImage(SPRITE_URL, function(){
        autoTimer = setTimeout(function(){
          autoTimer = null;
          beginAndPlay({ token: TOKEN, fairy_id: FAIRY_ID, kind: "standard" }, function(){});
        }, WAIT_BEFORE_FLY_MS);
      });
    }
  }
  boot();
})();
JS;
    }
}
