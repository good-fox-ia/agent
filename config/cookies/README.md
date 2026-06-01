# Cookies для завантаження медіа (yt-dlp / gallery-dl)

Відео — **yt-dlp**. Фото Instagram (`/p/` без відео) — **gallery-dl** (ті самі cookies у `instagram.txt`).

Файли з cookies **не комітяться** (каталог `var/` у `.gitignore`).

## Шляхи

| Платформа  | Файл на хості (у репозиторії)     | Шлях у Docker-контейнері                    |
|------------|-----------------------------------|---------------------------------------------|
| YouTube    | `var/cookies/youtube.txt`         | `/var/www/html/var/cookies/youtube.txt`     |
| Instagram  | `var/cookies/instagram.txt`       | `/var/www/html/var/cookies/instagram.txt`   |

## .env.local

Один активний файл на раз (параметр `SOCIAL_VIDEO_COOKIES_FILE`):

```bash
# YouTube (менше 403):
SOCIAL_VIDEO_COOKIES_FILE=/var/www/html/var/cookies/youtube.txt

# Instagram (логін):
# SOCIAL_VIDEO_COOKIES_FILE=/var/www/html/var/cookies/instagram.txt
```

Після зміни: `docker compose restart messenger-worker`

## Формат

Netscape HTTP Cookie File (перший рядок часто `# Netscape HTTP Cookie File`).
