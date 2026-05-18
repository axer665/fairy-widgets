(function(){
  var API = "{{API}}";
  var TOKEN = "{{TOKEN}}";
  var SESSION_KEY = (function(){
    try {
      var k = sessionStorage.getItem("_mlf_sk");
      if (k && k.length >= 16) return k;
      var buf = new Uint8Array(16);
      if (window.crypto && crypto.getRandomValues) crypto.getRandomValues(buf);
      else for (var i = 0; i < 16; i++) buf[i] = Math.floor(Math.random() * 256);
      var hex = "";
      for (var j = 0; j < 16; j++) hex += ("0" + buf[j].toString(16)).slice(-2);
      sessionStorage.setItem("_mlf_sk", hex);
      return hex;
    } catch (e) {
      return "fb_" + String(Date.now()) + "_" + String(Math.random()).slice(2, 14);
    }
  })();
  var AUTO_STANDARD_WELCOME = {{AUTO_STANDARD}};
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
  var pendingExecutions = {};

  function rememberPendingExecution(id){ if (id) pendingExecutions[id] = true; }
  function forgetPendingExecution(id){ delete pendingExecutions[id]; }

  function beaconPost(path, body){
    var url = API + path;
    var payload = JSON.stringify(body);
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(url, new Blob([payload], { type: "application/json" }));
      } else {
        fetch(url, { method: "POST", headers: { "Content-Type": "application/json" }, body: payload, keepalive: true });
      }
    } catch (e) {}
  }

  window.addEventListener("pagehide", function(){
    try {
      for (var k in pendingExecutions) {
        if (!Object.prototype.hasOwnProperty.call(pendingExecutions, k)) continue;
        var n = parseInt(k, 10);
        if (n) beaconPost("/api/widget/event-complete", { token: TOKEN, execution_id: n, session_key: SESSION_KEY });
      }
      pendingExecutions = {};
    } catch (e) {}
  });

  function pageUrl(){ try { return location.href.split("#")[0]; } catch(e){ return ""; } }

  function track(){
    fetch(API + "/api/track", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ token: TOKEN, page_url: pageUrl() })
    }).catch(function(){});
  }

  function flyFromXY(){
    var vw = window.innerWidth, vh = window.innerHeight;
    return { x: vw + FLY_FROM_RIGHT_OVERFLOW - WIDGET_W, y: vh - WIDGET_H - FLY_FROM_BOTTOM };
  }

  function resolveLandXY(pos){
    var vw = window.innerWidth, vh = window.innerHeight;
    pos = pos || {};
    var h = pos.horizontal === "left" ? "left" : "right";
    var v = pos.vertical === "top" ? "top" : "bottom";
    var unit = pos.unit === "percent" ? "percent" : "px";
    var ox = Number(pos.x), oy = Number(pos.y);
    if (!isFinite(ox)) ox = FLY_TO_RIGHT_INSET;
    if (!isFinite(oy)) oy = FLY_TO_BOTTOM;
    function toPx(val, dim){ return unit === "percent" ? dim * val / 100 : val; }
    var x = h === "left" ? toPx(ox, vw) : vw - WIDGET_W - toPx(ox, vw);
    var y = v === "top" ? toPx(oy, vh) : vh - WIDGET_H - toPx(oy, vh);
    return { x: x, y: y };
  }

  function preloadImage(url, onDone){
    var img = new Image(), done = false;
    function finish(ok){ if (!done){ done = true; onDone(ok); } }
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
    forgetPendingExecution(executionId);
    postJson("/api/widget/event-complete", {
      token: TOKEN, execution_id: executionId, session_key: SESSION_KEY
    }).catch(function(){});
  }

  function normalizeAction(data){
    if (data.action && data.action.type) return data.action;
    var phrase = String(data.phrase || "");
    return { type: "text", phrase: phrase };
  }

  function createFairyHost(spriteOk){
    var host = document.createElement("div");
    host.setAttribute("data-widget", "ok");
    host.style.cssText =
      "position:fixed;right:auto;bottom:auto;z-index:2147483647;width:" + WIDGET_W + "px;height:" + WIDGET_H + "px;" +
      "pointer-events:none;opacity:1;transition:none;will-change:left,top;";
    var fairy = document.createElement("div");
    var fairyBg = "background:transparent url('" + SPRITE_URL + "') no-repeat;background-size:" +
      SPRITE_W + "px " + SPRITE_H + "px;background-position:0 0;";
    var fairyFallback = "background:#6b3a82 linear-gradient(180deg,#9b6fb8,#4a2d5c);border-radius:12px;";
    fairy.style.cssText =
      "position:absolute;right:16px;bottom:0;width:" + FRAME_W + "px;height:" + FRAME_H + "px;" +
      "transform:scaleX(1);transform-origin:50% 100%;" + (spriteOk ? fairyBg : fairyFallback);
    host.appendChild(fairy);
    document.body.appendChild(host);
    var frame = 0, spriteTimer = null;
    if (spriteOk) {
      spriteTimer = setInterval(function(){
        frame = (frame + 1) % FRAME_COUNT;
        fairy.style.backgroundPosition = (-frame * FRAME_W) + "px 0";
      }, 85);
    }
    function setHostXY(x, y, animate){
      if (animate) {
        host.style.transition = "left " + FLY_MS + "ms ease-in-out, top " + FLY_MS + "ms ease-in-out";
        requestAnimationFrame(function(){ requestAnimationFrame(function(){
          host.style.left = x + "px"; host.style.top = y + "px";
        }); });
      } else {
        host.style.transition = "none";
        host.style.left = x + "px"; host.style.top = y + "px";
        void host.offsetWidth;
      }
    }
    function flyAwayThen(executionId, done){
      var fairyEl = host.firstChild;
      if (fairyEl) fairyEl.style.transform = "scaleX(-1)";
      var pBack = flyFromXY();
      setHostXY(pBack.x, pBack.y, true);
      setTimeout(function(){
        if (spriteTimer) clearInterval(spriteTimer);
        try { host.remove(); } catch(e){}
        if (done) done();
        else completeExecution(executionId);
      }, FLY_MS + REMOVE_DELAY_MS);
    }
    return { host: host, fairy: fairy, setHostXY: setHostXY, flyAwayThen: flyAwayThen, clearSprite: function(){
      if (spriteTimer) clearInterval(spriteTimer);
    }};
  }

  function runTextAction(action, spriteOk, introMs, executionId, landPos){
    var phrase = String(action.phrase || "");
    var ctx = createFairyHost(spriteOk);
    var bubble = document.createElement("div");
    bubble.textContent = phrase;
    bubble.style.cssText =
      "position:absolute;right:30px;bottom:108px;max-width:200px;padding:8px 10px;" +
      "background:#fff;color:#111;border-radius:10px;font:13px/1.35 system-ui,sans-serif;" +
      "box-shadow:0 8px 24px rgba(0,0,0,.2);opacity:0;transform:translateY(4px);transition:opacity .25s,transform .25s;word-wrap:break-word;";
    ctx.host.appendChild(bubble);
    var pStart = flyFromXY();
    ctx.setHostXY(pStart.x, pStart.y, false);
    setTimeout(function(){
      var pEnd = resolveLandXY(landPos);
      ctx.setHostXY(pEnd.x, pEnd.y, true);
      setTimeout(function(){
        bubble.style.opacity = "1"; bubble.style.transform = "translateY(0)";
        setTimeout(function(){
          bubble.style.opacity = "0"; bubble.style.transform = "translateY(4px)";
          ctx.flyAwayThen(executionId, null);
        }, MESSAGE_DELAY_MS);
      }, FLY_MS);
    }, introMs || 0);
  }

  function runSurveyAction(action, spriteOk, introMs, executionId, landPos){
    var title = String(action.survey_title || "");
    var ctx = createFairyHost(spriteOk);
    var panel = document.createElement("div");
    panel.style.cssText =
      "position:absolute;right:24px;bottom:100px;max-width:220px;padding:10px 12px;" +
      "background:#fff;color:#111;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.2);" +
      "font:13px/1.35 system-ui,sans-serif;pointer-events:auto;opacity:0;transform:translateY(4px);transition:opacity .25s,transform .25s;";
    var h = document.createElement("p");
    h.textContent = title;
    h.style.cssText = "margin:0 0 10px;font-weight:600;font-size:13px;";
    panel.appendChild(h);
    var stars = document.createElement("div");
    stars.style.cssText = "display:flex;gap:4px;";
    var rated = false;
    var selectedLevel = 0;
    var starBtns = [];
    var STAR_OFF = "#c4c7c5";
    var STAR_ON = "#f4b400";
    function paintStars(upTo){
      for (var i = 0; i < starBtns.length; i++) {
        starBtns[i].style.color = i < upTo ? STAR_ON : STAR_OFF;
      }
    }
    function submitRating(n){
      if (rated) return;
      rated = true;
      selectedLevel = n;
      paintStars(n);
      postJson("/api/widget/survey-rate", {
        token: TOKEN, execution_id: executionId, session_key: SESSION_KEY,
        rating: n, page_url: pageUrl()
      }).then(function(){
        ctx.flyAwayThen(executionId, null);
      }).catch(function(){ completeExecution(executionId); });
    }
    stars.onmouseleave = function(){
      if (!rated) paintStars(selectedLevel);
    };
    for (var s = 1; s <= 5; s++) {
      (function(star){
        var btn = document.createElement("button");
        btn.type = "button";
        btn.setAttribute("aria-label", star + " из 5");
        btn.textContent = "\u2605";
        btn.style.cssText =
          "border:none;background:transparent;cursor:pointer;font-size:22px;line-height:1;padding:0;color:" + STAR_OFF + ";";
        btn.onmouseenter = function(){ if (!rated) paintStars(star); };
        btn.onclick = function(){ submitRating(star); };
        starBtns.push(btn);
        stars.appendChild(btn);
      })(s);
    }
    panel.appendChild(stars);
    ctx.host.appendChild(panel);
    ctx.host.style.pointerEvents = "auto";
    var pStart = flyFromXY();
    ctx.setHostXY(pStart.x, pStart.y, false);
    setTimeout(function(){
      ctx.setHostXY(resolveLandXY(landPos).x, resolveLandXY(landPos).y, true);
      setTimeout(function(){
        panel.style.opacity = "1"; panel.style.transform = "translateY(0)";
      }, FLY_MS);
    }, introMs || 0);
  }

  function runVideoAction(action, spriteOk, introMs, executionId, landPos){
    var videoUrl = String(action.video_url || "");
    if (!videoUrl) { completeExecution(executionId); return; }
    var linkUrl = action.video_link_url ? String(action.video_link_url) : "";
    var ctx = createFairyHost(spriteOk);
    var panel = document.createElement("div");
    panel.style.cssText =
      "position:absolute;right:20px;bottom:96px;width:200px;padding:8px;" +
      "background:#111;color:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.35);" +
      "pointer-events:auto;opacity:0;transform:translateY(4px);transition:opacity .25s,transform .25s;";
    var video = document.createElement("video");
    video.src = videoUrl;
    video.controls = true;
    video.playsInline = true;
    video.style.cssText = "width:100%;max-height:120px;border-radius:6px;background:#000;display:block;";
    panel.appendChild(video);
    var row = document.createElement("div");
    row.style.cssText = "display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;";
    if (linkUrl) {
      var linkBtn = document.createElement("a");
      linkBtn.href = linkUrl;
      linkBtn.target = "_blank";
      linkBtn.rel = "noopener noreferrer";
      linkBtn.textContent = "Подробнее";
      linkBtn.style.cssText =
        "flex:1;text-align:center;padding:6px 8px;background:#1a73e8;color:#fff;border-radius:6px;" +
        "text-decoration:none;font-size:12px;font-weight:600;";
      row.appendChild(linkBtn);
    }
    var dismissBtn = document.createElement("button");
    dismissBtn.type = "button";
    dismissBtn.textContent = "Не интересно";
    dismissBtn.style.cssText =
      "flex:1;padding:6px 8px;border:1px solid #555;border-radius:6px;background:transparent;color:#ddd;" +
      "font-size:12px;cursor:pointer;";
    var dismissed = false;
    function dismiss(){
      if (dismissed) return;
      dismissed = true;
      try { video.pause(); } catch(e){}
      postJson("/api/widget/video-dismiss", {
        token: TOKEN, execution_id: executionId, session_key: SESSION_KEY
      }).catch(function(){});
      ctx.flyAwayThen(executionId, null);
    }
    dismissBtn.onclick = dismiss;
    row.appendChild(dismissBtn);
    panel.appendChild(row);
    ctx.host.appendChild(panel);
    ctx.host.style.pointerEvents = "auto";
    var pStart = flyFromXY();
    ctx.setHostXY(pStart.x, pStart.y, false);
    setTimeout(function(){
      ctx.setHostXY(resolveLandXY(landPos).x, resolveLandXY(landPos).y, true);
      setTimeout(function(){
        panel.style.opacity = "1"; panel.style.transform = "translateY(0)";
        try { video.play().catch(function(){}); } catch(e){}
      }, FLY_MS);
    }, introMs || 0);
  }

  function runFairyAction(action, spriteOk, introMs, executionId, landPos){
    var t = String(action.type || "text");
    if (t === "survey") return runSurveyAction(action, spriteOk, introMs, executionId, landPos);
    if (t === "video") return runVideoAction(action, spriteOk, introMs, executionId, landPos);
    return runTextAction(action, spriteOk, introMs, executionId, landPos);
  }

  function beginAndPlay(body, onFail){
    postJson("/api/widget/event-begin", body)
      .then(function(r){
        if (r.status === 409) { if (onFail) onFail(); return null; }
        if (!r.ok) throw new Error();
        return r.json();
      })
      .then(function(data){
        if (!data) return;
        var eid = data.execution_id;
        if (!eid) throw new Error();
        var action = normalizeAction(data);
        if (action.type === "text" && !String(action.phrase || "")) throw new Error();
        if (action.type === "survey" && !String(action.survey_title || "")) throw new Error();
        if (action.type === "video" && !String(action.video_url || "")) throw new Error();
        rememberPendingExecution(eid);
        preloadImage(SPRITE_URL, function(ok){
          runFairyAction(action, ok, 0, eid, data.position || null);
        });
      })
      .catch(function(){ if (onFail) onFail(); });
  }

  function show(eventKey){
    var key = String(eventKey || "").trim();
    if (!key) {
      console.error("myLittleFairyWidget.show: передайте ключ события");
      return;
    }
    beginAndPlay({ token: TOKEN, event_key: key, session_key: SESSION_KEY }, function(){
      console.warn("myLittleFairyWidget: событие не выполнено — см. кабинет");
    });
  }

  function boot(){
    track();
    if (window.myLittleFairyWidget) return;
    window.myLittleFairyWidget = { show: show, version: "{{VERSION}}" };
    if (AUTO_STANDARD_WELCOME) {
      preloadImage(SPRITE_URL, function(){
        autoTimer = setTimeout(function(){
          autoTimer = null;
          beginAndPlay({ token: TOKEN, event_key: STANDARD_EVENT_KEY, session_key: SESSION_KEY }, function(){});
        }, WAIT_BEFORE_FLY_MS);
      });
    }
  }
  boot();
})();
