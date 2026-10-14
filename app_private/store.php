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
            if ($write) self::ensureGuids($state);
            $result = $callback($state);
            if ($write) self::ensureGuids($state);
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
    /** HMAC signing key for short-lived public form challenges; not a browser credential. */
    public function publicFormKey(): string {
        $file=$this->dir.'/public-form.key';
        if(!is_file($file)) {
            $handle=@fopen($file,'x');
            if($handle!==false) {
                try { @chmod($file,0600);
                    if(fwrite($handle,random_bytes(32))!==32)throw new RuntimeException('Cannot save public form signing key');
                } finally { fclose($handle); }
            }
        }
        $key=@file_get_contents($file);
        if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('Public form key missing or invalid');
        return $key;
    }
    /** Encrypt API credentials at rest with an application-local AES-256-GCM key. */
    private function secretKey(): string {
        $file=$this->dir.'/messaging.key';
        if (!is_file($file)) {
            $handle=@fopen($file,'x');
            if ($handle!==false) {
                try { @chmod($file,0600); $key=random_bytes(32);
                    if (fwrite($handle,$key)!==32) throw new RuntimeException('Cannot create messaging encryption key');
                } finally { fclose($handle); }
            }
        }
        $key=@file_get_contents($file);
        if(!is_string($key)||strlen($key)!==32)throw new RuntimeException('Messaging key missing or corrupted; restore messaging.key from backup');
        return $key;
    }
    public function seal(string $value): string {
        if(!function_exists('openssl_encrypt'))throw new RuntimeException('OpenSSL extension required');
        $iv=random_bytes(12);$tag='';
        $cipher=openssl_encrypt($value,'aes-256-gcm',$this->secretKey(),OPENSSL_RAW_DATA,$iv,$tag);
        if($cipher===false)throw new RuntimeException('Cannot encrypt provider credentials');
        return base64_encode($iv.$tag.$cipher);
    }
    public function unseal(string $value): string {
        $raw=base64_decode($value,true);
        if($raw===false||strlen($raw)<29)throw new RuntimeException('Provider credentials are unavailable');
        $v=openssl_decrypt(substr($raw,28),'aes-256-gcm',$this->secretKey(),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
        if($v===false)throw new RuntimeException('Provider credentials cannot be decrypted; restore messaging.key');
        return $v;
    }
    /** Assign permanent GUIDs to records during locked writes; keep numeric ID FKs intact. */
    private static function ensureGuids(array &$s): void {
        foreach(['users','schools','memberships','sites','group_sites','leads','calls','visits','email_templates','activities','audit_logs','receipts','sessions','message_attempts'] as $table){
            if(!isset($s[$table])||!is_array($s[$table]))continue;
            foreach($s[$table] as &$record)if(is_array($record)&&empty($record['guid']))$record['guid']=self::guid();
            unset($record);
        }
    }
    public static function guid(): string {
        $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);
        $h=bin2hex($b);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
    public static function blank(): array {
        return ['version'=>1, 'counters'=>[], 'users'=>[], 'schools'=>[], 'memberships'=>[], 'sites'=>[],
            'leads'=>[], 'group_sites'=>[], 'calls'=>[], 'visits'=>[], 'email_templates'=>[], 'assignment_modes'=>[], 'assignment_cursors'=>[], 'activities'=>[], 'audit_logs'=>[], 'sessions'=>[], 'receipts'=>[], 'rate_limits'=>[]];
    }
}
