<?php
/**
 * Unit Simpan Pinjam KDKMP
 * Aplikasi Manajemen Simpan Pinjam
 * Versi 1.0.0
 * Dibuat: 2026
 */

// ==================== KONFIGURASI DATABASE ====================
$db_file = __DIR__ . '/kdkmp.db';
$db = new SQLite3($db_file);

// Buat tabel jika belum ada
$db->exec("
    CREATE TABLE IF NOT EXISTS anggota (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        no_anggota TEXT UNIQUE,
        nama_lengkap TEXT NOT NULL,
        nik TEXT UNIQUE NOT NULL,
        tempat_lahir TEXT NOT NULL,
        tanggal_lahir DATE NOT NULL,
        jenis_kelamin TEXT CHECK(jenis_kelamin IN ('L', 'P')) NOT NULL,
        alamat TEXT NOT NULL,
        no_telepon TEXT NOT NULL,
        email TEXT UNIQUE,
        status TEXT DEFAULT 'aktif' CHECK(status IN ('aktif', 'nonaktif')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS simpanan (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        anggota_id INTEGER NOT NULL,
        no_transaksi TEXT UNIQUE NOT NULL,
        jenis_simpanan TEXT CHECK(jenis_simpanan IN ('wajib', 'pokok', 'sukarela')) NOT NULL,
        jumlah DECIMAL(15,2) NOT NULL,
        tanggal DATE NOT NULL,
        keterangan TEXT,
        status TEXT DEFAULT 'pending' CHECK(status IN ('pending', 'diterima', 'ditolak')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (anggota_id) REFERENCES anggota(id) ON DELETE CASCADE
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS pinjaman (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        anggota_id INTEGER NOT NULL,
        no_pinjaman TEXT UNIQUE NOT NULL,
        jumlah_pinjaman DECIMAL(15,2) NOT NULL,
        bunga DECIMAL(5,2) DEFAULT 1.5,
        tenor INTEGER NOT NULL,
        angsuran_per_bulan DECIMAL(15,2) NOT NULL,
        tanggal_pinjaman DATE NOT NULL,
        tanggal_jatuh_tempo DATE NOT NULL,
        status TEXT DEFAULT 'aktif' CHECK(status IN ('aktif', 'lunas', 'macet')),
        keterangan TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (anggota_id) REFERENCES anggota(id) ON DELETE CASCADE
    )
");

// ==================== FUNGSI BANTUAN ====================
function formatRupiah($angka) {
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

function getTotal($db, $table, $where = '') {
    $query = "SELECT COUNT(*) as total FROM $table";
    if ($where) $query .= " WHERE $where";
    $result = $db->querySingle($query);
    return $result ?: 0;
}

function getSum($db, $table, $column, $where = '') {
    $query = "SELECT SUM($column) as total FROM $table";
    if ($where) $query .= " WHERE $where";
    $result = $db->querySingle($query);
    return $result ?: 0;
}

function generateNoAnggota($db) {
    $count = getTotal($db, 'anggota');
    $no = str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    return 'KDKMP-' . date('Ymd') . '-' . $no;
}

function generateNoTransaksi($prefix) {
    return $prefix . '-' . date('YmdHis') . '-' . rand(100, 999);
}

function hitungAngsuran($jumlah, $bunga, $tenor) {
    $bungaPerBulan = $bunga / 100;
    $angsuran = ($jumlah * $bungaPerBulan * pow(1 + $bungaPerBulan, $tenor)) / (pow(1 + $bungaPerBulan, $tenor) - 1);
    return round($angsuran, 2);
}

// ==================== HANDLE REQUEST ====================
$action = isset($_GET['action']) ? $_GET['action'] : 'dashboard';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// --- Handle Form Submit ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_post = isset($_POST['action']) ? $_POST['action'] : '';
    
    // Tambah Anggota
    if ($action_post === 'tambah_anggota') {
        $no_anggota = generateNoAnggota($db);
        $stmt = $db->prepare("INSERT INTO anggota (no_anggota, nama_lengkap, nik, tempat_lahir, tanggal_lahir, jenis_kelamin, alamat, no_telepon, email) 
                              VALUES (:no_anggota, :nama, :nik, :tempat, :tgl_lahir, :jk, :alamat, :telepon, :email)");
        $stmt->bindValue(':no_anggota', $no_anggota);
        $stmt->bindValue(':nama', $_POST['nama_lengkap']);
        $stmt->bindValue(':nik', $_POST['nik']);
        $stmt->bindValue(':tempat', $_POST['tempat_lahir']);
        $stmt->bindValue(':tgl_lahir', $_POST['tanggal_lahir']);
        $stmt->bindValue(':jk', $_POST['jenis_kelamin']);
        $stmt->bindValue(':alamat', $_POST['alamat']);
        $stmt->bindValue(':telepon', $_POST['no_telepon']);
        $stmt->bindValue(':email', $_POST['email'] ?: null);
        $stmt->execute();
        header('Location: ?action=anggota&success=Anggota berhasil ditambahkan');
        exit;
    }
    
    // Tambah Simpanan
    if ($action_post === 'tambah_simpanan') {
        $no_transaksi = generateNoTransaksi('SMP');
        $stmt = $db->prepare("INSERT INTO simpanan (anggota_id, no_transaksi, jenis_simpanan, jumlah, tanggal, keterangan) 
                              VALUES (:anggota_id, :no_transaksi, :jenis, :jumlah, :tanggal, :keterangan)");
        $stmt->bindValue(':anggota_id', $_POST['anggota_id']);
        $stmt->bindValue(':no_transaksi', $no_transaksi);
        $stmt->bindValue(':jenis', $_POST['jenis_simpanan']);
        $stmt->bindValue(':jumlah', $_POST['jumlah']);
        $stmt->bindValue(':tanggal', $_POST['tanggal']);
        $stmt->bindValue(':keterangan', $_POST['keterangan'] ?: null);
        $stmt->execute();
        header('Location: ?action=simpanan&success=Simpanan berhasil ditambahkan');
        exit;
    }
    
    // Tambah Pinjaman
    if ($action_post === 'tambah_pinjaman') {
        $no_pinjaman = generateNoTransaksi('PJM');
        $jumlah = (float)$_POST['jumlah_pinjaman'];
        $bunga = (float)$_POST['bunga'];
        $tenor = (int)$_POST['tenor'];
        $tanggal_pinjaman = $_POST['tanggal_pinjaman'];
        $angsuran = hitungAngsuran($jumlah, $bunga, $tenor);
        $jatuh_tempo = date('Y-m-d', strtotime($tanggal_pinjaman . ' + ' . $tenor . ' months'));
        
        $stmt = $db->prepare("INSERT INTO pinjaman (anggota_id, no_pinjaman, jumlah_pinjaman, bunga, tenor, angsuran_per_bulan, tanggal_pinjaman, tanggal_jatuh_tempo, keterangan) 
                              VALUES (:anggota_id, :no_pinjaman, :jumlah, :bunga, :tenor, :angsuran, :tanggal, :jatuh_tempo, :keterangan)");
        $stmt->bindValue(':anggota_id', $_POST['anggota_id']);
        $stmt->bindValue(':no_pinjaman', $no_pinjaman);
        $stmt->bindValue(':jumlah', $jumlah);
        $stmt->bindValue(':bunga', $bunga);
        $stmt->bindValue(':tenor', $tenor);
        $stmt->bindValue(':angsuran', $angsuran);
        $stmt->bindValue(':tanggal', $tanggal_pinjaman);
        $stmt->bindValue(':jatuh_tempo', $jatuh_tempo);
        $stmt->bindValue(':keterangan', $_POST['keterangan'] ?: null);
        $stmt->execute();
        header('Location: ?action=pinjaman&success=Pinjaman berhasil ditambahkan');
        exit;
    }
    
    // Verifikasi Simpanan
    if ($action_post === 'verifikasi_simpanan') {
        $stmt = $db->prepare("UPDATE simpanan SET status = 'diterima' WHERE id = :id");
        $stmt->bindValue(':id', $_POST['id']);
        $stmt->execute();
        header('Location: ?action=simpanan&success=Simpanan berhasil diverifikasi');
        exit;
    }
    
    // Update Status Pinjaman
    if ($action_post === 'update_status_pinjaman') {
        $stmt = $db->prepare("UPDATE pinjaman SET status = :status WHERE id = :id");
        $stmt->bindValue(':status', $_POST['status']);
        $stmt->bindValue(':id', $_POST['id']);
        $stmt->execute();
        header('Location: ?action=pinjaman&success=Status pinjaman berhasil diupdate');
        exit;
    }
}

// --- Handle Delete ---
if (isset($_GET['delete']) && $id) {
    $table = $_GET['delete'];
    $stmt = $db->prepare("DELETE FROM $table WHERE id = :id");
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    header('Location: ?action=' . $table . '&success=Data berhasil dihapus');
    exit;
}

// ==================== RENDER VIEW ====================
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Simpan Pinjam KDKMP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --success-color: #2ecc71;
            --warning-color: #f39c12;
            --danger-color: #e74c3c;
        }
        .sidebar {
            min-height: 100vh;
            background: var(--primary-color);
            color: white;
            padding: 20px 0;
        }
        .sidebar a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 12px 20px;
            display: block;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        .sidebar a:hover, .sidebar a.active {
            background: rgba(255,255,255,0.1);
            border-left-color: var(--secondary-color);
            color: white;
        }
        .sidebar a i {
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }
        .logo-area {
            padding: 0 20px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .logo-area h4 {
            color: white;
            font-weight: bold;
        }
        .logo-area small {
            color: rgba(255,255,255,0.6);
        }
        .main-content {
            padding: 30px;
            background: #f5f7fa;
            min-height: 100vh;
        }
        .stat-card {
            border-radius: 12px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
            transition: transform 0.3s;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-card h3 {
            margin: 0;
            font-size: 28px;
            font-weight: bold;
        }
        .stat-card small {
            opacity: 0.8;
        }
        .stat-card .icon {
            float: right;
            font-size: 40px;
            opacity: 0.3;
        }
        .bg-blue { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .bg-green { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .bg-orange { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .bg-purple { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
        .bg-red { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .table-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .btn-action {
            margin: 2px;
        }
        .badge-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        .modal-content {
            border-radius: 15px;
        }
        .modal-header {
            background: var(--primary-color);
            color: white;
            border-radius: 15px 15px 0 0;
        }
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
                padding: 10px 0;
            }
            .main-content {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- ==================== SIDEBAR ==================== -->
            <div class="col-md-2 sidebar">
                <div class="logo-area">
                    <h4>🏦 KDKMP</h4>
                    <small>Unit Simpan Pinjam</small>
                </div>
                <div class="mt-3">
                    <a href="?action=dashboard" class="<?= $action == 'dashboard' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a href="?action=anggota" class="<?= $action == 'anggota' ? 'active' : '' ?>">
                        <i class="bi bi-people"></i> Anggota
                    </a>
                    <a href="?action=simpanan" class="<?= $action == 'simpanan' ? 'active' : '' ?>">
                        <i class="bi bi-wallet2"></i> Simpanan
                    </a>
                    <a href="?action=pinjaman" class="<?= $action == 'pinjaman' ? 'active' : '' ?>">
                        <i class="bi bi-credit-card"></i> Pinjaman
                    </a>
                </div>
                <div class="position-absolute bottom-0 start-0 w-100 p-3">
                    <hr class="border-secondary">
                    <small class="text-muted">Versi 1.0.0</small>
                </div>
            </div>
            
            <!-- ==================== MAIN CONTENT ==================== -->
            <div class="col-md-10 main-content">
                <!-- Alert -->
                <?php if (isset($_GET['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_GET['success']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($_GET['error']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php
                // ==================== DASHBOARD ====================
                if ($action == 'dashboard'): 
                    $totalAnggota = getTotal($db, 'anggota', "status = 'aktif'");
                    $totalSimpanan = getSum($db, 'simpanan', 'jumlah', "status = 'diterima'");
                    $totalPinjaman = getSum($db, 'pinjaman', 'jumlah_pinjaman', "status = 'aktif'");
                    $totalPinjamanLunas = getSum($db, 'pinjaman', 'jumlah_pinjaman', "status = 'lunas'");
                    $simpananBulanIni = getSum($db, 'simpanan', 'jumlah', "status = 'diterima' AND strftime('%m', tanggal) = strftime('%m', 'now')");
                    $pinjamanBulanIni = getSum($db, 'pinjaman', 'jumlah_pinjaman', "status = 'aktif' AND strftime('%m', tanggal_pinjaman) = strftime('%m', 'now')");
                ?>
                <div class="row">
                    <div class="col-12">
                        <h2 class="mb-4"><i class="bi bi-speedometer2"></i> Dashboard</h2>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="stat-card bg-blue">
                            <span class="icon"><i class="bi bi-people"></i></span>
                            <small>Total Anggota Aktif</small>
                            <h3><?= number_format($totalAnggota) ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-green">
                            <span class="icon"><i class="bi bi-wallet2"></i></span>
                            <small>Total Simpanan</small>
                            <h3><?= formatRupiah($totalSimpanan) ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-orange">
                            <span class="icon"><i class="bi bi-credit-card"></i></span>
                            <small>Pinjaman Aktif</small>
                            <h3><?= formatRupiah($totalPinjaman) ?></h3>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stat-card bg-purple">
                            <span class="icon"><i class="bi bi-check-circle"></i></span>
                            <small>Pinjaman Lunas</small>
                            <h3><?= formatRupiah($totalPinjamanLunas) ?></h3>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-6">
                        <div class="table-card">
                            <h5><i class="bi bi-arrow-up-circle"></i> Simpanan Bulan Ini</h5>
                            <h3><?= formatRupiah($simpananBulanIni) ?></h3>
                            <small class="text-muted">Total simpanan diterima bulan ini</small>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="table-card">
                            <h5><i class="bi bi-arrow-down-circle"></i> Pinjaman Bulan Ini</h5>
                            <h3><?= formatRupiah($pinjamanBulanIni) ?></h3>
                            <small class="text-muted">Total pinjaman disalurkan bulan ini</small>
                        </div>
                    </div>
                </div>
                
                <?php
                // ==================== ANGGOTA ====================
                elseif ($action == 'anggota'):
                    $anggotas = $db->query("SELECT a.*, 
                        (SELECT COUNT(*) FROM simpanan WHERE anggota_id = a.id) as total_simpanan,
                        (SELECT COUNT(*) FROM pinjaman WHERE anggota_id = a.id) as total_pinjaman
                        FROM anggota a ORDER BY id DESC");
                ?>
                <div class="row">
                    <div class="col-12">
                        <h2 class="mb-4"><i class="bi bi-people"></i> Data Anggota</h2>
                        <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#modalAnggota">
                            <i class="bi bi-plus-circle"></i> Tambah Anggota
                        </button>
                    </div>
                    <div class="col-12">
                        <div class="table-card">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>No Anggota</th>
                                            <th>Nama</th>
                                            <th>NIK</th>
                                            <th>Telepon</th>
                                            <th>Status</th>
                                            <th>Simpanan</th>
                                            <th>Pinjaman</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($row = $anggotas->fetchArray(SQLITE3_ASSOC)): ?>
                                        <tr>
                                            <td><strong><?= $row['no_anggota'] ?></strong></td>
                                            <td><?= htmlspecialchars($row['nama_lengkap']) ?></td>
                                            <td><?= htmlspecialchars($row['nik']) ?></td>
                                            <td><?= htmlspecialchars($row['no_telepon']) ?></td>
                                            <td>
                                                <span class="badge badge-status bg-<?= $row['status'] == 'aktif' ? 'success' : 'danger' ?>">
                                                    <?= ucfirst($row['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= $row['total_simpanan'] ?></td>
                                            <td><?= $row['total_pinjaman'] ?></td>
                                            <td>
                                                <a href="?action=anggota&delete=anggota&id=<?= $row['id'] ?>" 
                                                   class="btn btn-sm btn-danger" 
                                                   onclick="return confirm('Hapus data ini?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Tambah Anggota -->
                <div class="modal fade" id="modalAnggota" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-person-plus"></i> Tambah Anggota</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="tambah_anggota">
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Nama Lengkap</label>
                                            <input type="text" name="nama_lengkap" class="form-control" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">NIK</label>
                                            <input type="text" name="nik" class="form-control" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Tempat Lahir</label>
                                            <input type="text" name="tempat_lahir" class="form-control" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Tanggal Lahir</label>
                                            <input type="date" name="tanggal_lahir" class="form-control" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Jenis Kelamin</label>
                                            <select name="jenis_kelamin" class="form-select" required>
                                                <option value="L">Laki-laki</option>
                                                <option value="P">Perempuan</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">No Telepon</label>
                                            <input type="text" name="no_telepon" class="form-control" required>
                                        </div>
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Alamat</label>
                                            <textarea name="alamat" class="form-control" rows="2" required></textarea>
                                        </div>
                                        <div class="col-md-12 mb-3">
                                            <label class="form-label">Email</label>
                                            <input type="email" name="email" class="form-control">
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <?php
                // ==================== SIMPANAN ====================
                elseif ($action == 'simpanan'):
                    $simpanans = $db->query("SELECT s.*, a.nama_lengkap, a.no_anggota 
                                             FROM simpanan s 
                                             JOIN anggota a ON s.anggota_id = a.id 
                                             ORDER BY s.id DESC");
                    $anggotas = $db->query("SELECT id, no_anggota, nama_lengkap FROM anggota WHERE status = 'aktif'");
                ?>
                <div class="row">
                    <div class="col-12">
                        <h2 class="mb-4"><i class="bi bi-wallet2"></i> Data Simpanan</h2>
                        <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#modalSimpanan">
                            <i class="bi bi-plus-circle"></i> Tambah Simpanan
                        </button>
                    </div>
                    <div class="col-12">
                        <div class="table-card">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>No Transaksi</th>
                                            <th>Anggota</th>
                                            <th>Jenis</th>
                                            <th>Jumlah</th>
                                            <th>Tanggal</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($row = $simpanans->fetchArray(SQLITE3_ASSOC)): ?>
                                        <tr>
                                            <td><strong><?= $row['no_transaksi'] ?></strong></td>
                                            <td><?= htmlspecialchars($row['nama_lengkap']) ?><br>
                                                <small class="text-muted"><?= $row['no_anggota'] ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $row['jenis_simpanan'] == 'wajib' ? 'info' : ($row['jenis_simpanan'] == 'pokok' ? 'primary' : 'secondary') ?>">
                                                    <?= ucfirst($row['jenis_simpanan']) ?>
                                                </span>
                                            </td>
                                            <td><strong><?= formatRupiah($row['jumlah']) ?></strong></td>
                                            <td><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                            <td>
                                                <span class="badge badge-status bg-<?= $row['status'] == 'diterima' ? 'success' : ($row['status'] == 'pending' ? 'warning' : 'danger') ?>">
                                                    <?= ucfirst($row['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($row['status'] == 'pending'): ?>
                                                <form method="POST" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="verifikasi_simpanan">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <a href="?action=simpanan&delete=simpanan&id=<?= $row['id'] ?>" 
                                                   class="btn btn-sm btn-danger"
                                                   onclick="return confirm('Hapus data ini?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Tambah Simpanan -->
                <div class="modal fade" id="modalSimpanan" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-wallet-plus"></i> Tambah Simpanan</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="tambah_simpanan">
                                    <div class="mb-3">
                                        <label class="form-label">Anggota</label>
                                        <select name="anggota_id" class="form-select" required>
                                            <option value="">Pilih Anggota</option>
                                            <?php while($a = $anggotas->fetchArray(SQLITE3_ASSOC)): ?>
                                            <option value="<?= $a['id'] ?>"><?= $a['no_anggota'] ?> - <?= htmlspecialchars($a['nama_lengkap']) ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jenis Simpanan</label>
                                        <select name="jenis_simpanan" class="form-select" required>
                                            <option value="wajib">Wajib</option>
                                            <option value="pokok">Pokok</option>
                                            <option value="sukarela">Sukarela</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jumlah (Rp)</label>
                                        <input type="number" name="jumlah" class="form-control" min="10000" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Tanggal</label>
                                        <input type="date" name="tanggal" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Keterangan</label>
                                        <textarea name="keterangan" class="form-control" rows="2"></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <?php
                // ==================== PINJAMAN ====================
                elseif ($action == 'pinjaman'):
                    $pinjamans = $db->query("SELECT p.*, a.nama_lengkap, a.no_anggota 
                                             FROM pinjaman p 
                                             JOIN anggota a ON p.anggota_id = a.id 
                                             ORDER BY p.id DESC");
                    $anggotas = $db->query("SELECT id, no_anggota, nama_lengkap FROM anggota WHERE status = 'aktif'");
                ?>
                <div class="row">
                    <div class="col-12">
                        <h2 class="mb-4"><i class="bi bi-credit-card"></i> Data Pinjaman</h2>
                        <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#modalPinjaman">
                            <i class="bi bi-plus-circle"></i> Tambah Pinjaman
                        </button>
                    </div>
                    <div class="col-12">
                        <div class="table-card">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>No Pinjaman</th>
                                            <th>Anggota</th>
                                            <th>Jumlah</th>
                                            <th>Bunga</th>
                                            <th>Tenor</th>
                                            <th>Angsuran/Bln</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($row = $pinjamans->fetchArray(SQLITE3_ASSOC)): ?>
                                        <tr>
                                            <td><strong><?= $row['no_pinjaman'] ?></strong></td>
                                            <td><?= htmlspecialchars($row['nama_lengkap']) ?><br>
                                                <small class="text-muted"><?= $row['no_anggota'] ?></small>
                                            </td>
                                            <td><strong><?= formatRupiah($row['jumlah_pinjaman']) ?></strong></td>
                                            <td><?= $row['bunga'] ?>%</td>
                                            <td><?= $row['tenor'] ?> bln</td>
                                            <td><?= formatRupiah($row['angsuran_per_bulan']) ?></td>
                                            <td>
                                                <span class="badge badge-status bg-<?= $row['status'] == 'aktif' ? 'primary' : ($row['status'] == 'lunas' ? 'success' : 'danger') ?>">
                                                    <?= ucfirst($row['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($row['status'] == 'aktif'): ?>
                                                <form method="POST" style="display:inline-block;">
                                                    <input type="hidden" name="action" value="update_status_pinjaman">
                                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                                    <input type="hidden" name="status" value="lunas">
                                                    <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Tandai sebagai lunas?')">
                                                        <i class="bi bi-check-circle"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                <a href="?action=pinjaman&delete=pinjaman&id=<?= $row['id'] ?>" 
                                                   class="btn btn-sm btn-danger"
                                                   onclick="return confirm('Hapus data ini?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Modal Tambah Pinjaman -->
                <div class="modal fade" id="modalPinjaman" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-credit-card-plus"></i> Tambah Pinjaman</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body">
                                    <input type="hidden" name="action" value="tambah_pinjaman">
                                    <div class="mb-3">
                                        <label class="form-label">Anggota</label>
                                        <select name="anggota_id" class="form-select" required>
                                            <option value="">Pilih Anggota</option>
                                            <?php while($a = $anggotas->fetchArray(SQLITE3_ASSOC)): ?>
                                            <option value="<?= $a['id'] ?>"><?= $a['no_anggota'] ?> - <?= htmlspecialchars($a['nama_lengkap']) ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Jumlah Pinjaman (Rp)</label>
                                        <input type="number" name="jumlah_pinjaman" class="form-control" min="50000" required>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Bunga (%)</label>
                                            <input type="number" name="bunga" class="form-control" value="1.5" step="0.1" min="0" max="10" required>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Tenor (Bulan)</label>
                                            <input type="number" name="tenor" class="form-control" min="1" max="36" required>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Tanggal Pinjaman</label>
                                        <input type="date" name="tanggal_pinjaman" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Keterangan</label>
                                        <textarea name="keterangan" class="form-control" rows="2"></textarea>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                    <button type="submit" class="btn btn-primary">Simpan</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// Tutup koneksi database
$db->close();
?>