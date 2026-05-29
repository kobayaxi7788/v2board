<?php

namespace App\Utils;

use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\ClashNyanpasu;
use App\Protocols\ClashVerge;
use App\Protocols\Stash;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class FlClashCrypto
{
    private const CLIENT_MARK = '8A3F2C7E9D4B1A6C5E0D7B2F1C8A3D5E';
    private const KEY = 'ZpGhHIPrDuTbvHSuk+2wyU2AA16jsngz';
    private const CLASH_PROTOCOLS = [
        Clash::class,
        ClashMeta::class,
        ClashNyanpasu::class,
        ClashVerge::class,
        Stash::class,
    ];

    public static function shouldEncrypt(Request $request): bool
    {
        return (string) $request->header('X-Client', '') === self::CLIENT_MARK;
    }

    public static function shouldEncryptSubscription(Request $request, string $protocolClassName): bool
    {
        return self::shouldEncrypt($request) && self::shouldEncryptProtocolClassName($protocolClassName);
    }

    public static function shouldEncryptProtocolClassName(string $protocolClassName): bool
    {
        return in_array($protocolClassName, self::CLASH_PROTOCOLS, true);
    }

    public static function shouldEncryptProtocol($protocol): bool
    {
        return is_object($protocol) && self::shouldEncryptProtocolClassName(get_class($protocol));
    }

    public static function rewriteServerFields($payload)
    {
        $rewriteRules = self::getServerRewriteRules();
        if (empty($rewriteRules)) {
            return $payload;
        }

        $content = $payload instanceof Response ? $payload->getContent() : $payload;
        if (!is_string($content) || $content === '') {
            return $payload;
        }

        try {
            $config = Yaml::parse($content);
        } catch (Throwable $e) {
            return $payload;
        }

        if (!is_array($config) || !isset($config['proxies']) || !is_array($config['proxies'])) {
            return $payload;
        }

        $rewritten = false;
        $rewriteCount = 0;
        foreach ($config['proxies'] as &$proxy) {
            if (!is_array($proxy)) {
                continue;
            }

            $server = isset($proxy['server']) ? $proxy['server'] : null;
            if (!is_string($server) || $server === '') {
                continue;
            }

            $newServer = $server;
            foreach ($rewriteRules as $search => $replace) {
                if (strpos($newServer, $search) === false) {
                    continue;
                }
                $newServer = str_replace($search, $replace, $newServer);
            }

            if ($newServer === $server) {
                continue;
            }

            $proxy['server'] = $newServer;
            $rewritten = true;
            $rewriteCount++;
        }
        unset($proxy);

        if (!$rewritten) {
            return $payload;
        }

        $yaml = Yaml::dump($config, 2, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        if ($payload instanceof Response) {
            $payload->setContent($yaml);
            $payload->headers->remove('content-length');
            $payload->headers->set('x-flclash-server-rewritten', '1');
            $payload->headers->set('x-flclash-server-rewrite-count', (string) $rewriteCount);
            return $payload;
        }

        return $yaml;
    }

    public static function encryptPayload($payload)
    {
        if ($payload instanceof Response) {
            $content = $payload->getContent();
            if (!is_string($content) || $content === '') {
                return $payload;
            }

            $payload->setContent(self::encrypt($content));
            $payload->headers->set('content-type', 'text/plain; charset=UTF-8');
            $payload->headers->remove('content-length');
            $payload->headers->set('x-flclash-encrypted', '1');
            return $payload;
        }

        if (!is_string($payload) || $payload === '') {
            return $payload;
        }

        return response(self::encrypt($payload), 200, [
            'content-type' => 'text/plain; charset=UTF-8',
            'x-flclash-encrypted' => '1',
        ]);
    }

    public static function encrypt(string $plainText): string
    {
        $iv = random_bytes(16);
        $cipherText = openssl_encrypt(
            $plainText,
            'AES-256-CBC',
            self::KEY,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($cipherText === false) {
            throw new \RuntimeException('FlClash config encrypt failed');
        }

        return base64_encode($iv . $cipherText);
    }

    private static function getServerRewriteRules(): array
    {
        $config = self::loadRewriteConfig();
        $rules = isset($config['server_rewrite_rules']) ? $config['server_rewrite_rules'] : null;
        if (!is_array($rules)) {
            return [];
        }

        return self::normalizeRewriteRules($rules);
    }

    private static function loadRewriteConfig(): array
    {
        $configPath = base_path('config/flclash.php');
        if (!is_file($configPath)) {
            return [];
        }

        try {
            $config = require $configPath;
            return is_array($config) ? $config : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function normalizeRewriteRules(array $rules): array
    {
        $normalized = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $source = trim((string) (isset($rule['server']) ? $rule['server'] : ''));
            $target = trim((string) (isset($rule['replace']) ? $rule['replace'] : ''));
            if ($source === '' || $target === '') {
                continue;
            }

            $normalized[$source] = $target;
        }

        return $normalized;
    }
}
