# Ringkasan Proyek RIMS - Stock Management System

## Gambaran Umum

RIMS (Request & Inventory Management System) adalah sistem web manajemen inventaris dan permintaan suku cadang yang dirancang untuk memantau alur stok barang dari penerimaan hingga pengiriman dengan pencatatan yang akurat dan real-time.

Aplikasi ini memungkinkan pengguna untuk mengelola stok suku cadang, melacak pergerakan stok, menangani permintaan bagian, dan menghasilkan laporan komprehensif.

---

## Alur Bisnis Utama

### 1. **Pergerakan Stok (Stock Movements)**
   - **Fungsi**: Mencatat setiap perubahan stok yang terjadi dalam sistem
   - **Fitur**:
     - Melacak semua pergerakan stok masuk dan keluar
     - Menyimpan informasi: jenis pergerakan (IN/OUT), kuantitas, stok akhir, waktu, dan pengguna yang bertindak
     - Filter berdasarkan: nomor bagian, tipe pergerakan, penanggung jawab, tanggal
     - Default filter otomatis ke bulan saat ini
     - Export laporan ke Excel dengan 2 sheet:
       - **Sheet Stock**: Snapshot stok terkini dari semua bagian
       - **Sheet Movements**: Riwayat detail pergerakan stok
   - **Tabel**: `movements` dengan kolom `part_id`, `type` (IN/OUT), `pic` (user_id), `qty`, `final_qty`, `created_at`

### 2. **Penerimaan Barang (Receivings)**
   - **Fungsi**: Mencatat penerimaan barang dari supplier atau sumber lainnya
   - **Fitur**:
     - Membuat batch penerimaan dengan nomor unik
     - Menambahkan multiple bagian dalam satu batch penerimaan
     - Mengisi informasi: nomor bagian, kuantitas yang diterima, unit penerima
     - Menyimpan detail penerima (PIC) dan tanggal penerimaan
     - Status penerimaan: draft/pending/completed
     - Pencarian bagian dengan autocomplete dan cache 6 jam
     - Validasi duplikasi: satu bagian hanya bisa diterima sekali per batch
   - **Otomasi**:
     - Ketika status diubah menjadi "completed", stok bagian secara otomatis bertambah
     - Mencatat pergerakan stok tipe "IN" dengan detail kuantitas dan stok akhir
   - **Tabel**: `receivings` dengan relasi ke `singleparts` (bagian yang diterima)

### 3. **Permintaan Bagian (Part Requests)**
   - **Fungsi**: Mengelola permintaan bagian dari departemen atau pengguna
   - **Fitur**:
     - Membuat request bagian dengan daftar bagian yang diminta
     - **QR Code Scanner**: Scan langsung kode QR bagian untuk input cepat dan akurat
     - Pencarian bagian dengan autocomplete (text atau QR code)
     - Menentukan kuantitas yang diminta
     - Proses langsung: tanpa perlu persetujuan manager
     - Validasi stok: memastikan bagian tersedia
     - Filter request berdasarkan bagian, status, tanggal
     - Riwayat lengkap permintaan yang dibuat
   - **Relasi**:
     - Satu request bisa memiliki multiple bagian (one-to-many dengan RequestList)
     - Setiap request terhubung dengan pengguna yang membuat
   - **Tabel**: `requests` dan `request_lists`

### 4. **Pengiriman Bagian (Outgoing Parts)**
   - **Fungsi**: Menangani pengeluaran/pengiriman bagian ke pengguna atau departemen
   - **Fitur**:
     - Membuat batch pengiriman dengan rincian bagian dan kuantitas
     - **QR Code Scanner**: Scan kode QR bagian untuk input cepat dan meminimalkan kesalahan
     - Pencarian bagian dengan autocomplete dan pencarian real-time
     - Validasi stok: pastikan stok cukup untuk pengiriman
     - Menyimpan informasi pengirim dan penerima
     - Status pengiriman: draft/pending/completed
     - Penyimpanan note/keterangan untuk setiap pengiriman
     - Submit batch pengiriman dengan validasi stok
   - **Otomasi**:
     - Ketika batch disubmit dan completed, stok bagian secara otomatis berkurang
     - Mencatat pergerakan stok tipe "OUT" dengan detail kuantitas dan stok akhir
     - Update status request terkait menjadi completed
   - **Tabel**: `outgoings` dengan relasi ke `singleparts`

### 5. **Notifikasi Real-time (Real-time Notifications)**
   - **Fungsi**: Mengirimkan notifikasi instan kepada pengguna untuk aktivitas penting dalam sistem
   - **Fitur**:
     - **Web Push Notifications**: Notifikasi browser real-time untuk pengguna yang subscribed
       - Notifikasi untuk permintaan part baru yang masuk
       - Menampilkan detail: tujuan, jumlah jenis part, total KBN
       - Icon dan badge dengan visual yang menarik
     - **Telegram Notifications**: Notifikasi via Telegram bot dengan format tabel terperinci
       - Koneksi otomatis: user klik tombol → diarahkan ke bot → konfirmasi otomatis
       - Notifikasi berisi:
         - Detail permintaan (tujuan, jenis part, total KBN, pembuat)
         - **Daftar item terperinci dalam format tabel**:
           - Kolom: PART NUMBER | STATUS
           - Menampilkan: Nomor bagian dan status (⚠️ URGENT atau ✓ Normal)
       - Tombol langsung untuk akses ke detail permintaan
       - Pesan konfirmasi saat user berhasil terhubung
     - **Pengaturan Notifikasi**: Halaman dedicated untuk mengelola koneksi Telegram
       - Lihat status koneksi saat ini
       - Hubungkan/putuskan akun Telegram kapan saja
       - Tampilkan Chat ID untuk reference
   - **Otomasi**:
     - Otomatis mengirim notifikasi saat permintaan part baru dibuat
     - Kirim ke semua user yang memiliki:
       - Web push subscription aktif, DAN/ATAU
       - Chat ID Telegram terhubung
     - Format pesan yang konsisten dan informatif
   - **Tabel & Config**: 
     - `users.telegram_user_id`: Menyimpan Telegram chat ID
     - `config/services.php`: Konfigurasi Telegram bot token dan username
   - **Routes**:
     - `/telegram-settings`: Halaman pengaturan Telegram (protected)
     - `/telegram/webhook`: Endpoint webhook untuk menerima update dari Telegram (public, CSRF exempt)

### 6. **Laporan Stok (Stock Reports)**
   - **Fungsi**: Menghasilkan laporan komprehensif tentang stok dan pergerakannya
   - **Fitur**:
     - Filter laporan berdasarkan:
       - Nomor bagian (text search)
       - Tipe pergerakan (IN/OUT)
       - Penanggung jawab (PIC)
       - Range tanggal (start date - end date)
     - Default otomatis ke bulan saat ini jika tidak ada filter
     - Export Excel dengan styling profesional:
       - Header berwarna dengan text putih dan bold
       - Auto-fit kolom sesuai konten
       - Border pada semua sel
       - Alignment center untuk kolom angka
     - 2 Sheet Excel:
       1. **Stock Sheet**: Tampilan snapshot stok terkini (7 kolom)
          - Part Number, Part Name, Customer Code, Supplier Code, Model, Variant, Stock
       2. **Movements Sheet**: Riwayat pergerakan stok terfilter (8 kolom)
          - Part Number, Model, Variant, Type, Acted By, Qty, Final Qty, Date
     - Pagination dengan opsi 10, 20, 50, atau 100 baris per halaman
     - Styling tabel dengan badge untuk tipe pergerakan (hijau untuk IN, merah untuk OUT)
   - **Data yang Dilacak**:
     - Setiap pergerakan mencatat: bagian apa, jenis (in/out), siapa yang melakukan, berapa kuantitas, stok akhir, kapan waktunya

---

## Model Data Utama

### Singlepart (Suku Cadang Tunggal)
- `part_number`: Nomor identitas bagian unik
- `part_name`: Nama bagian
- `model`: Model produk
- `variant`: Varian bagian
- `customer_code`: Kode pelanggan
- `supplier_code`: Kode supplier
- `stock`: Jumlah stok saat ini

### Movement (Pergerakan Stok)
- `part_id`: Referensi ke Singlepart
- `type`: Tipe pergerakan (IN atau OUT)
- `pic`: User ID yang melakukan pergerakan
- `qty`: Kuantitas yang bergerak
- `final_qty`: Stok final setelah pergerakan
- `created_at`: Waktu terjadinya pergerakan

### User
- `telegram_user_id`: Chat ID Telegram untuk notifikasi (nullable)
- Sistem authentication untuk setiap pengguna
- Tracking siapa yang melakukan setiap transaksi
- Relasi dengan push subscriptions untuk web push notifications

---

## Fitur Keamanan & Validasi

1. **Validasi Input**:
   - Cek kuantitas tidak boleh negatif
   - Cek stok cukup sebelum pengiriman
   - Cek duplikasi bagian dalam batch

2. **Audit Trail**:
   - Setiap pergerakan dicatat dengan user yang melakukan
   - Timestamp otomatis untuk setiap transaksi
   - Riwayat lengkap tersedia dalam laporan

3. **Search & Performance**:
   - Cache 6 jam untuk hasil pencarian bagian
   - Eager loading untuk menghindari N+1 query problem
   - Index pada field yang sering di-filter

---

## Teknologi & Stack

- **Framework**: Laravel 12
- **Frontend**: Livewire Volt (Functional Component API)
- **UI Components**: Mary UI
- **Notifications**: Web Push, Telegram Bot API
- **Notification Channels**: laravel-notification-channels/telegram, laravel-notification-channels/webpush
- **Database**: MySQL/MariaDB
- **Report Export**: Maatwebsite/Excel
- **Styling**: Tailwind CSS
- **Authentication**: Laravel Sanctum

---

## Use Case Utama

1. **Warehouse Staff**: 
   - Menerima barang masuk → mencatat di Receivings
   - Mengeluarkan barang → mencatat di Outgoings
   - Pantau stok real-time

2. **Department/User**:
   - Request bagian yang dibutuhkan → buat Part Request langsung
   - Scan QR code bagian untuk input cepat
   - Cek stok tersedia secara real-time
   - Hubungkan Telegram untuk notifikasi instan permintaan part baru

3. **Admin/Supervisor**:
   - Monitor semua pergerakan stok
   - Generate laporan bulanan
   - Analyze tren stok
   - Export data untuk reporting

4. **Finance/Planning**:
   - Use stock reports untuk perencanaan inventory
   - Track historical movement untuk forecasting

---

## Keuntungan Sistem

✅ **Tracking Real-time**: Setiap pergerakan stok langsung tercatat  
✅ **Audit Trail Lengkap**: Tahu siapa, kapan, berapa, dan apa yang terjadi  
✅ **Reporting Fleksibel**: Filter custom dan export format Excel profesional  
✅ **Validasi Otomatis**: Cegah stok negatif dan duplikasi  
✅ **QR Code Scanner**: Input cepat dan akurat dengan scanning barcode/QR code untuk request dan outgoing  
✅ **Multi-Channel Notifications**: Web push dan Telegram bot dengan format terperinci  
✅ **Automatic Telegram Linking**: User cukup klik tombol → otomatis terhubung ke bot tanpa manual input  
✅ **User-Friendly**: Interface intuitif dengan search, autocomplete, dan QR scanner  
✅ **Proses Langsung**: Tanpa workflow approval, users bisa langsung execute request  
✅ **Performa Optimal**: Cache dan query optimization untuk speed  
✅ **Mobile Responsive**: Akses dari berbagai device

---

## Roadmap Potensial (Future Enhancement)

- [x] QR Code Scanner untuk faster input (sudah implemented)
- [x] Stock Movement Tracking (sudah implemented)
- [x] Comprehensive Dashboard dengan real-time metrics (sudah implemented)
- [x] **Web Push Notifications** untuk notifikasi real-time (sudah implemented)
- [x] **Telegram Bot Integration** dengan automatic chat_id linking (sudah implemented)
- [x] **Detailed Item List dalam Telegram** dengan status urgency (sudah implemented)
- [ ] **Part-to-Part Traceability**: Serial number & QR code per unit bagian
  - Generate unique QR code per batch receiving
  - Track individual unit dari penerimaan hingga delivery
  - Riwayat pergerakan per unit bagian
- [ ] **Batch Serialization**: Automatic serial number generation saat receiving
- [ ] **QR Code Printing**: Generate & print stiker QR code untuk unit bagian
- [ ] **Defect Tracking**: Link defect ke specific batch/serial number
- [ ] Add movement notes/remarks field untuk keterangan detail
- [ ] Multi-warehouse support dengan traceability per warehouse
- [ ] Advanced Dashboard analytics dengan chart dan graph
- [ ] Email notifications untuk activity updates
- [ ] REST API untuk integrasi dengan sistem lain
- [ ] Mobile app native untuk field operations
- [ ] Batch import dari Excel untuk stock adjustment
- [ ] **Advanced Reporting**: Traceability report per serial number
- [ ] **Supplier Analytics**: Trend quality berdasarkan receiving batch data
