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
    public const REASON_ALL_FAIRIES_BUSY = 'all_fairies_busy';

    /** Зарезервированный ключ: то же событие, что и остальные; авто после загрузки, если назначено фее. */
    private const STANDARD_EVENT_KEY = '_standard';

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
        $appRow = $this->resolveApplicationForEmbed($request, $token);
        if ($appRow === null) {
            return Response::text(
                'console.error("widget: invalid token or host mismatch");',
                403,
                ['Content-Type' => 'application/javascript; charset=utf-8'],
            );
        }

        $apiBase = $this->appUrl;
        $appId = (int) $appRow['application_id'];
        $chk = $this->db->pdo()->prepare(
            'SELECT 1 FROM widget_fairies f
             INNER JOIN fairy_events fe ON fe.fairy_id = f.id
             INNER JOIN widget_events we ON we.id = fe.widget_event_id AND we.event_key = ?
             WHERE f.application_id = ? LIMIT 1',
        );
        $chk->execute([self::STANDARD_EVENT_KEY, $appId]);
        $autoStandardWelcome = (bool) $chk->fetchColumn();
        $js = $this->buildWidgetJs($apiBase, $token, $autoStandardWelcome);
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
        $eventKey = trim((string) ($b['event_key'] ?? ''));
        if ($token === '') {
            return Response::json(['error' => 'validation', 'message' => 'token обязателен'], 422);
        }
        $appRow = $this->resolveApplicationForEmbed($request, $token);
        if ($appRow === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $appId = (int) $appRow['application_id'];

        if ($eventKey === '' || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $eventKey)) {
            return Response::json(['error' => 'validation'], 422);
        }

        return $this->beginEventForApplication($appId, $eventKey);
    }

    public function eventComplete(Request $request): Response
    {
        $b = $request->body;
        if (!is_array($b)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        $token = trim((string) ($b['token'] ?? ''));
        $executionId = isset($b['execution_id']) ? (int) $b['execution_id'] : 0;
        if ($token === '' || $executionId < 1) {
            return Response::json(['error' => 'validation', 'message' => 'token и execution_id обязательны'], 422);
        }
        if ($this->resolveApplicationForEmbed($request, $token) === null) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $pdo = $this->db->pdo();
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare(
                'SELECT x.id AS ex_id, x.fairy_id, x.completed_at, f.current_execution_id
                 FROM widget_event_executions x
                 INNER JOIN widget_fairies f ON f.id = x.fairy_id
                 INNER JOIN widget_applications a ON a.id = f.application_id
                 WHERE x.id = ? AND a.widget_token = ? AND a.status = ?
                 FOR UPDATE',
            );
            $st->execute([$executionId, $token, 'approved']);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->rollBack();

                return Response::json(['error' => 'not_found'], 404);
            }
            $fairyId = (int) $row['fairy_id'];
            if ($row['completed_at'] !== null) {
                $pdo->rollBack();

                return Response::json(['ok' => true]);
            }
            $pdo->prepare(
                'UPDATE widget_event_executions SET completed_at = CURRENT_TIMESTAMP WHERE id = ?',
            )->execute([$executionId]);
            if ((int) ($row['current_execution_id'] ?? 0) === $executionId) {
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

    /** @return array{application_id: int, site_url: string}|null */
    private function resolveApplicationForEmbed(Request $request, string $appToken): ?array
    {
        $st = $this->db->pdo()->prepare(
            'SELECT a.id AS application_id, a.site_url, a.status
             FROM widget_applications a
             WHERE a.widget_token = ? AND a.status = ? LIMIT 1',
        );
        $st->execute([$appToken, 'approved']);
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

        return [
            'application_id' => (int) $row['application_id'],
            'site_url' => (string) $row['site_url'],
        ];
    }

    private function firstFairyIdForLog(PDO $pdo, int $appId): int
    {
        $st = $pdo->prepare(
            'SELECT id FROM widget_fairies WHERE application_id = ? ORDER BY id ASC LIMIT 1',
        );
        $st->execute([$appId]);
        $id = $st->fetchColumn();

        return $id !== false ? (int) $id : 0;
    }

    private function beginEventForApplication(int $appId, string $eventKey): Response
    {
        $pdo = $this->db->pdo();
        $logFairy = $this->firstFairyIdForLog($pdo, $appId);
        if ($logFairy < 1) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $st = $pdo->prepare(
            'SELECT id, phrase FROM widget_events WHERE application_id = ? AND event_key = ? LIMIT 1',
        );
        $st->execute([$appId, $eventKey]);
        $ev = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ev) {
            $this->insertFailure(
                $pdo,
                $appId,
                $logFairy,
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
        $cst = $pdo->prepare(
            'SELECT f.id FROM widget_fairies f
             INNER JOIN fairy_events fe ON fe.fairy_id = f.id AND fe.widget_event_id = ?
             WHERE f.application_id = ?
             ORDER BY f.id ASC',
        );
        $cst->execute([$widgetEventId, $appId]);
        /** @var list<int|string> $fairyIds */
        $fairyIds = $cst->fetchAll(PDO::FETCH_COLUMN);
        if ($fairyIds === []) {
            $this->insertFailure(
                $pdo,
                $appId,
                $logFairy,
                $widgetEventId,
                $eventKey,
                self::REASON_NOT_ASSIGNED,
                'Событие не назначено ни одной фее',
                null,
            );

            return Response::json(['error' => 'conflict', 'reason' => self::REASON_NOT_ASSIGNED], 409);
        }

        $lockName = 'wevt_' . $widgetEventId;
        $execId = 0;
        try {
            $pdo->beginTransaction();
            $lk = $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 8)')->fetchColumn();
            if ((int) $lk !== 1) {
                $pdo->rollBack();

                return Response::json(['error' => 'server', 'message' => 'lock'], 503);
            }
            try {
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
                        (int) $fairyIds[0],
                        $widgetEventId,
                        $eventKey,
                        self::REASON_EVENT_LOCKED,
                        'Это событие уже выполняется (другая фея)',
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
                foreach ($fairyIds as $fidRaw) {
                    $fairyId = (int) $fidRaw;
                    $this->releaseStaleExecution($pdo, $fairyId);
                    $fst = $pdo->prepare(
                        'SELECT id, current_execution_id FROM widget_fairies WHERE id = ? FOR UPDATE',
                    );
                    $fst->execute([$fairyId]);
                    $fairy = $fst->fetch(PDO::FETCH_ASSOC);
                    if (!$fairy || !empty($fairy['current_execution_id'])) {
                        continue;
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

                    return Response::json([
                        'execution_id' => $execId,
                        'phrase' => $phrase,
                    ]);
                }
                $this->insertFailure(
                    $pdo,
                    $appId,
                    (int) $fairyIds[0],
                    $widgetEventId,
                    $eventKey,
                    self::REASON_ALL_FAIRIES_BUSY,
                    'Все феи с этим событием заняты',
                    null,
                );
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

        return Response::json(['error' => 'conflict', 'reason' => self::REASON_ALL_FAIRIES_BUSY], 409);
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
            'SELECT fairy_id, widget_event_id FROM widget_event_executions WHERE id = ? LIMIT 1',
        );
        $bst->execute([$blockerExecutionId]);
        $b = $bst->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return;
        }
        $bk = $this->blockerEventKey($pdo, $blockerExecutionId);
        $detail = ($bk === self::STANDARD_EVENT_KEY)
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
        bool $autoStandardWelcome,
    ): string {
        $api = addslashes($apiBase);
        $tok = addslashes($widgetToken);
        $stdJs = $autoStandardWelcome ? 'true' : 'false';
        return <<<JS
(function(){
  var API = "{$api}";
  var TOKEN = "{$tok}";
  var AUTO_STANDARD_WELCOME = {$stdJs};
  var STANDARD_EVENT_KEY = "_standard";
  var WAIT_BEFORE_FLY_MS = 10000;
  var SPRITE_URL = API + "/widget/fairy-sprite.png";
  var FRAME_W = 128;
  var FRAME_H = 106;
  var FRAME_COUNT = 8;
  var SPRITE_W = 1024;
  var SPRITE_H = 106;
  var WIDGET_W = 180;
  var WIDGET_H = 170;
  var FLY_FROM_RIGHT_OVERFLOW = 320;
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
      body: JSON.stringify({ token: TOKEN, page_url: url })
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
    postJson("/api/widget/event-complete", { token: TOKEN, execution_id: executionId }).catch(function(){});
  }
  function runFairySequence(phrase, spriteOk, introMs, executionId){
    introMs = introMs || 0;
    if (!spriteOk) console.warn("widget: sprite failed to load", SPRITE_URL);
    var host = document.createElement("div");
    host.setAttribute("data-widget", "ok");
    var pStart = flyFromXY();
    host.style.cssText =
      "position:fixed;right:auto;bottom:auto;" +
      "z-index:2147483647;width:" + WIDGET_W + "px;height:" + WIDGET_H + "px;pointer-events:none;opacity:1;" +
      "transition:none;will-change:left,top;";

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

    function setHostXY(x, y, animate){
      if (animate) {
        host.style.transition = "left " + FLY_MS + "ms ease-in-out, top " + FLY_MS + "ms ease-in-out";
        requestAnimationFrame(function(){
          requestAnimationFrame(function(){
            host.style.left = x + "px";
            host.style.top = y + "px";
          });
        });
      } else {
        host.style.transition = "none";
        host.style.left = x + "px";
        host.style.top = y + "px";
        void host.offsetWidth;
      }
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

    setHostXY(pStart.x, pStart.y, false);
    setTimeout(function(){
      var pEnd = flyToXY();
      setHostXY(pEnd.x, pEnd.y, true);
      setTimeout(function(){
        showBubble();
        setTimeout(function(){
          hideBubble();
          fairy.style.transform = "scaleX(-1)";
          var pBack = flyFromXY();
          setHostXY(pBack.x, pBack.y, true);
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
    beginAndPlay({ token: TOKEN, event_key: key }, function(){
      console.warn("myLittleFairyWidget: событие не выполнено (фея занята, событие у другой феи и т.д.) — см. кабинет");
    });
  }

  function boot(){
    track();
    if (window.myLittleFairyWidget) return;
    window.myLittleFairyWidget = { show: show, version: "4" };
    if (AUTO_STANDARD_WELCOME) {
      preloadImage(SPRITE_URL, function(){
        autoTimer = setTimeout(function(){
          autoTimer = null;
          beginAndPlay({ token: TOKEN, event_key: STANDARD_EVENT_KEY }, function(){});
        }, WAIT_BEFORE_FLY_MS);
      });
    }
  }
  boot();
})();
JS;
    }
}
