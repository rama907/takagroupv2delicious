<?php
// File: includes/header.php

// === START: INLINE CSS UNTUK LOGO PERUSAHAAN DAN TEKS ===
// Styling ini mengatur tata letak Flexbox (gambar di samping teks)
?>
<style>
    /* Styling khusus untuk logo container */
    .header-left .logo {
        display: flex; /* Menggunakan Flexbox untuk menata item (gambar dan teks) */
        align-items: center;
        gap: 8px; /* Jarak antara logo dan teks */
        padding: 0;
        margin: 0;
        font-size: unset; /* Reset font size warisan */
    }
    /* Styling khusus untuk gambar logo di header */
    .header-logo-img {
        height: 35px; /* Sesuaikan ukuran gambar logo agar pas dengan header */
        width: auto;
        object-fit: contain;
        flex-shrink: 0;
    }
    /* Pastikan teks terlihat dan sesuai */
    .logo .logo-text {
        display: block !important; /* Pastikan teks terlihat */
        color: white; /* Warna teks yang kontras */
        font-weight: 600;
        font-size: 0.875rem; /* Ukuran font yang sesuai */
    }
    /* Sembunyikan elemen ikon lama (jika ada) */
    .logo .logo-icon {
        display: none !important;
    }
</style>
<?php
// === END: INLINE CSS UNTUK LOGO PERUSAHAAN DAN TEKS ===
?>
<header class="header">
    <div class="header-content">
        <div class="header-left">
            <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle Menu">
                <span class="hamburger-icon">☰</span>
            </button>
            <div class="logo">
                <img src="LOGO_WOT.png" alt="Delicious Logo" class="header-logo-img">
                <span class="logo-text">Delicious By TAKA Group</span>
            </div>
        </div>
        <div class="header-actions">
            <div class="user-menu">
                <span class="user-name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                <a href="logout.php" class="btn btn-outline btn-sm">Keluar</a>
            </div>
        </div>
    </div>
</header>