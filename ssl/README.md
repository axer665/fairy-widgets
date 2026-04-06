# TLS-сертификаты для reverse-proxy (Apache)

В этой директории должны лежать **три файла** (имена заданы в `proxy/httpd-vhosts.conf`):

| Файл | Назначение |
|------|------------|
| `mylittlefairy.crt` | Сертификат вашего домена (leaf / end-entity), **без** цепочки УЦ |
| `mylittlefairy.key` | Приватный ключ к этому сертификату (без пароля на диске или настройте `SSLPassPhraseDialog` отдельно) |
| `chain.crt` | Цепочка доверия УЦ: **промежуточный(е)** сертификат(ы), затем при необходимости **корневой** (порядок: от выпускающего к корню). Обычно даётся файл «full chain» / «bundle» от регистратора, разбейте: leaf → `mylittlefairy.crt`, остальное → `chain.crt`. |

В Apache это соответствует директивам:

- `SSLCertificateFile` → `mylittlefairy.crt`
- `SSLCertificateKeyFile` → `mylittlefairy.key`
- `SSLCertificateChainFile` → `chain.crt`

После добавления или замены файлов перезапустите прокси:

```bash
docker compose restart proxy
```

**Локальный самоподписанный сертификат (только для проверки)**

У настоящей УЦ нет промежуточных — для старта Apache можно временно подставить тот же файл в цепочку:

```bash
cd ssl
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout mylittlefairy.key -out mylittlefairy.crt \
  -subj "/CN=localhost"
cp mylittlefairy.crt chain.crt
```

Браузер покажет предупреждение — это нормально для self-signed.

**Важно**

- Эти файлы **не коммитьте** в git (см. `.gitignore`).
- `ServerName` в `proxy/httpd-vhosts.conf` должен совпадать с **CN/SAN** в `mylittlefairy.crt`.
- Порты **80** (редирект на HTTPS) и **443**; публичный URL — **`PUBLIC_BASE_URL`** в `.env`.
