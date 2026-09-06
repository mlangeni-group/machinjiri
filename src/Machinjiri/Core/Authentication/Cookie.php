<?php
namespace Mlangeni\Machinjiri\Core\Authentication;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Authentication\Session;

class Cookie {

  private Session $session;

  public function __construct(private Container $app) {
      $this->session = new Session($app);
  }

  public function set(string $name, mixed $value, int $expire = 0, string $path = '', string $domain = '', bool $secure = false, bool $httponly = true): bool {
    return setcookie($name, (string) $value, [
      'expires' => $expire === 0 ? 0 : time() + $expire,
      'path' => $this->session->config['path'] ?? '/',
      'domain' => (!empty($domain)) ? $domain : $this->session->config['domain'] ?? $this->session->request->getServerParam()['HTTP_HOST'] ?? 'localhost',
      'secure' => $secure,
      'httponly' => $httponly,
      'samesite' => $this->session->config['same_site'] ?? 'Lax'
    ]);
  }

  public function get(string $name, mixed $default = null): mixed {
    return $_COOKIE[$name] ?? $default;
  }

  public function delete(string $name, string $path = '/', string $domain = ''): void {
    $this->set($name, '', time() - 3600, $path, $domain);
  }

  public function has(string $name): bool {
    return isset($_COOKIE[$name]);
  }
}