# BTC TON Revenue Auto Claimer

Multi-account auto claimer with service manager for BTC TON Revenue.

## Fitur

- Multi akun (simpan banyak initData)
- Auto claim + bypass captcha
- Manual claim dengan loop kontrol
- Service mode (jalan di background)
- Manajemen akun (tambah/lihat/hapus)

## Instalasi

```bash
pkg update && pkg upgrade -y
pkg install php curl -y

git clone https://github.com/bowowiwendi/btc.git
cd btc
```

## Cara Pakai

### Menu Interaktif

```bash
php claim.php
```

| Opsi | Fungsi |
|------|--------|
| 1 | Tambah Akun |
| 2 | Lihat Akun |
| 3 | Hapus Akun |
| 4 | Start Service (background) |
| 5 | Stop Service |
| 6 | Restart Service |
| 7 | Lihat Log |
| 8 | Manual Claim (loop) |
| 0 | Keluar |

### Cara Mendapatkan initData

1. Buka bot BTC TON Revenue di Telegram
2. Buka Inspect Element / DevTools
3. Tab Network → cari request ke `btc.tonrevenue.space`
4. Ambil value `initData` dari request body
5. Masukkan ke menu Tambah Akun

### Service Mode

```bash
php claim.php
# Pilih menu 4 untuk start service (background)
# Buka menu 7 untuk lihat log real-time
# Pilih menu 5 untuk stop service
```

Service berjalan sebagai proses background terpisah. Aman keluar dari menu, service tetap jalan.

### Manual Claim

```bash
php claim.php
# Pilih menu 8
# Tekan q + Enter untuk stop loop
```

## File

| File | Fungsi |
|------|--------|
| `claim.php` | Main script |
| `accounts.json` | Data akun (auto-generated) |
| `service.log` | Log service (auto-generated) |
| `service.pid` | PID file service (auto-generated) |

## Catatan

- `initData` memiliki masa berlaku. Generate ulang jika expired.
- Jika kena `Unauthorize`, berarti initData tidak valid.
- Gunakan menu 8 (Manual Claim) untuk testing sebelum start service.
