<?php
/**
 * PENGATURAN KATALOG BUKU - Perpustakaan SMPN 6 Keritang
 */
return [
    // Lokasi folder SLiMS di hosting
    'folder_slims' => '/home/bzydmpxm/public_html',

    // Alamat website SLiMS (untuk tautan ke OPAC & login anggota)
    'url_opac' => 'https://otomasismpn6keritang.my.id',

    // Database: dibaca otomatis dari SLiMS. Isi hanya jika pembacaan otomatis gagal.
    'db_host' => '', 'db_port' => '3306', 'db_nama' => '', 'db_user' => '', 'db_pass' => '',

    // Jumlah buku per halaman hasil pencarian
    'per_halaman' => 12,
];
