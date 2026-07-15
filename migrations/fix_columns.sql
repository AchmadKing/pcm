-- =====================================================
-- FIX: Tambah kolom yang hilang di tabel requests
-- Jalankan SATU PER SATU di phpMyAdmin
-- Abaikan error jika kolom sudah ada
-- =====================================================

-- 1. Tambah description (JALANKAN INI DULU)
ALTER TABLE requests ADD COLUMN description TEXT AFTER request_date;

-- 2. Tambah week_number
ALTER TABLE requests ADD COLUMN week_number INT DEFAULT 1 AFTER request_date;

-- 3. Tambah admin_notes
ALTER TABLE requests ADD COLUMN admin_notes TEXT AFTER approved_at;

-- =====================================================
-- Selesai! Refresh browser
-- =====================================================
