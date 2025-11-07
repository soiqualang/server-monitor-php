<?php
require_once 'config.php';

// Xử lý logout
if (isset($_GET['logout'])) {
    processLogout();
    header('Location: index.php');
    exit;
}

// Xử lý login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    if (processLogin($_POST['pin'])) {
        header('Location: index.php');
        exit;
    } else {
        $login_error = true;
    }
}

// Kiểm tra authentication cho AJAX requests
if (isset($_GET['action'])) {
    if (!checkAuth()) {
        header('HTTP/1.1 401 Unauthorized');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'cpu':
            echo json_encode(getCPUInfo());
            break;
        case 'memory':
            echo json_encode(getMemoryInfo());
            break;
        case 'disk':
            echo json_encode(getDiskInfo());
            break;
        case 'network':
            echo json_encode(getNetworkInfo());
            break;
        case 'processes':
            $sort = $_GET['sort'] ?? 'cpu';
            $order = $_GET['order'] ?? 'desc';
            echo json_encode(getProcesses($sort, $order));
            break;
        case 'system':
            echo json_encode(getSystemInfo());
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
    exit;
}

function getCPUInfo() {
    $load = sys_getloadavg();
    $cpu_count = (int)trim(shell_exec("nproc"));
    
    // Lấy thông tin CPU usage
    $prev = shell_exec("cat /proc/stat | grep '^cpu '");
    usleep(100000); // 100ms
    $current = shell_exec("cat /proc/stat | grep '^cpu '");
    
    preg_match_all('/\d+/', $prev, $prev_matches);
    preg_match_all('/\d+/', $current, $current_matches);
    
    $prev_idle = $prev_matches[0][3] + $prev_matches[0][4];
    $current_idle = $current_matches[0][3] + $current_matches[0][4];
    
    $prev_total = array_sum($prev_matches[0]);
    $current_total = array_sum($current_matches[0]);
    
    $diff_idle = $current_idle - $prev_idle;
    $diff_total = $current_total - $prev_total;
    
    $cpu_usage = (1000 * ($diff_total - $diff_idle) / $diff_total + 5) / 10;
    
    // Lấy thông tin từng core
    $cores = [];
    $cpu_stats = shell_exec("cat /proc/stat | grep '^cpu[0-9]'");
    $lines = explode("\n", trim($cpu_stats));
    
    foreach ($lines as $i => $line) {
        if (empty($line)) continue;
        preg_match_all('/\d+/', $line, $matches);
        if (!empty($matches[0])) {
            $total = array_sum($matches[0]);
            $idle = $matches[0][3] + $matches[0][4];
            $core_usage = $total > 0 ? round((($total - $idle) / $total) * 100, 1) : 0;
            
            $cores[] = [
                'core' => $i,
                'usage' => $core_usage
            ];
        }
    }
    
    // Lấy temperature
    $temp = @file_get_contents('/sys/class/thermal/thermal_zone0/temp');
    $temperature = $temp ? round($temp / 1000, 1) : null;
    
    return [
        'usage' => round($cpu_usage, 1),
        'load' => $load,
        'cores' => $cores,
        'count' => $cpu_count,
        'temperature' => $temperature,
        'model' => trim(shell_exec("cat /proc/cpuinfo | grep 'model name' | head -1 | cut -d':' -f2"))
    ];
}

function getMemoryInfo() {
    $meminfo = shell_exec("cat /proc/meminfo");
    preg_match_all('/(\w+):\s+(\d+)/', $meminfo, $matches);
    $info = array_combine($matches[1], $matches[2]);
    
    $total = $info['MemTotal'];
    $free = $info['MemFree'];
    $available = $info['MemAvailable'];
    $buffers = $info['Buffers'];
    $cached = $info['Cached'];
    $slab = isset($info['SReclaimable']) ? $info['SReclaimable'] : 0;
    
    // Tính Used chính xác như lệnh 'free'
    $used = $total - $free - $buffers - $cached - $slab;
    
    // Free hiển thị = Total - Used
    $free_display = $total - $used;
    
    $swap_total = $info['SwapTotal'];
    $swap_free = $info['SwapFree'];
    $swap_used = $swap_total - $swap_free;
    
    return [
        'total' => $total,
        'used' => $used,
        'free' => $free_display,
        'available' => $available,
        'buffers' => $buffers,
        'cached' => $cached,
        'usage_percent' => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        'swap' => [
            'total' => $swap_total,
            'used' => $swap_used,
            'free' => $swap_free,
            'usage_percent' => $swap_total > 0 ? round(($swap_used / $swap_total) * 100, 1) : 0
        ]
    ];
}

function getDiskInfo() {
    $disks = [];
    $df = shell_exec("df -h | grep '^/dev/'");
    $lines = explode("\n", trim($df));
    
    foreach ($lines as $line) {
        if (empty($line)) continue;
        $parts = preg_split('/\s+/', $line);
        
        $disks[] = [
            'device' => $parts[0],
            'size' => $parts[1],
            'used' => $parts[2],
            'available' => $parts[3],
            'usage_percent' => (int)str_replace('%', '', $parts[4]),
            'mount' => $parts[5]
        ];
    }
    
    return ['disks' => $disks];
}

function getNetworkInfo() {
    $cache_file = sys_get_temp_dir() . '/network_cache.json';
    $current_time = microtime(true);
    
    $interfaces = [];
    $net = shell_exec("cat /proc/net/dev");
    $lines = explode("\n", $net);
    
    $current_data = [];
    
    foreach ($lines as $line) {
        if (strpos($line, ':') === false) continue;
        
        list($interface, $data) = explode(':', $line);
        $interface = trim($interface);
        
        if ($interface == 'lo') continue;
        
        $values = preg_split('/\s+/', trim($data));
        if (count($values) < 9) continue;
        
        $rx_bytes = (float)$values[0];
        $tx_bytes = (float)$values[8];
        
        $current_data[$interface] = [
            'rx_bytes' => $rx_bytes,
            'tx_bytes' => $tx_bytes,
            'time' => $current_time
        ];
    }
    
    // Đọc dữ liệu cũ
    $prev_data = [];
    if (file_exists($cache_file)) {
        $cache_content = file_get_contents($cache_file);
        $prev_data = json_decode($cache_content, true);
    }
    
    // Tính speed
    foreach ($current_data as $interface => $current) {
        $rx_speed = 0;
        $tx_speed = 0;
        
        if (isset($prev_data[$interface])) {
            $time_diff = $current['time'] - $prev_data[$interface]['time'];
            
            if ($time_diff > 0) {
                $rx_diff = $current['rx_bytes'] - $prev_data[$interface]['rx_bytes'];
                $tx_diff = $current['tx_bytes'] - $prev_data[$interface]['tx_bytes'];
                
                $rx_speed = max(0, $rx_diff / $time_diff);
                $tx_speed = max(0, $tx_diff / $time_diff);
            }
        }
        
        $interfaces[] = [
            'name' => $interface,
            'rx_bytes' => $current['rx_bytes'],
            'tx_bytes' => $current['tx_bytes'],
            'rx_speed' => $rx_speed,
            'tx_speed' => $tx_speed
        ];
    }
    
    file_put_contents($cache_file, json_encode($current_data));
    
    return $interfaces;
}

function getProcesses($sort_by = 'cpu', $order = 'desc') {
    $sort_column = '%cpu';
    switch ($sort_by) {
        case 'mem':
            $sort_column = '%mem';
            break;
        case 'pid':
            $sort_column = 'pid';
            break;
        case 'user':
            $sort_column = 'user';
            break;
        case 'command':
            $sort_column = 'comm';
            break;
    }
    
    $sort_prefix = ($order === 'desc') ? '-' : '+';
    
    $ps = shell_exec("ps aux --sort={$sort_prefix}{$sort_column} | head -51");
    $lines = explode("\n", trim($ps));
    array_shift($lines);
    
    $processes = [];
    foreach ($lines as $line) {
        if (empty($line)) continue;
        $parts = preg_split('/\s+/', $line, 11);
        
        if (count($parts) < 11) continue;
        
        $processes[] = [
            'user' => $parts[0],
            'pid' => $parts[1],
            'cpu' => $parts[2],
            'mem' => $parts[3],
            'vsz' => $parts[4],
            'rss' => $parts[5],
            'tty' => $parts[6],
            'stat' => $parts[7],
            'start' => $parts[8],
            'time' => $parts[9],
            'command' => $parts[10]
        ];
    }
    
    return $processes;
}

function getSystemInfo() {
    return [
        'hostname' => trim(shell_exec("hostname")),
        'kernel' => trim(shell_exec("uname -r")),
        'os' => trim(shell_exec("cat /etc/os-release | grep PRETTY_NAME | cut -d'\"' -f2")),
        'uptime' => trim(shell_exec("uptime -p")),
        'users' => trim(shell_exec("who | wc -l")),
        'session_time' => isset($_SESSION['login_time']) ? (time() - $_SESSION['login_time']) : 0
    ];
}

// Login page
if (!checkAuth()) {
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Monitor - Login</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-box {
            background: #313244;
            padding: 40px;
            border-radius: 12px;
            border: 2px solid #45475a;
            max-width: 400px;
            width: 100%;
            text-align: center;
        }
        .login-box h1 {
            color: #89b4fa;
            margin-bottom: 30px;
        }
        .login-box input {
            width: 100%;
            padding: 15px;
            font-size: 18px;
            border: 2px solid #45475a;
            background: #1e1e2e;
            color: #cdd6f4;
            border-radius: 8px;
            margin-bottom: 20px;
            font-family: 'Courier New', monospace;
            letter-spacing: 8px;
            text-align: center;
        }
        .login-box input:focus {
            outline: none;
            border-color: #89b4fa;
        }
        .login-box button {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            font-weight: bold;
            background: linear-gradient(90deg, #89b4fa, #74c7ec);
            color: #1e1e2e;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .login-box button:hover {
            transform: translateY(-2px);
        }
        .error-message {
            color: #f38ba8;
            margin-bottom: 20px;
            padding: 10px;
            background: #1e1e2e;
            border-radius: 6px;
            border: 1px solid #f38ba8;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-box">
            <h1>🔒 Server Monitor</h1>
            <?php if (isset($login_error)): ?>
                <div class="error-message">❌ Mã PIN không chính xác!</div>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="pin" placeholder="Nhập mã PIN" maxlength="10" autofocus required>
                <button type="submit">🚀 Đăng nhập</button>
            </form>
        </div>
    </div>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Server Monitor - btop style</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h1>🖥️ SERVER MONITOR</h1>
                <button onclick="logout()" class="logout-btn">🚪 Logout</button>
            </div>
            <div id="system-info"></div>
        </div>
        
        <div class="grid">
            <!-- CPU Section -->
            <div class="panel cpu-panel">
                <div class="panel-header">
                    <span>CPU</span>
                    <span id="cpu-usage">0%</span>
                </div>
                <div class="panel-content">
                    <div id="cpu-model"></div>
                    <div class="progress-bar">
                        <div class="progress-fill cpu" id="cpu-progress"></div>
                    </div>
                    <div id="cpu-cores"></div>
                    <div class="stats">
                        <div>Load Avg: <span id="cpu-load"></span></div>
                        <div>Temp: <span id="cpu-temp"></span></div>
                    </div>
                </div>
            </div>
            
            <!-- Memory Section -->
            <div class="panel memory-panel">
                <div class="panel-header">
                    <span>MEMORY</span>
                    <span id="mem-usage">0%</span>
                </div>
                <div class="panel-content">
                    <div class="progress-bar">
                        <div class="progress-fill memory" id="mem-progress"></div>
                    </div>
                    <div class="stats">
                        <div>Used: <span id="mem-used">0 B</span></div>
                        <div>Free: <span id="mem-free">0 B</span></div>
                        <div>Total: <span id="mem-total">0 B</span></div>
                    </div>
                    <div class="panel-subheader">SWAP</div>
                    <div class="progress-bar">
                        <div class="progress-fill swap" id="swap-progress"></div>
                    </div>
                    <div class="stats">
                        <div>Used: <span id="swap-used">0 B</span></div>
                    </div>
                </div>
            </div>
            
            <!-- Disk Section -->
            <div class="panel disk-panel">
                <div class="panel-header">
                    <span>DISK</span>
                </div>
                <div class="panel-content" id="disk-content">
                </div>
            </div>
            
            <!-- Network Section -->
            <div class="panel network-panel">
                <div class="panel-header">
                    <span>NETWORK</span>
                </div>
                <div class="panel-content" id="network-content">
                </div>
            </div>
            
            <!-- Processes Section -->
            <div class="panel processes-panel">
                <div class="panel-header">
                    <span>TOP PROCESSES</span>
                    <span style="font-size: 12px; color: #a6adc8;">Click vào cột để sắp xếp</span>
                </div>
                <div class="panel-content">
                    <table id="processes-table">
                        <thead>
                            <tr>
                                <th data-sort="pid">PID <span class="sort-icon"></span></th>
                                <th data-sort="user">USER <span class="sort-icon"></span></th>
                                <th data-sort="cpu" class="active desc">CPU% <span class="sort-icon">▼</span></th>
                                <th data-sort="mem">MEM% <span class="sort-icon"></span></th>
                                <th data-sort="command">COMMAND <span class="sort-icon"></span></th>
                            </tr>
                        </thead>
                        <tbody id="processes-body">
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="script.js"></script>
</body>
</html>