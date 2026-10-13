<?php
declare(strict_types=1);

/** File-backed transactional storage. Can later be replaced with a MariaDB adapter. */
final class SchoolFlowStore {
    private string $dir;
    public function __construct(?string $directory = null) {
        $this->dir = $directory ?: (getenv('SCHOOLFLOW_DATA_DIR') ?: (defined('SCHOOLFLOW_DEFAULT_DATA_DIR') ? SCHOOLFLOW_DEFAULT_DATA_DIR : __DIR__ . '/data'));
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException('Cannot create private data directory');
        }
        if (!is_writable($this->dir)) throw new RuntimeException('Private data directory is not writable');
    }
    public function transaction(callable $callback, bool $write = false): mixed {
        $lock = fopen($this->dir . '/store.lock', 'c');
        if (!$lock) throw new RuntimeException('Cannot open store lock');
        try {
            if (!flock($lock, $write ? LOCK_EX : LOCK_SH)) throw new RuntimeException('Cannot lock store');
            $file = $this->dir . '/store.json';
            $json = is_file($file) ? file_get_contents($file) : false;
            if ($json === false || $json === '') {
                if (is_file($file)) throw new RuntimeException('Cannot read persisted data');
                $state = self::blank();
            } else {
                $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($state) || ($state['version'] ?? null) !== 1) throw new RuntimeException('Invalid data format');
            }
            $result = $callback($state);
            if ($write) {
                $bytes = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $tmp = tempnam($this->dir, '.save-');
                if ($tmp === false) throw new RuntimeException('Cannot create transaction file');
                try {
                    @chmod($tmp, 0600);
                    if (file_put_contents($tmp, $bytes) !== strlen($bytes)) throw new RuntimeException('Failed to save data');
                    if (!rename($tmp, $file)) throw new RuntimeException('Cannot commit data');
                } finally { if (is_file($tmp)) @unlink($tmp); }
            }
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function blank(): array {
        return ['version'=>1, 'counters'=>[], 'users'=>[], 'schools'=>[], 'memberships'=>[], 'sites'=>[],
            'leads'=>[], 'calls'=>[], 'visits'=>[], 'email_templates'=>[], 'assignment_modes'=>[], 'assignment_cursors'=>[], 'activities'=>[], 'audit_logs'=>[], 'sessions'=>[], 'receipts'=>[], 'rate_limits'=>[]];
    }
}