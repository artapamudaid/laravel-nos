# Laravel NOS — Neo Object Storage Service

Microservice berbasis Laravel 12 untuk mengelola upload, list, dan delete file ke **Neo Object Storage (NOS)** menggunakan S3-compatible API. Upload diproses secara **asynchronous via Laravel Queue** sehingga response API langsung cepat.

---

## Fitur

- ✅ Upload file ke NOS via queue (async) — response langsung tanpa menunggu upload selesai
- ✅ List file berdasarkan client & folder
- ✅ View metadata file
- ✅ Delete file
- ✅ Bucket auto-created jika belum ada
- ✅ Bucket policy public-read otomatis diterapkan
- ✅ JWT Auth untuk keamanan API
- ✅ Artisan command untuk apply/refresh bucket policy

---

## Persyaratan

- PHP >= 8.2
- Laravel 12
- MySQL (untuk queue & session driver)
- Supervisor (untuk menjalankan queue worker di production)

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
# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel_nos
DB_USERNAME=root
DB_PASSWORD=secret

# Queue & Session
QUEUE_CONNECTION=database
SESSION_DRIVER=database

# Neo Object Storage (NOS)
NEO_ACCESS_KEY=your-access-key
NEO_SECRET_KEY=your-secret-key
NEO_ENDPOINT=your-nos-endpoint
NEO_BUCKET=your-bucket-name
NEO_REGION=your-region
NEO_USE_SSL=false

# Security
SERVER_SECRET_KEY=your-secret-key
JWT_SECRET=your-jwt-secret
```

### Setup Database & Queue

```bash
php artisan migrate
```

---

## Menjalankan Queue Worker

### Development

```bash
php artisan queue:work --tries=3 --timeout=300
```

### Production (Supervisor)

Install Supervisor:

```bash
sudo apt-get install supervisor -y
sudo systemctl enable --now supervisor
```

Buat file konfigurasi `/etc/supervisor/conf.d/nos-worker.conf`:

```ini
[program:nos-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/nos-service-laravel/artisan queue:work database --sleep=3 --tries=3 --timeout=300 --max-time=3600
directory=/var/www/nos-service-laravel
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/nos-service-laravel/storage/logs/worker.log
stopwaitsecs=3600
```

Aktifkan worker:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start nos-queue-worker:*
sudo supervisorctl status
```

---

## API Endpoints

Semua endpoint menggunakan **`secret`** sebagai autentikasi (POST body / query param).

### Upload File

```
POST /api/upload
Content-Type: multipart/form-data
```

| Parameter | Tipe   | Keterangan                    |
| --------- | ------ | ----------------------------- |
| `secret`  | string | Server secret key             |
| `file`    | file   | File yang diupload (max 50MB) |
| `client`  | string | Identifier client/tenant      |
| `folder`  | string | Sub-folder (opsional)         |

**Response `202 Accepted`:**

```json
{
    "success": true,
    "message": "Sukses Upload",
    "url": "https://your-nos-endpoint/bucket/client/uploads/folder/uuid.jpg"
}
```

> URL dikembalikan langsung meskipun upload masih diproses di background queue.

---

### List File

```
GET /api/list?secret=xxx&client=xxx&folder=xxx
```

**Response:**

```json
{
    "success": true,
    "files": ["https://your-nos-endpoint/bucket/client/uploads/folder/file.jpg"]
}
```

---

### View Metadata File

```
POST /api/view
```

| Parameter | Tipe   | Keterangan        |
| --------- | ------ | ----------------- |
| `secret`  | string | Server secret key |
| `url`     | string | URL lengkap file  |

**Response:**

```json
{
    "url": "https://...",
    "key": "client/uploads/folder/file.jpg",
    "size": 102400,
    "type": "image/jpeg",
    "last_modified": "2026-05-05 10:00:00"
}
```

---

### Delete File

```
POST /api/delete
```

| Parameter | Tipe   | Keterangan                    |
| --------- | ------ | ----------------------------- |
| `secret`  | string | Server secret key             |
| `file`    | string | URL lengkap file yang dihapus |

---

## Artisan Commands

### Apply Bucket Policy (Public Read)

Gunakan command ini untuk memastikan bucket bisa diakses publik (tanpa ACL per-object):

```bash
php artisan storage:apply-policy
```

Atau untuk bucket tertentu:

```bash
php artisan storage:apply-policy --bucket=nama-bucket
```

> **Kapan perlu dijalankan?**
>
> - Pertama kali setup di server baru
> - Setelah error **Access Denied** saat mengakses URL file
> - Setelah membuat bucket baru secara manual

---

## Alur Upload (Async Queue)

```
Client → POST /api/upload
           │
           ├─ Validasi file & secret
           ├─ Generate UUID filename
           ├─ Baca binary content file
           ├─ Dispatch job ke queue → return URL langsung (202)
           │
           └─ [Background] ProcessS3Upload Job
                 ├─ Connect ke NOS S3
                 ├─ Pastikan bucket ada
                 ├─ Apply public-read policy
                 └─ putObject ke NOS
```

---

## Deployment ke Production

```bash
# 1. Pull kode terbaru
git pull

# 2. Apply bucket policy (jika pertama kali atau setelah error Access Denied)
php artisan storage:apply-policy

# 3. Restart queue worker agar load kode baru
php artisan queue:restart

# 4. (Opsional) Bersihkan failed jobs lama
php artisan queue:flush

# 5. Reload Supervisor
sudo supervisorctl restart nos-queue-worker:*
```

---

## Struktur File Penting

```
app/
├── Console/Commands/
│   └── ApplyBucketPolicy.php   # Artisan command apply bucket policy
├── Http/Controllers/
│   └── StorageController.php   # Controller upload/list/view/delete
└── Jobs/
    └── ProcessS3Upload.php     # Queue job untuk upload ke NOS

routes/
├── api.php                     # API routes
└── console.php                 # Console routes

bootstrap/
└── app.php                     # Registrasi commands & middleware
```

---

## Troubleshooting

### Access Denied saat akses URL file

```bash
php artisan storage:apply-policy
```

### Queue worker tidak berjalan

```bash
sudo supervisorctl status
sudo supervisorctl start nos-queue-worker:*
```

### Failed jobs menumpuk

```bash
php artisan queue:failed       # lihat daftar
php artisan queue:flush        # hapus semua
php artisan queue:retry all    # retry semua
```

### Command artisan tidak ditemukan

Pastikan command sudah didaftarkan di `bootstrap/app.php`:

```php
->withCommands([
    App\Console\Commands\ApplyBucketPolicy::class,
])
```

---

## License

MIT
