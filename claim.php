<?php
/*
 * BTC TON Revenue Auto Claimer - Multi Akun + Service Manager
 * Telegram: t.me/config_geratis
 */

define('DATA_FILE', __DIR__ . '/accounts.json');
define('PID_FILE', __DIR__ . '/service.pid');
define('LOG_FILE', __DIR__ . '/service.log');
define('INNER_WIDTH', 44);

// ─── UI Functions ───

function color($c, $t) {
    $m = ['green'=>"\e[1;32m",'red'=>"\e[1;31m",'yellow'=>"\e[1;33m",'blue'=>"\e[1;34m",'cyan'=>"\e[1;36m",'white'=>"\e[1;37m",'reset'=>"\e[0m"];
    return ($m[$c]??$m['reset']).$t.$m['reset'];
}

function vislen($t) {
    return mb_strwidth(preg_replace('/\x1b\[[0-9;]*m/','',$t),'UTF-8');
}

function ctext($t) {
    $p = INNER_WIDTH - vislen($t);
    return $p <= 0 ? $t : str_repeat(' ',floor($p/2)).$t;
}

function box($lines, $bc='white') {
    echo color($bc,"╔".str_repeat("═",INNER_WIDTH)."╗\n");
    foreach($lines as $l) {
        if($l==='---') { echo color($bc,"╠".str_repeat("═",INNER_WIDTH)."╣\n"); continue; }
        $r = max(0, INNER_WIDTH - vislen($l));
        echo color($bc,"║").$l.str_repeat(' ',$r).color($bc,"║\n");
    }
    echo color($bc,"╚".str_repeat("═",INNER_WIDTH)."╝\n");
}

// ─── Data Functions ───

function load_accounts() {
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?: [];
}

function save_accounts($accs) {
    file_put_contents(DATA_FILE, json_encode($accs, JSON_PRETTY_PRINT));
}

// ─── Claim Logic ───

function do_claim($initData, $log_fh = null) {
    $log = function($msg) use ($log_fh) {
        $t = '['.date('H:i:s').'] '.$msg;
        if ($log_fh) fwrite($log_fh, $t."\n");
        else echo " $t\n";
    };

    $urlC = "https://btc.tonrevenue.space/api/claim";
    $urlCf = "https://btc.tonrevenue.space/api/claim/confirm";
    $urlCap = "https://btc.tonrevenue.space/api/captcha/challenge";
    $urlVer = "https://btc.tonrevenue.space/api/captcha/verify";
    $ua = "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Mobile Safari/537.36";
    $hdr = ["Host: btc.tonrevenue.space", "Content-Type: application/json", "User-Agent: $ua"];

    $int = [
        "pointer_type"=>"touch","page_x_norm"=>0.45,"page_y_norm"=>0.60,
        "button_x_norm"=>0.42,"button_y_norm"=>0.58,"press_ms"=>rand(120,200),
        "move_count"=>5,"path_length"=>0.00001,"screen_orientation"=>"portrait-primary",
        "ua"=>$ua,"screen"=>"393x875","lang"=>"id-ID","tz"=>"Asia/Jakarta",
        "platform"=>"Linux armv81","tg_platform"=>"android","viewport_width"=>377,
        "viewport_height"=>640,"max_touch_points"=>5,"device_pixel_ratio"=>2.75
    ];

    $post = function($url, $data) use ($hdr) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>1, CURLOPT_POST=>1,
            CURLOPT_POSTFIELDS=>json_encode($data), CURLOPT_HTTPHEADER=>$hdr,
            CURLOPT_TIMEOUT=>30
        ]);
        $r = curl_exec($ch);
        return json_decode($r);
    };

    $log("Memulai klaim...");
    $json = $post($urlC, ["initData"=>$initData, "interaction"=>$int]);

    if (isset($json->detail) && $json->detail == "captcha_required") {
        $log("[!] Captcha terdeteksi, bypass...");
        $cap = $post($urlCap, ["initData"=>$initData, "interaction"=>$int]);
        if (isset($cap->challenge)) {
            $cid = $cap->challenge->challenge_id;
            $ans = strtolower(str_replace("Tap the ", "", $cap->challenge->prompt));
            $log(" Bypass target: $ans");
            $ver = $post($urlVer, ["initData"=>$initData,"challenge_id"=>$cid,"answer"=>$ans,"interaction"=>$int]);
            if (isset($ver->status) && $ver->status == "success") {
                $log("[✓] Bypass sukses!");
                $json = $post($urlC, ["initData"=>$initData, "interaction"=>$int]);
            }
        }
    }

    if (isset($json->session_uid)) {
        $cf = $post($urlCf, ["initData"=>$initData, "session_uid"=>$json->session_uid]);
        if (isset($cf->reward)) {
            $log("[💰] Sukses! Reward: {$cf->reward} | Saldo: {$cf->new_balance}");
            return ['status'=>'ok', 'reward'=>$cf->reward, 'balance'=>$cf->new_balance];
        }
        $log("[!] Konfirmasi gagal, lanjut...");
        return ['status'=>'confirm_fail'];
    }

    $det = $json->detail ?? 'Gagal';
    $log("[✗] $det");

    $wait = 20;
    if (preg_match('/\d+/', $det, $m)) $wait = (int)$m[0];
    return ['status'=>'fail', 'detail'=>$det, 'wait'=>$wait];
}

// ─── Service Daemon ───

function run_service() {
    file_put_contents(PID_FILE, getmypid());
    $log_fh = fopen(LOG_FILE, 'a');

    fwrite($log_fh, "\n=== SERVICE STARTED [".date('Y-m-d H:i:s')."] PID: ".getmypid()." ===\n");

    while (true) {
        $accs = load_accounts();
        if (empty($accs)) {
            fwrite($log_fh, "[".date('H:i:s')."] Tidak ada akun, tunggu 30 detik...\n");
            sleep(30);
            continue;
        }

        foreach ($accs as $i => $acc) {
            if (!file_exists(PID_FILE)) { fwrite($log_fh, "PID file hilang, berhenti.\n"); break 2; }
            $label = $acc['label'] ?? "Akun #".($i+1);
            fwrite($log_fh, "\n--- $label ---\n");
            $result = do_claim($acc['initData'], $log_fh);

            if (isset($result['wait'])) {
                fwrite($log_fh, "Tunggu {$result['wait']} detik sebelum akun berikutnya...\n");
                sleep($result['wait']);
            } else {
                sleep(5);
            }
        }

        fwrite($log_fh, "\n=== Siklus selesai, tunggu 15 detik... ===\n");
        sleep(15);
    }

    fclose($log_fh);
    unlink(PID_FILE);
}

// ─── Service Management ───

function service_running() {
    if (!file_exists(PID_FILE)) return false;
    $pid = trim(file_get_contents(PID_FILE));
    return (bool)@posix_kill((int)$pid, 0);
}

function start_service() {
    if (service_running()) { box([ctext(color('yellow', "Service sudah berjalan"))], 'yellow'); return; }
    $cmd = sprintf("nohup php %s --daemon > /dev/null 2>&1 &", escapeshellarg(__FILE__));
    exec($cmd);
    sleep(1);
    if (service_running()) {
        box([ctext(color('green', "[✓] Service started (PID: ".trim(file_get_contents(PID_FILE)).")"))], 'green');
    } else {
        box([ctext(color('red', "[✗] Gagal start service"))], 'red');
    }
}

function stop_service() {
    if (!service_running()) { box([ctext(color('yellow', "Service tidak berjalan"))], 'yellow'); return; }
    $pid = trim(file_get_contents(PID_FILE));
    exec("kill $pid 2>/dev/null");
    @unlink(PID_FILE);
    sleep(1);
    box([ctext(color('green', "[✓] Service stopped"))], 'green');
}

function restart_service() {
    stop_service();
    sleep(1);
    start_service();
}

// ─── Account Management ───

function add_account() {
    system("clear");
    box([ctext(color('cyan', "TAMBAH AKUN BARU"))], 'cyan');
    echo "\n ".color('cyan', "➔ ").color('white', "Label (misal: Akun1): ");
    $label = trim(readline());
    echo " ".color('cyan', "➔ ").color('white', "Payload (initData): ");
    $data = trim(readline());
    if (empty($label) || empty($data)) {
        box([ctext(color('red', "Data tidak lengkap"))], 'red');
        return;
    }
    $accs = load_accounts();
    $accs[] = ['label'=>$label, 'initData'=>$data];
    save_accounts($accs);
    box([ctext(color('green', "[✓] Akun '$label' berhasil ditambahkan"))], 'green');
}

function list_accounts() {
    system("clear");
    $accs = load_accounts();
    if (empty($accs)) {
        box([ctext(color('yellow', "Belum ada akun"))], 'yellow');
        return;
    }
    $lines = [ctext(color('cyan', "DAFTAR AKUN (".count($accs).")"))];
    foreach ($accs as $i => $a) {
        $lines[] = '---';
        $lines[] = " ".color('white', ($i+1).". ").color('green', $a['label']);
        $lines[] = "   ".color('yellow', substr($a['initData'], 0, 50)."...");
    }
    box($lines, 'cyan');
}

function delete_account() {
    $accs = load_accounts();
    if (empty($accs)) { box([ctext(color('yellow', "Belum ada akun"))], 'yellow'); return; }
    list_accounts();
    echo "\n ".color('red', "➔ ").color('white', "Nomor akun yang dihapus: ");
    $n = (int)trim(readline());
    if ($n < 1 || $n > count($accs)) { box([ctext(color('red', "Nomor tidak valid"))], 'red'); return; }
    $label = $accs[$n-1]['label'];
    array_splice($accs, $n-1, 1);
    save_accounts($accs);
    box([ctext(color('green', "[✓] Akun '$label' dihapus"))], 'green');
}

function view_service_log() {
    if (!file_exists(LOG_FILE)) { box([ctext(color('yellow', "Belum ada log"))], 'yellow'); return; }
    $log = file_get_contents(LOG_FILE);
    $lines = explode("\n", trim($log));
    $last = array_slice($lines, -20);
    box(array_merge(
        [ctext(color('cyan', "LOG TERBARU (20 baris terakhir)")), '---'],
        array_map(fn($l) => " ".color('white', $l), $last)
    ), 'cyan');
}

// ─── Auto-Start ───

function install_autostart() {
    $script = __FILE__;

    // Termux boot
    $termux_boot_dir = getenv('HOME') . '/.termux/boot';
    if (is_dir($termux_boot_dir) || @mkdir($termux_boot_dir, 0755, true)) {
        $boot_script = "$termux_boot_dir/btc-claimer.sh";
        file_put_contents($boot_script, "#!/data/data/com.termux/files/usr/bin/bash\ncd " . __DIR__ . "\nexec php " . escapeshellarg($script) . " --daemon\n");
        chmod($boot_script, 0755);
    }

    // systemd user
    $systemd_dir = getenv('HOME') . '/.config/systemd/user';
    if (is_dir($systemd_dir) || @mkdir($systemd_dir, 0755, true)) {
        $service_content = "[Unit]\nDescription=BTC TON Revenue Auto Claimer\nAfter=network.target\n\n[Service]\nType=simple\nExecStart=" . PHP_BINARY . " " . escapeshellarg($script) . " --daemon\nWorkingDirectory=" . __DIR__ . "\nRestart=on-failure\nRestartSec=10\n\n[Install]\nWantedBy=default.target\n";
        file_put_contents("$systemd_dir/btc-claimer.service", $service_content);
        exec("systemctl --user daemon-reload 2>/dev/null");
        exec("systemctl --user enable btc-claimer.service 2>/dev/null");
        exec("systemctl --user start btc-claimer.service 2>/dev/null");
    }

    // crontab fallback (@reboot)
    $cron_line = "@reboot cd " . __DIR__ . " && " . PHP_BINARY . " " . escapeshellarg($script) . " --daemon > " . LOG_FILE . " 2>&1\n";
    $existing_cron = @shell_exec("crontab -l 2>/dev/null") ?: '';
    if (strpos($existing_cron, $cron_line) === false) {
        file_put_contents('/tmp/btc-cron', $existing_cron . $cron_line);
        exec("crontab /tmp/btc-cron 2>/dev/null");
        unlink('/tmp/btc-cron');
    }

    box([
        ctext(color('green', "[✓] Auto-start terpasang!")),
        '---',
        " ".color('white', "Termux : $termux_boot_dir/btc-claimer.sh"),
        " ".color('white', "systemd: btc-claimer.service"),
        " ".color('white', "cron   : @reboot (fallback)")
    ], 'green');
}

function remove_autostart() {
    // systemd
    exec("systemctl --user stop btc-claimer.service 2>/dev/null");
    exec("systemctl --user disable btc-claimer.service 2>/dev/null");
    @unlink(getenv('HOME') . '/.config/systemd/user/btc-claimer.service');
    exec("systemctl --user daemon-reload 2>/dev/null");

    // Termux boot
    @unlink(getenv('HOME') . '/.termux/boot/btc-claimer.sh');

    // crontab
    $cron = @shell_exec("crontab -l 2>/dev/null") ?: '';
    $cron = preg_replace('/@reboot.*btc-claimer.*\n?/', '', $cron);
    file_put_contents('/tmp/btc-cron', $cron);
    exec("crontab /tmp/btc-cron 2>/dev/null");
    unlink('/tmp/btc-cron');

    box([ctext(color('green', "[✓] Auto-start dihapus"))], 'green');
}

// ─── Manual Claim ───

function manual_claim() {
    $accs = load_accounts();
    if (empty($accs)) {
        box([ctext(color('yellow', "Belum ada akun. Tambah dulu."))], 'yellow');
        return;
    }

    system("clear");
    box([
        ctext(color('cyan', "MANUAL CLAIM — Tekan 'q' + ENTER untuk berhenti")),
        '---',
        " ".color('white', "Akun: ".count($accs)." terdaftar")
    ], 'cyan');

    // Non-blocking input setup
    system("stty -icanon min 0 time 1 2>/dev/null");

    $running = true;
    while ($running) {
        foreach ($accs as $i => $acc) {
            // Cek input tanpa blokir
            $ch = fread(STDIN, 1);
            if ($ch === 'q' || $ch === 'Q') {
                $running = false;
                break;
            }

            $label = $acc['label'] ?? "Akun #".($i+1);
            echo "\n";
            box([" ".color('blue', "[$label] Memulai klaim...")], 'blue');
            $result = do_claim($acc['initData']);

            if (isset($result['wait'])) {
                $w = $result['wait'];
                echo "\n";
                box([" ".color('yellow', "Tunggu {$w} detik... (tekan q + ENTER untuk skip)")], 'yellow');
                for ($s = $w; $s > 0; $s--) {
                    $ch = fread(STDIN, 1);
                    if ($ch === 'q' || $ch === 'Q') { break 2; }
                    sleep(1);
                }
            } else {
                sleep(3);
            }
        }

        if ($running) {
            echo "\n";
            box([" ".color('yellow', "Siklus selesai. Ulang dalam 10 detik... (tekan q + ENTER)")], 'yellow');
            for ($s = 10; $s > 0; $s--) {
                $ch = fread(STDIN, 1);
                if ($ch === 'q' || $ch === 'Q') { break; }
                sleep(1);
            }
        }
    }

    system("stty sane 2>/dev/null");
    echo "\n";
    box([ctext(color('green', "[✓] Manual claim dihentikan"))], 'green');
}

// ─── Main ───

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--daemon') {
    run_service();
    exit;
}

while (true) {
    system("clear");
    $status = service_running() ? color('green', "● RUNNING") : color('red', "○ STOPPED");
    $acc_count = count(load_accounts());

    box([
        ctext(color('yellow', "BTC TON REVENUE AUTO CLAIMER")),
        ctext(color('cyan', "Multi Akun + Service Manager")),
        '---',
        " ".color('white', "Service : $status"),
        " ".color('white', "Akun    : ").color('yellow', $acc_count),
        " ".color('white', "Log     : ".LOG_FILE),
        '---',
        " ".color('green', "1). ").color('white', "Tambah Akun"),
        " ".color('green', "2). ").color('white', "Lihat Akun"),
        " ".color('green', "3). ").color('white', "Hapus Akun"),
        " ".color('green', "4). ").color('white', "Start Service"),
        " ".color('green', "5). ").color('white', "Stop Service"),
        " ".color('green', "6). ").color('white', "Restart Service"),
        " ".color('green', "7). ").color('white', "Lihat Log"),
        " ".color('green', "8). ").color('white', "Manual Claim (loop terus)"),
        " ".color('green', "9). ").color('white', "Pasang Auto-Start (reboot)"),
        " ".color('green', "10). ").color('white', "Hapus Auto-Start"),
        " ".color('green', "0). ").color('white', "Keluar"),
    ], 'white');

    echo "\n ".color('yellow', "➔ ").color('white', "Pilih: ");
    $p = trim(readline());

    if ($p === '0') {
        system("clear");
        exit("Bye!\n");
    }

    switch ($p) {
        case '1': add_account(); break;
        case '2': list_accounts(); break;
        case '3': delete_account(); break;
        case '4': start_service(); break;
        case '5': stop_service(); break;
        case '6': restart_service(); break;
        case '7': view_service_log(); break;
        case '8': manual_claim(); break;
        case '9': install_autostart(); break;
        case '10': remove_autostart(); break;
    }

    echo "\n ".color('yellow', "Tekan ENTER untuk kembali...");
    readline();
}
