<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;

class Session {
    private int $timeout;
    private string $cookieName;
    private ?string $domain;
    private bool $secure;

    private Container $app;
    public HttpRequest $request;
    public array $config;

    public function __construct(Container $app) {
        $this->request = $app->resolve(HttpRequest::class);
        $this->config = array_merge(self::defaultConfig(), $this->getAppConfigurations());
        
        $this->loadSessionConfig();
        
        if (session_status() == PHP_SESSION_NONE) {
            $this->configureSession();
            session_start();
            $this->initializeSession();
        }
    }

    private function loadSessionConfig(): void {
        // Convert minutes to seconds for timeout
        $lifetimeMinutes = (int) $this->config['lifetime'];
        $this->timeout = $lifetimeMinutes * 60;
        
        $this->cookieName = $this->config['cookie'];
        $this->domain = $this->config['domain'];
        $this->secure = $this->config['secure'];
    }

    private static function defaultConfig(): array 
    {
        return [
            'driver' => 'file',
            'lifetime' => 120,
            'expire_on_close' => false,
            'encrypt' => false,
            'files' => storage_path('session'),
            'connection' => null,
            'table' => 'sessions',
            'store' => null,
            'lottery' => [2, 100],
            'cookie' => 'machinjiri_session',
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'http_only' => true,
            'same_site' => 'lax',
        ];
    }

    public function getAppConfigurations(): array
    {
        return $this->app->configurations['app'] ?? [];
    }

    private function configureSession(): void {
        // Determine session save path based on driver
        $sessionPath = ($this->config['driver'] === 'file') 
            ? $this->config['files']
            : '';

        if ($sessionPath && !is_dir($sessionPath)) {
            mkdir($sessionPath, 0755, true);
        }

        session_name($this->cookieName);
        
        session_set_cookie_params([
            'lifetime' => $this->timeout,
            'path' => '/',
            'domain' => $this->domain ?? ($this->request->getServerParam()['HTTP_HOST'] ?? 'locahost'),
            'secure' => $this->secure,
            'httponly' => $this->config['http_only'],
            'samesite' => $this->config['same_site'],
        ]);
        
        if ($sessionPath) {
            session_save_path($sessionPath);
        }
        
        ini_set('session.use_strict_mode', '1');
        ini_set('session.gc_maxlifetime', $this->timeout);
    }

    private function initializeSession(): void {
        if (!isset($_SESSION['_created'])) {
            $_SESSION['_created'] = time();
            $_SESSION['_fingerprint'] = $this->generateFingerprint();
        }
        $this->validateSession();
    }

    private function validateSession(): void {
        $this->checkTimeout();
        $this->validateFingerprint();
    }

    public function set(string $key, mixed $value): void {
      if (str_starts_with($key, '_')) {
          throw new MachinjiriException("Session key cannot start with underscore (reserved)");
      }
      $_SESSION[$key] = $value;
    }
    
    public function get(string $key, mixed $default = null): mixed {
        return isset($_SESSION[$key]) ? $this->sanitize($_SESSION[$key]) : $default;
    }

    private function sanitize(mixed $data): mixed {
        return is_array($data) 
            ? array_map([$this, 'sanitize'], $data) 
            : htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
    }

    private function generateFingerprint(): string {
        $ip = $this->request->getServerParam()['REMOTE_ADDR'] ?? '127.0.0.1';
        $agent = $this->request->getServerParam()['HTTP_USER_AGENT'] ?? 'artisan cli';
        return hash('sha256', $agent . $ip);
    }
    
    private function validateFingerprint(): void {
        if ($_SESSION['_fingerprint'] !== $this->generateFingerprint()) {
            $this->destroy();
            throw new MachinjiriException('Session validation failed');
        }
    }
    
    public function regenerateId(): void {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }
  
    private function checkTimeout(): void {
        if (isset($_SESSION['_created']) && time() - $_SESSION['_created'] > $this->timeout) {
            $this->destroy();
            throw new MachinjiriException('Session timeout');
        }
    }
    
    public function has(string $key): bool {
        return isset($_SESSION[$key]);
    }
    
    public function destroy(bool $restart = false): void {
      session_destroy();
      $_SESSION = [];
      if ($restart) {
          session_start();
          $this->initializeSession();
      }
    }
    
}