# Integrasi ERP DK

Toko bisa disambungkan ke ERP DK per company. Setelah tersambung, ERP menjadi sumber data: barang, harga, foto, dan stok
diambil dari ERP, dan setiap pesanan toko dicatat di ERP. Bawaannya mati; company yang tidak disambungkan tidak berubah.

## Menyambungkan

1. Di ERP (Pengaturan › Integrasi API), buat kunci API untuk pengguna integrasi dengan cakupan: `products.read`,
   `stock.read`, `customers.read`, `customers.write`, `sales_orders.read`, `sales_orders.write`, `payments.write`.
   Pengguna integrasi butuh izin buat/konfirmasi/batal SO dan buat DP. Bila harga jual dikunci di ERP, beri juga izin
   ubah harga (harga toko termasuk diskon/flash sale).
2. Di ERP, buat webhook ke alamat yang tampil di toko (Setting › Koneksi ERP), event `product.changed`,
   `product.deleted`, `stock.changed`, `sales_order.updated`, `sales_delivery.updated`, `sales_delivery.received`,
   `sales_invoice.updated`, dan `sales_invoice.paid`, lalu salin rahasianya. Kunci API juga butuh
   `sales_invoices.read` untuk status invoice.
3. Di toko, Setting › Koneksi ERP: isi alamat API (`https://<erp>/api/v1`), kunci API, rahasia webhook, id level harga
   (kosong = harga dasar), kode gudang sumber stok (pisahkan koma, kosong = semua gudang), dan akun ERP untuk pesanan.
   Klik **Tes koneksi**, lalu **Sinkron sekarang**, lalu nyalakan **Aktifkan**.

## Katalog dan stok

- Sinkron otomatis: barang/harga/foto tiap 15 menit (hanya yang berubah), stok tiap 5 menit, dan seketika lewat webhook.
- Stok toko = stok tersedia ERP (on hand − dipesan) dijumlah dari gudang sumber, minimal 0.
- Barang dihapus di ERP = ikut dihapus di toko (webhook `product.deleted`, pilih event ini di webhook ERP). Barang yang
  sudah pernah dibeli hanya disembunyikan (stok 0) supaya riwayat pesanan tetap utuh. Tarik penuh tiap jam ikut
  membersihkan barang yang terlewat webhook. Barang dinonaktifkan di ERP = disembunyikan di toko.
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

## Status pesanan dari ERP

Setiap kabar pesanan dari ERP membuat toko mengambil ulang SO-nya, lalu menyesuaikan transaksi:

| Di ERP | Di toko |
|---|---|
| Surat jalan diposting | Status **Kirim**, resi = no. resi surat jalan (kurir sendiri tanpa resi = nomor surat jalan), kurir = ekspedisi surat jalan. Pembeli mendapat pemberitahuan "Pesanan Dalam Perjalanan". No. resi diubah di ERP = resi toko ikut berubah. |
| Semua barang terkirim dan setiap surat jalan punya bukti terima | Status **Selesai**, poin loyalti diberikan seperti saat pembeli menandai pesanan diterima. |
| SO dibatalkan di ERP sebelum pesanan lunas | Status **Dibatalkan**, stok, promo, dan poin yang ditahan dilepas. Pesanan yang sudah lunas tidak dibatalkan otomatis: urus refund di ERP. |
| Invoice diposting / lunas | Nomor invoice ERP dan tanda lunas tampil di detail transaksi admin. |

Setiap perubahan dicatat di riwayat status transaksi. Bila webhook terlewat, `php artisan erp:push-orders --refresh`
(otomatis tiap jam) mengambil ulang status SO pesanan yang masih berjalan (60 hari terakhir).


## Dokumen B2B

Company yang tersambung membuat dan memproses dokumen B2B di ERP: penawaran, sales order, proforma, surat jalan,
invoice B2B, dan pembayaran dokumen. Di admin toko, halaman B2B menampilkan keterangan "Dokumen B2B perusahaan ini
dibuat dan diproses di ERP" beserta alamat ERP. Dokumen lama tetap bisa dilihat dan dicetak. Aksi buat, ubah, kirim,
konversi, catat bayar, dan batal diarahkan kembali ke daftar dengan pesan yang sama (permintaan JSON = 423 + `erp_url`).
Company yang tidak tersambung tidak berubah.

## Invoice untuk pembeli

Tombol Invoice di pesanan pembeli (web dan aplikasi ponsel) menampilkan PDF invoice ERP begitu pesanan ditagih di
ERP. PDF diambil server toko dengan kunci API, jadi kunci tidak pernah sampai ke browser. Selama belum ditagih, atau
bila ERP tidak bisa dihubungi, yang tampil invoice toko seperti biasa (`?shop=1` = selalu invoice toko).

## Penjadwal

Semua sinkron berkala butuh cron Laravel di server:
`* * * * * cd /home/boq.co.id/public_html && /usr/local/lsws/lsphp83/bin/php artisan schedule:run >> /dev/null 2>&1`
(terpasang untuk pengguna situs sejak 18/11/2026).
