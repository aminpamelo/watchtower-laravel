# Watchtower SDK for Laravel

`pantau/watchtower-laravel` — SDK klien untuk platform monitoring **Watchtower**. Pasang pada aplikasi Laravel yang anda mahu pantau; ia menangkap exception, slow query, failed job, scheduled task yang gagal, dan metrik (requests, jobs, mail, cache, HTTP keluar, dsb.), lalu menghantarnya secara berkelompok ke server Watchtower anda.

## Keperluan

- PHP `^8.2`
- Laravel `^11.0 | ^12.0`

## Pemasangan

Package ini di-hos di GitHub (bukan Packagist), jadi daftar repo VCS dahulu:

```bash
composer config repositories.watchtower vcs https://github.com/aminpamelo/watchtower-laravel
composer require pantau/watchtower-laravel:^1.0
php artisan watchtower:install
```

Kemudian isi `.env`:

```dotenv
WATCHTOWER_ENABLED=true
WATCHTOWER_ENDPOINT=https://server-watchtower-anda/api/ingest
WATCHTOWER_TOKEN=token-ingest-anda
WATCHTOWER_ENVIRONMENT=production
WATCHTOWER_QUEUE=watchtower
```

Pastikan satu worker queue berjalan untuk queue `watchtower`, kemudian uji:

```bash
php artisan watchtower:test
```

Jika berjaya, server akan menerima event ujian (HTTP 202) dan ia muncul dalam dashboard Watchtower anda.

## Perintah

| Perintah | Fungsi |
|---|---|
| `watchtower:install` | Publish config dan tambah kunci `.env` |
| `watchtower:test` | Hantar exception ujian ke server (segerak) |
| `watchtower:flush-spool` | Kosongkan spool tempatan (event tertangguh) |

## Lesen

MIT.
