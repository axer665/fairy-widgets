# TLS-сертификаты для reverse-proxy (Apache)

В этой директории должны лежать два файла (имена фиксированы в конфиге Apache):

| Файл | Назначение |
|------|------------|
| `mylittlepony.crt` | Сертификат (или цепочка: сертификат + промежуточные CA в одном файле в правильном порядке) |
| `mylittlepony.key` | Приватный ключ (без пароля или настройте `SSLPassPhraseDialog` отдельно) |

После добавления файлов перезапустите прокси:

```bash
docker compose restart proxy
```

**Локальный самоподписанный сертификат (для проверки)**

```bash
cd ssl
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout mylittlepony.key -out mylittlepony.crt \
  -subj "/CN=localhost"
```

Браузер покажет предупреждение — это нормально для self-signed.

**Важно**

- Файлы **не коммитьте** в git (они в `.gitignore`).
- `ServerName` в `proxy/httpd-vhosts.conf` должен совпадать с **CN/SAN** сертификата, иначе браузер будет ругаться на несовпадение имени.
- Снаружи публикуются порты **80** (редирект 301 на HTTPS) и **443** (основной трафик). Публичный URL задайте переменной **`PUBLIC_BASE_URL`** (например `https://example.com`) в `.env` рядом с compose или в окружении — от неё зависят `APP_URL` у backend и `NUXT_DEV_ORIGIN` у fe-client в `docker-compose.yml`.
- Раньше использовался порт **8080**; теперь по умолчанию **80/443**. Чтобы оставить нестандартные порты, задайте маппинг в `docker-compose.override.yml` (например `8080:80`, `8443:443`) и выставьте `PUBLIC_BASE_URL` с нужным портом.
