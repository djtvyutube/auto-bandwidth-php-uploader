<div align="center">

# Auto-Bandwidth PHP Uploader

**High-Performance Concurrent Chunked File & Folder Uploader with Dynamic Auto-Bandwidth Adjustment & Real-Time Logging**

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%205.6-777bb4.svg)](https://www.php.net/)
[![Platform](https://img.shields.io/badge/Platform-Linux%20%7C%20Windows%20%7C%20macOS-lightgrey.svg)](#)

---

### Select Language / Dil Secimi
[English (Default)](#english) &nbsp;|&nbsp; [Turkce README](#turkce-readme)

---

</div>

<a name="english"></a>
## English

### Overview
**Auto-Bandwidth PHP Uploader** is a lightweight, single-file (`index.php`) web uploader designed for uploading large files, directories, and nested folder trees without running into PHP's default file size limitations.

It slices files into 1.5 MB binary chunks, dynamically modulates client-side concurrency based on connection health and throughput, supports 0-byte file preservation, and tracks all events into a thread-safe `log.json` file.

> [!IMPORTANT]
> **Encountering upload or permission errors?**
> If you get HTTP 500 errors, `ERROR_DIR_PERMISSION`, `ERROR_DIR_NOT_WRITABLE`, or `cant write` errors on your server, give full write permissions to the upload directory:
> ```bash
> chmod -R 777 /path/to/upload-folder
> ```
> Or adjust ownership to your web server user:
> ```bash
> chown -R www-data:www-data /path/to/upload-folder
> # or for cPanel / CentOS / Nginx:
> chown -R nginx:nginx /path/to/upload-folder
> ```

---

### Features
- **Smart Dynamic Bandwidth Engine**: Starts with 6 parallel streams and dynamically scales up as chunks complete successfully, or throttles down on network congestion.
- **Configurable Concurrency**: Choose between Auto (Dynamic), 1, 4, 8, 16, 32, 64, 128 parallel streams, or set any custom stream count.
- **Chunked Upload Pipeline**: Slices files into 1.5 MB chunks on client-side and reassembles them sequentially on the server.
- **Recursive Folder Uploading**: Preserves deep folder structures with full nested directory paths.
- **Zero-Byte File Support**: Handles empty files (`0 bytes`) without hanging or errors.
- **Concurrent File-Locked Logging**: Writes real-time server actions and client IPs into `log.json` using non-blocking atomic flock locks.
- **Live Metrics**: Real-time upload speed calculation, remaining time estimates (ETA), progress bar, and instant server log viewer.
- **Zero Dependencies**: Pure PHP + Vanilla Modern JavaScript. No Composer, Node.js, or external JS frameworks required.

---

### Installation & Usage

1. **Clone or Download** this repository:
   ```bash
   git clone https://github.com/djtvyutube/auto-bandwidth-php-uploader.git
   ```

2. **Copy `index.php`** into your web server directory (Apache, Nginx, LiteSpeed, etc.).

3. **Set Permissions**:
   ```bash
   chmod -R 777 .
   ```

4. Open your browser and navigate to the script:
   ```
   http://your-server-ip-or-domain/index.php
   ```

5. Drag & drop files or folders, select concurrency mode, and start uploading.

---
<br>

<a name="turkce-readme"></a>
## Turkce README

### Genel Bakis
**Auto-Bandwidth PHP Uploader**, PHP'nin varsayilan dosya boyutu sinirlamalarina takilmadan buyuk dosyalari, klasorleri ve ic ice dizin agaclarini yuklemek icin tasarlanmis tek dosyalik (`index.php`) hafif ve hızlı bir yukleyicidir.

Dosyalari 1.5 MB'lik ikili parcalara (chunk) boler, baglanti durumuna gore eszamanli kanal sayisini otomatik olarak ayarlar, 0 baytlik bos dosyalari basariyla korur ve tum islemleri eszamanli kilit mekanizmasina sahip `log.json` dosyasinda gunluge kaydeder.

> [!IMPORTANT]
> **Yukleme veya Izin Hatasi Alirsaniz:**
> Eger HTTP 500, dizin yazilamaz (`ERROR_DIR_PERMISSION`, `ERROR_DIR_NOT_WRITABLE`) veya yazma engeli gibi bir hata alirsaniz yukleyicinin bulundugu dizine 777 yazma izni verin:
> ```bash
> chmod -R 777 /yukleme-yapilan-klasor
> ```
> Ya da web sunucunuzun kullanicisina sahiplik verin:
> ```bash
> chown -R www-data:www-data /yukleme-yapilan-klasor
> # cPanel / CentOS / Nginx icin:
> chown -R nginx:nginx /yukleme-yapilan-klasor
> ```

---

### One Cikan Ozellikler
- **Akilli Dinamik Bant Genisligi**: 6 paralel kanalla baslar, aktarim sorunsuz gittikce hizi ve kanal sayisini dinamik olarak artirir; ag tikanirsa otomatik guvenli seviyeye ceker.
- **Secilebilir Eszamanlilik**: Otomatik Mod, 1, 4, 8, 16, 32, 64, 128 eszamanli dosya aktarimi veya dilediginiz ozel kanal sayisini girebilme imkani.
- **Parcali (Chunked) Yukleme**: Dosyalari istemci tarafinda 1.5 MB'lik parcalara ayirarak yukler ve sunucuda guvenle birlestirir (`upload_max_filesize` sinirini asar).
- **Klasor Agaci Destegi**: Klasorleri surukleyip biraktiginizda tum alt klasor yapilarini koruyarak olusturur.
- **0 Bayt Dosya Destegi**: Bos dosyalari hata vermeden aninda kaydeder.
- **Eszamanli JSON Gunlugu**: Tum istemci hareketlerini ve durumlari kilitlenme korumali (flock) `log.json` dosyasina isler.
- **Canli Gostergeler**: Anlik aktarim hizi, tahmini kalan sure (ETA), ilerleme cubugu ve ekran uzerinden sunucu loglarini okuma/temizleme araclari.
- **Sifir Bagimlilik**: Harici kutuphane veya paket gerektirmez; saf PHP ve saf Vanilla JavaScript ile calisir.

---

### Kurulum ve Kullanim

1. **Depoyu Indirin**:
   ```bash
   git clone https://github.com/djtvyutube/auto-bandwidth-php-uploader.git
   ```

2. **`index.php` dosyasini** sunucunuzdaki istediginiz dizine atin.

3. **Izinleri Verin**:
   ```bash
   chmod -R 777 .
   ```

4. Tarayicinizdan sayfayi acin ve dosyalarinizi yuklemeye baslayin:
   ```
   http://sunucu-ip-veya-domain/index.php
   ```

---

## License
This project is open-source and licensed under the [MIT License](LICENSE).
