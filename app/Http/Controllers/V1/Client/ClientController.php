<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Services\ServerService;
use App\Services\SubscriptionControlService;
use App\Services\UserService;
use App\Utils\FlClashCrypto;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    private const VALID_SUBSCRIPTION_TYPES = [
        'shadowsocks',
        'vmess',
        'vless',
        'trojan',
        'tuic',
        'hysteria',
        'hysteria2',
        'anytls',
        'v2node',
    ];

    private const DEFAULT_NORMAL_SUBSCRIPTION_ALLOWED_TYPES = [
        'anytls',
    ];

    private const PROTOCOL_FLAG_ALIASES = [
        'App\\Protocols\\ClashMeta' => [
            'flclash',
            'nekobox',
            'clashmetaforandroid',
        ],
    ];

    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $clientInfo = $this->getClientInfo($request, $flag);
            $subscriptionControl = app(SubscriptionControlService::class);
            $requestIp = $subscriptionControl->getRequestIp($request);
            $skipSubscriptionControl = in_array($requestIp, [
                '8.134.191.84',
            ], true);
            $subscribeLog = null;
            if (!$skipSubscriptionControl) {
                $subscribeLog = $subscriptionControl->recordSubscribeRequest($user, $request, $clientInfo);
            }

            if($flag) {
                if (strpos($flag, 'sing') === false) {
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        $class = new $file($user, $servers);
                        if ($this->protocolMatchesFlag($class, $flag, $file)) {
                            $isEncryptedSubscription = FlClashCrypto::shouldEncryptSubscription($request, $file);
                            $protocolServers = $this->filterServersByRequest($request, $servers, $isEncryptedSubscription);
                            if (!$skipSubscriptionControl) {
                                $protocolServers = $subscriptionControl->applyToServers($user, $protocolServers, $request, $clientInfo, $subscribeLog);
                            }
                            $this->setSubscribeInfoToServers($protocolServers, $user);
                            $class = new $file($user, $protocolServers);
                            $payload = $class->handle();

                            if (!$isEncryptedSubscription) {
                                return $payload;
                            }

                            $payload = FlClashCrypto::rewriteServerFields($payload);
                            return FlClashCrypto::encryptPayload($payload);
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $protocolServers = $this->filterServersByRequest($request, $servers, false);
                    if (!$skipSubscriptionControl) {
                        $protocolServers = $subscriptionControl->applyToServers($user, $protocolServers, $request, $clientInfo, $subscribeLog);
                    }
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $protocolServers);
                    } else {
                        $class = new SingboxOld($user, $protocolServers);
                    }
                    return $class->handle();
                }
            }
            $servers = $this->filterServersByRequest($request, $servers, false);
            if (!$skipSubscriptionControl) {
                $servers = $subscriptionControl->applyToServers($user, $servers, $request, $clientInfo, $subscribeLog);
            }
            $class = new General($user, $servers);
            return $class->handle();
        }
    }

    private function getClientInfo(Request $request, $flag)
    {
        $clientName = null;
        $clientVersion = null;

        if (preg_match('/([a-zA-Z0-9\-_]+)[\/\s]+(v?[0-9]+(?:\.[0-9]+){0,2})/', $flag, $matches)) {
            $clientName = strtolower($matches[1]);
            $clientVersion = preg_replace('/^v/', '', $matches[2]);
        }

        if (!$clientName) {
            foreach ([
                'clash-verge',
                'clashmetaforandroid',
                'flclash',
                'clashmeta',
                'clash',
                'shadowrocket',
                'surge',
                'quantumult',
                'quanx',
                'sing-box',
                'singbox',
                'hiddify',
                'loon',
                'surfboard',
                'stash',
                'nekobox',
                'v2rayn',
                'passwall',
            ] as $name) {
                if (strpos($flag, $name) !== false) {
                    $clientName = $name;
                    break;
                }
            }
        }

        return [
            'flag' => $flag,
            'name' => $clientName,
            'version' => $clientVersion,
        ];
    }

    private function protocolMatchesFlag($protocol, $flag, $protocolClassName)
    {
        foreach ($this->getProtocolFlags($protocol, $protocolClassName) as $protocolFlag) {
            $protocolFlag = strtolower((string) $protocolFlag);
            if ($protocolFlag !== '' && strpos($flag, $protocolFlag) !== false) {
                return true;
            }
        }

        return false;
    }

    private function getProtocolFlags($protocol, $protocolClassName)
    {
        $flags = [];
        if (isset($protocol->flags) && is_array($protocol->flags)) {
            $flags = array_merge($flags, $protocol->flags);
        }
        if (isset($protocol->flag)) {
            $flags[] = $protocol->flag;
        }
        if (isset(self::PROTOCOL_FLAG_ALIASES[$protocolClassName])) {
            $flags = array_merge($flags, self::PROTOCOL_FLAG_ALIASES[$protocolClassName]);
        }

        return array_values(array_unique($flags));
    }

    private function parseRequestedTypes($typeInputString)
    {
        $typeInputString = trim((string) $typeInputString);
        if ($typeInputString === '' || strtolower($typeInputString) === 'all') {
            return self::VALID_SUBSCRIPTION_TYPES;
        }

        $requestedTypes = preg_split('/[|,｜]+/', $typeInputString);
        $types = [];
        foreach ($requestedTypes as $type) {
            $type = $this->normalizeServerType($type);
            if ($type === '' || !in_array($type, self::VALID_SUBSCRIPTION_TYPES, true)) {
                continue;
            }
            $types[] = $type;
        }

        return array_values(array_unique($types));
    }

    private function resolveAllowedServerTypesForSubscription(array $requestedTypes, $isEncryptedSubscription)
    {
        if ($isEncryptedSubscription) {
            return $requestedTypes;
        }

        $allowedTypes = config('flclash.normal_subscription_allowed_types', self::DEFAULT_NORMAL_SUBSCRIPTION_ALLOWED_TYPES);
        if (!is_array($allowedTypes)) {
            $allowedTypes = self::DEFAULT_NORMAL_SUBSCRIPTION_ALLOWED_TYPES;
        }

        $normalizedAllowedTypes = [];
        foreach ($allowedTypes as $type) {
            $type = $this->normalizeServerType($type);
            if ($type === '' || !in_array($type, self::VALID_SUBSCRIPTION_TYPES, true)) {
                continue;
            }
            $normalizedAllowedTypes[] = $type;
        }

        return array_values(array_intersect($requestedTypes, array_unique($normalizedAllowedTypes)));
    }

    private function parseFilterKeywords($filterInputString)
    {
        $filterInputString = trim((string) $filterInputString);
        if ($filterInputString === '') {
            return null;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($filterInputString) : strlen($filterInputString);
        if ($length > 20) {
            return null;
        }

        $keywords = preg_split('/[|,｜]+/', $filterInputString);
        $normalizedKeywords = [];
        foreach ($keywords as $keyword) {
            $keyword = trim((string) $keyword);
            if ($keyword !== '') {
                $normalizedKeywords[] = $keyword;
            }
        }

        return $normalizedKeywords ? array_values(array_unique($normalizedKeywords)) : null;
    }

    private function filterServersByRequest(Request $request, array $servers, $isEncryptedSubscription)
    {
        $requestedTypes = $this->parseRequestedTypes($request->input('types'));
        $allowedTypes = $this->resolveAllowedServerTypesForSubscription($requestedTypes, $isEncryptedSubscription);
        $filterKeywords = $this->parseFilterKeywords($request->input('filter'));

        if (empty($allowedTypes)) {
            return [];
        }

        $filtered = [];
        foreach ($servers as $server) {
            if (!$this->serverHasAllowedType($server, $allowedTypes)) {
                continue;
            }
            if (!$this->serverMatchesFilterKeywords($server, $filterKeywords)) {
                continue;
            }
            $filtered[] = $server;
        }

        return array_values($filtered);
    }

    private function serverHasAllowedType(array $server, array $allowedTypes)
    {
        $serverTypes = [];
        if (isset($server['type'])) {
            $serverTypes[] = $this->normalizeServerType($server['type']);
        }
        if (($server['type'] ?? '') === 'v2node' && isset($server['protocol'])) {
            $serverTypes[] = $this->normalizeServerType($server['protocol']);
        }

        foreach (array_unique($serverTypes) as $serverType) {
            if ($serverType !== '' && in_array($serverType, $allowedTypes, true)) {
                return true;
            }
        }

        return false;
    }

    private function serverMatchesFilterKeywords(array $server, $filterKeywords)
    {
        if (empty($filterKeywords)) {
            return true;
        }

        $name = isset($server['name']) ? (string) $server['name'] : '';
        $tags = isset($server['tags']) && is_array($server['tags']) ? $server['tags'] : [];
        foreach ($filterKeywords as $keyword) {
            if ($name !== '' && stripos($name, $keyword) !== false) {
                return true;
            }
            if (in_array($keyword, $tags, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeServerType($type)
    {
        $type = strtolower(trim((string) $type));
        if ($type === 'ss') {
            return 'shadowsocks';
        }
        if ($type === 'hy2') {
            return 'hysteria2';
        }

        return $type;
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
        ]));
    }
}
