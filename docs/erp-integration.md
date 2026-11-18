# Integrasi ERP DK

Toko bisa disambungkan ke ERP DK per company. Setelah tersambung, ERP menjadi sumber data: barang, harga, foto, dan stok
diambil dari ERP, dan setiap pesanan toko dicatat di ERP. Bawaannya mati; company yang tidak disambungkan tidak berubah.

## Menyambungkan

1. Di ERP (Pengaturan › Integrasi API), buat kunci API untuk pengguna integrasi dengan cakupan: `products.read`,
   `stock.read`, `customers.read`, `customers.write`, `sales_orders.read`, `sales_orders.write`, `payments.write`.
   Pengguna integrasi butuh izin buat/konfirmasi/batal SO dan buat DP. Bila harga jual dikunci di ERP, beri juga izin
   ubah harga (harga toko termasuk diskon/flash sale).
2. Di ERP, buat webhook ke alamat yang tampil di toko (Setting › Koneksi ERP), event `product.changed` dan
   `stock.changed`, lalu salin rahasianya.
3. Di toko, Setting › Koneksi ERP: isi alamat API (`https://<erp>/api/v1`), kunci API, rahasia webhook, id level harga
   (kosong = harga dasar), kode gudang sumber stok (pisahkan koma, kosong = semua gudang), dan akun ERP untuk pesanan.
   Klik **Tes koneksi**, lalu **Sinkron sekarang**, lalu nyalakan **Aktifkan**.

## Katalog dan stok

- Sinkron otomatis: barang/harga/foto tiap 15 menit (hanya yang berubah), stok tiap 5 menit, dan seketika lewat webhook.
- Stok toko = stok tersedia ERP (on hand − dipesan) dijumlah dari gudang sumber, minimal 0.
- Barang company tersambung tidak bisa ditambah, diubah, dihapus, atau diimpor di admin toko; ubah di ERP.
- Perintah manual: `php artisan erp:sync [--company=ID] [--full] [--stock]`.

## Pesanan

| Kejadian di toko | Di ERP |
|---|---|
| Checkout (Midtrans, transfer manual, pesanan manual admin) | Pelanggan dibuat bila belum ada (akun toko = `user-<id>`, tamu = `guest-…`), lalu SO **dikonfirmasi** dengan nomor pesanan toko sebagai `external_ref`: stok dipesan di gudang pertama. Stok ERP kurang = SO tetap dikonfirmasi sebagai backorder. Ongkir = baris jasa ke akun pendapatan ongkir. |
| Pembayaran lunas | DP atas SO sebesar uang diterima, ke akun penampung Midtrans atau rekening transfer manual. Invoice ERP nanti memotong DP ini otomatis. |
| Batal / kedaluwarsa sebelum lunas | SO dibatalkan, stok yang dipesan dilepas. |

- Checkout tidak pernah gagal karena ERP. Bila ERP tidak bisa dihubungi, galatnya tampil di detail transaksi admin
  (baris **ERP: …**) dan dikirim ulang otomatis tiap 5 menit (`php artisan erp:push-orders`).
- Kirim ulang aman: setiap panggilan membawa `Idempotency-Key`, SO dicocokkan lewat nomor pesanan, DP lewat id pembayaran.
- Pesanan yang sudah lunas lalu dibatalkan (refund) belum dikirim otomatis: catat pengembaliannya di ERP.
