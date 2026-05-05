# Laravel NOS — Neo Object Storage Service

Microservice berbasis Laravel 12 untuk mengelola upload, list, dan delete file ke **Neo Object Storage (NOS)** menggunakan S3-compatible API.

> [!IMPORTANT]
> Service ini dioptimalkan untuk **Low Resource (RAM 1GB)** menggunakan strategi **Hybrid Disk-Queue** agar tidak membebani memory Redis dan CPU.

---

## Fitur

- ✅ **Async Upload:** Response instan (202 Accepted) sementara upload diproses di background.
- ✅ **RAM-Safe:** Menggunakan path file di antrian (bukan binary), hemat RAM secara signifikan.
- ✅ **Redis Cache & Queue:** Performa tinggi dengan beban database minimal.
- ✅ **Auto-Bucket Policy:** Otomatis menerapkan `public-read` pada bucket.
- ✅ **JWT & Secret Auth:** Keamanan API terjamin.

---

## Persyaratan

- PHP >= 8.2
- Redis Server (Wajib untuk Queue & Cache)
- MySQL/MariaDB
- PM2 (Rekomendasi untuk mengelola worker)

---

## Instalasi

```bash
git clone <repo-url>
cd laravel-nos

composer install
cp .env.example .env
php artisan key:generate
```

### Konfigurasi `.env`

```env
# Queue & Cache (Wajib Redis untuk performa)
QUEUE_CONNECTION=redis
CACHE_STORE=redis

# Redis Config
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_QUEUE_RETRY_AFTER=310

# Neo Object Storage (NOS)
NEO_ACCESS_KEY=your-access-key
NEO_SECRET_KEY=your-secret-key
NEO_ENDPOINT=your-nos-endpoint
NEO_BUCKET=your-bucket-name
NEO_REGION=your-region

# Security
SERVER_SECRET_KEY=your-secret-key
```

---

## Menjalankan Worker (Low Resource Mode)

Gunakan **PM2** untuk menjalankan worker dengan batasan RAM agar server tidak hang:

```bash
pm2 start "php artisan queue:work redis --sleep=3 --tries=3 --memory=64 --max-jobs=100" --name nos-worker
```

> **Catatan:** Jika Anda mengubah kode di `App\Jobs\ProcessS3Upload`, Anda **WAJIB** menjalankan `pm2 restart nos-worker`.

---

## API Endpoints

### Upload File (Async)
`POST /api/upload`

| Parameter | Keterangan |
|---|---|
| `secret` | Server secret key |
| `file` | File (max 50MB) |
| `client` | Identifier client |

**Limit:** 100 request / menit.

---

## Troubleshooting

### Error: "Too Many Requests" (429)
Tunggu 1 menit atau naikkan limit di `routes/api.php` pada middleware `throttle`.

### Error: "File tidak ditemukan" (Queue)
1. Pastikan folder `storage` writable: `sudo chmod -R 777 storage`.
2. Pastikan worker di-restart: `pm2 restart nos-worker`.
3. Pastikan konfigurasi `local` disk di `config/filesystems.php` sudah benar.

### Error: "Access Denied" (S3 Link)
Jalankan perintah untuk me-refresh policy bucket:
```bash
php artisan storage:apply-policy
```

---

## Maintenance

```bash
# Bersihkan antrian gagal
php artisan queue:flush

# Monitor status worker
pm2 status
pm2 logs nos-worker

# Cek penggunaan RAM Redis
redis-cli info memory | grep used_memory_human
```

---

## Lisensi
MIT
