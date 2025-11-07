// State management
let currentSort = 'cpu';
let currentOrder = 'desc';

// Utility functions
function formatBytes(bytes, decimals = 2) {
    if (bytes === 0 || isNaN(bytes)) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

function formatBytesSpeed(bytesPerSec) {
    if (bytesPerSec === 0) return '0 B/s';
    return formatBytes(bytesPerSec, 1) + '/s';
}

function getSpeedClass(speed) {
    if (speed > 1024 * 1024) return 'speed-high';
    if (speed > 1024 * 100) return 'speed-medium';
    return 'speed-low';
}

function formatTime(seconds) {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;
    return `${hours}h ${minutes}m ${secs}s`;
}

// Fetch data functions
async function fetchData(action, params = {}) {
    try {
        const queryString = new URLSearchParams(params).toString();
        const url = `?action=${action}${queryString ? '&' + queryString : ''}`;
        const response = await fetch(url);
        
        if (response.status === 401) {
            window.location.reload();
            return null;
        }
        
        return await response.json();
    } catch (error) {
        console.error(`Error fetching ${action}:`, error);
        return null;
    }
}

// Logout function
function logout() {
    if (confirm('Bạn có chắc muốn đăng xuất?')) {
        window.location.href = '?logout=1';
    }
}

// Update CPU info
async function updateCPU() {
    const data = await fetchData('cpu');
    if (!data) return;
    
    document.getElementById('cpu-usage').textContent = data.usage + '%';
    document.getElementById('cpu-progress').style.width = data.usage + '%';
    document.getElementById('cpu-model').textContent = data.model;
    document.getElementById('cpu-load').textContent = data.load.map(l => l.toFixed(2)).join(', ');
    document.getElementById('cpu-temp').textContent = data.temperature ? data.temperature + '°C' : 'N/A';
    
    // Update cores
    const coresDiv = document.getElementById('cpu-cores');
    coresDiv.innerHTML = '';
    
    data.cores.forEach(core => {
        const coreDiv = document.createElement('div');
        coreDiv.className = 'core-bar';
        coreDiv.innerHTML = `
            <div>C${core.core}</div>
            <div class="mini-progress">
                <div class="mini-progress-fill" style="width: ${core.usage}%"></div>
            </div>
            <div>${core.usage}%</div>
        `;
        coresDiv.appendChild(coreDiv);
    });
}

// Update Memory info - ĐÃ SỬA
async function updateMemory() {
    const data = await fetchData('memory');
    if (!data) return;
    
    document.getElementById('mem-usage').textContent = data.usage_percent + '%';
    document.getElementById('mem-progress').style.width = data.usage_percent + '%';
    document.getElementById('mem-used').textContent = formatBytes(data.used * 1024);
    document.getElementById('mem-free').textContent = formatBytes(data.free * 1024);
    document.getElementById('mem-total').textContent = formatBytes(data.total * 1024);
    
    document.getElementById('swap-progress').style.width = data.swap.usage_percent + '%';
    document.getElementById('swap-used').textContent = 
        `${formatBytes(data.swap.used * 1024)} / ${formatBytes(data.swap.total * 1024)} (${data.swap.usage_percent}%)`;
}

// Update Disk info
async function updateDisk() {
    const data = await fetchData('disk');
    if (!data) return;
    
    const diskDiv = document.getElementById('disk-content');
    diskDiv.innerHTML = '';
    
    data.disks.forEach(disk => {
        const diskItem = document.createElement('div');
        diskItem.className = 'disk-item';
        diskItem.innerHTML = `
            <div class="disk-name">💾 ${disk.device} → ${disk.mount}</div>
            <div class="progress-bar">
                <div class="progress-fill disk" style="width: ${disk.usage_percent}%"></div>
            </div>
            <div class="stats">
                <div>Used: <span>${disk.used}</span></div>
                <div>Free: <span>${disk.available}</span></div>
                <div>Total: <span>${disk.size}</span></div>
                <div>Usage: <span>${disk.usage_percent}%</span></div>
            </div>
        `;
        diskDiv.appendChild(diskItem);
    });
}

// Update Network info
async function updateNetwork() {
    const data = await fetchData('network');
    if (!data) return;
    
    const netDiv = document.getElementById('network-content');
    netDiv.innerHTML = '';
    
    if (data.length === 0) {
        netDiv.innerHTML = '<div style="text-align: center; color: #a6adc8;">No network interfaces found</div>';
        return;
    }
    
    data.forEach(iface => {
        const netItem = document.createElement('div');
        netItem.className = 'network-item';
        
        const rxSpeedClass = getSpeedClass(iface.rx_speed);
        const txSpeedClass = getSpeedClass(iface.tx_speed);
        
        netItem.innerHTML = `
            <div class="network-name">📡 ${iface.name.toUpperCase()}</div>
            <div class="stats">
                <div>
                    ↓ Download: 
                    <span class="speed-indicator ${rxSpeedClass}">
                        ${formatBytesSpeed(iface.rx_speed)}
                    </span>
                </div>
                <div>
                    ↑ Upload: 
                    <span class="speed-indicator ${txSpeedClass}">
                        ${formatBytesSpeed(iface.tx_speed)}
                    </span>
                </div>
                <div>Total RX: <span>${formatBytes(iface.rx_bytes)}</span></div>
                <div>Total TX: <span>${formatBytes(iface.tx_bytes)}</span></div>
            </div>
        `;
        netDiv.appendChild(netItem);
    });
}

// Update Processes with sorting
async function updateProcesses() {
    const data = await fetchData('processes', { 
        sort: currentSort, 
        order: currentOrder 
    });
    if (!data) return;
    
    const tbody = document.getElementById('processes-body');
    tbody.innerHTML = '';
    
    data.forEach(proc => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${proc.pid}</td>
            <td>${proc.user}</td>
            <td>${proc.cpu}%</td>
            <td>${proc.mem}%</td>
            <td class="command" title="${proc.command}">${proc.command}</td>
        `;
        tbody.appendChild(row);
    });
}

// Setup sorting
function setupSorting() {
    const headers = document.querySelectorAll('#processes-table th[data-sort]');
    
    headers.forEach(header => {
        header.addEventListener('click', () => {
            const sortBy = header.getAttribute('data-sort');
            
            if (currentSort === sortBy) {
                currentOrder = currentOrder === 'desc' ? 'asc' : 'desc';
            } else {
                currentSort = sortBy;
                currentOrder = 'desc';
            }
            
            headers.forEach(h => {
                h.classList.remove('active', 'asc', 'desc');
                h.querySelector('.sort-icon').textContent = '';
            });
            
            header.classList.add('active', currentOrder);
            header.querySelector('.sort-icon').textContent = currentOrder === 'desc' ? '▼' : '▲';
            
            updateProcesses();
        });
    });
}

// Update System info
async function updateSystem() {
    const data = await fetchData('system');
    if (!data) return;
    
    document.getElementById('system-info').innerHTML = `
        <div>🖥️ Host: <strong>${data.hostname}</strong></div>
        <div>🐧 OS: <strong>${data.os}</strong></div>
        <div>🔧 Kernel: <strong>${data.kernel}</strong></div>
        <div>⏱️ Uptime: <strong>${data.uptime}</strong></div>
        <div>👥 Users: <strong>${data.users}</strong></div>
        <div>🔐 Session: <strong>${formatTime(data.session_time)}</strong></div>
    `;
}

// Update all data
async function updateAll() {
    await Promise.all([
        updateSystem(),
        updateCPU(),
        updateMemory(),
        updateDisk(),
        updateNetwork(),
        updateProcesses()
    ]);
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    setupSorting();
    updateAll();
    
    // Update every 2 seconds
    setInterval(updateAll, 2000);
});