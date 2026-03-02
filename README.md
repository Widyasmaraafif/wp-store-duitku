# WP Store Duitku Extension

Ekstensi pembayaran Duitku Gateway untuk plugin **WP Store**. Ekstensi ini memungkinkan toko Anda menerima pembayaran otomatis melalui berbagai metode yang didukung oleh Duitku (Virtual Account, E-Wallet, Retail Outlet, dll).

## Fitur
- Integrasi mulus dengan halaman pengaturan WP Store.
- Mendukung mode Sandbox (Testing) dan Production (Live).
- Pengalihan otomatis ke halaman pembayaran Duitku setelah checkout.
- Update status pesanan otomatis via Callback/IPN (Instant Payment Notification).
- Pencatatan status pembayaran pada meta pesanan.

## Persyaratan
- Plugin **WP Store** versi 1.0.0 atau lebih baru sudah aktif.
- Akun merchant aktif di [Duitku](https://duitku.com).

## Instalasi
1. Pastikan plugin **WP Store** sudah terpasang dan aktif.
2. Unggah folder `wp-store-duitku` ke direktori `/wp-content/plugins/`.
3. Aktifkan plugin **WP Store Duitku** melalui menu 'Plugins' di WordPress.

## Konfigurasi
1. Buka menu **WP Store > Pengaturan**.
2. Masuk ke tab **Pembayaran**.
3. Centang metode pembayaran **Duitku**.
4. Isi kolom berikut:
   - **Merchant Code**: Didapat dari dashboard Duitku.
   - **API Key**: Didapat dari dashboard Duitku.
   - **Mode**: Pilih 'Sandbox' untuk pengujian atau 'Production' untuk live.
5. Salin **Callback URL** yang tertera di panel pengaturan dan tempelkan ke dashboard Duitku Anda pada bagian konfigurasi proyek/proyek API.
6. Klik **Simpan Pengaturan**.

## Teknis
- **Endpoint Callback**: `[site-url]/wp-json/wp-store/v1/duitku/callback`
- **Filter Hook**: Ekstensi ini menggunakan filter `wp_store_payment_init` dan `wp_store_allowed_payment_methods` dari plugin utama.
- **Namespace**: `WP_Store_Duitku`

## Lisensi
GPL2
