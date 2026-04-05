(function () {
  var TOKEN_KEY = "mod_jwt";

  function api(path, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    var t = localStorage.getItem(TOKEN_KEY);
    if (t) {
      headers.Authorization = "Bearer " + t;
    }
    if (opts.body && typeof opts.body === "string" && !headers["Content-Type"]) {
      headers["Content-Type"] = "application/json";
    }
    return fetch(path, {
      method: opts.method || "GET",
      headers: headers,
      body: opts.body,
    }).then(function (res) {
      if (!res.ok) {
        return res.json().then(function (j) {
          var err = new Error(j.message || j.error || "request_failed");
          err.status = res.status;
          throw err;
        });
      }
      if (res.status === 204) {
        return null;
      }
      return res.json();
    });
  }

  function setToken(token) {
    if (token) {
      localStorage.setItem(TOKEN_KEY, token);
    } else {
      localStorage.removeItem(TOKEN_KEY);
    }
  }

  function renderAuthBar() {
    var bar = document.getElementById("auth-bar");
    var t = localStorage.getItem(TOKEN_KEY);
    bar.innerHTML = "";
    if (t) {
      var out = document.createElement("button");
      out.textContent = "Выйти";
      out.addEventListener("click", function () {
        setToken(null);
        document.getElementById("list-section").hidden = true;
        document.getElementById("login-section").hidden = false;
        renderAuthBar();
      });
      bar.appendChild(out);
    }
  }

  function showLoginError(msg) {
    var el = document.getElementById("login-error");
    el.textContent = msg;
    el.hidden = !msg;
  }

  document.getElementById("login-form").addEventListener("submit", function (e) {
    e.preventDefault();
    showLoginError("");
    var fd = new FormData(e.target);
    var login = fd.get("login");
    var password = fd.get("password");
    api("/api/login", {
      method: "POST",
      body: JSON.stringify({ login: login, password: password }),
    })
      .then(function (data) {
        if (data.user.role !== "moderator") {
          showLoginError("Нужен аккаунт модератора");
          return;
        }
        setToken(data.token);
        renderAuthBar();
        document.getElementById("login-section").hidden = true;
        document.getElementById("list-section").hidden = false;
        return loadList();
      })
      .catch(function () {
        showLoginError("Неверный логин или пароль");
      });
  });

  function statusRu(s) {
    if (s === "pending") return "На модерации";
    if (s === "approved") return "Одобрено";
    if (s === "rejected") return "Отклонено";
    return s;
  }

  function loadStats(id, container) {
    container.textContent = "Загрузка статистики…";
    api("/api/mod/applications/" + id + "/stats")
      .then(function (data) {
        var html =
          "<div class='stats'><strong>Показы:</strong> всего " +
          data.total_views +
          "<table>";
        (data.by_page || []).forEach(function (row) {
          html +=
            "<tr><td>" +
            escapeHtml(row.page_url) +
            "</td><td>" +
            row.cnt +
            "</td></tr>";
        });
        html += "</table></div>";
        container.innerHTML = html;
      })
      .catch(function () {
        container.textContent = "Статистика недоступна";
      });
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function loadList() {
    return api("/api/mod/applications").then(function (data) {
      var ul = document.getElementById("applications");
      ul.innerHTML = "";
      data.applications.forEach(function (a) {
        var li = document.createElement("li");
        li.className = "card";
        li.innerHTML =
          "<div><strong>#" +
          a.id +
          "</strong> — " +
          statusRu(a.status) +
          "</div>" +
          "<div class='meta'>Клиент: " +
          escapeHtml(a.user_login) +
          " · " +
          escapeHtml(a.site_url) +
          "</div>" +
          (a.moderator_note ? "<div class='meta'>Заметка: " + escapeHtml(a.moderator_note) + "</div>" : "") +
          "<div class='actions' data-id='" +
          a.id +
          "'></div>" +
          "<div class='stats-wrap'></div>";

        var actions = li.querySelector(".actions");
        var statsWrap = li.querySelector(".stats-wrap");

        if (a.status === "pending") {
          var bOk = document.createElement("button");
          bOk.textContent = "Одобрить";
          bOk.addEventListener("click", function () {
            api("/api/mod/applications/" + a.id + "/approve", { method: "PUT", body: "{}" }).then(function () {
              return loadList();
            });
          });
          var bNo = document.createElement("button");
          bNo.textContent = "Отклонить";
          bNo.className = "danger";
          bNo.addEventListener("click", function () {
            var note = window.prompt("Комментарий (необязательно)", "") || "";
            api("/api/mod/applications/" + a.id + "/reject", {
              method: "PUT",
              body: JSON.stringify({ note: note }),
            }).then(function () {
              return loadList();
            });
          });
          actions.appendChild(bOk);
          actions.appendChild(bNo);
        } else {
          var bStat = document.createElement("button");
          bStat.textContent = "Показать статистику";
          bStat.className = "secondary";
          bStat.addEventListener("click", function () {
            if (statsWrap.dataset.loaded) {
              statsWrap.innerHTML = "";
              delete statsWrap.dataset.loaded;
              bStat.textContent = "Показать статистику";
              return;
            }
            loadStats(a.id, statsWrap);
            statsWrap.dataset.loaded = "1";
            bStat.textContent = "Скрыть статистику";
          });
          actions.appendChild(bStat);
        }

        ul.appendChild(li);
      });
    });
  }

  renderAuthBar();
  if (localStorage.getItem(TOKEN_KEY)) {
    document.getElementById("login-section").hidden = true;
    document.getElementById("list-section").hidden = false;
    loadList().catch(function () {
      setToken(null);
      document.getElementById("login-section").hidden = false;
      document.getElementById("list-section").hidden = true;
      renderAuthBar();
    });
  }
})();
