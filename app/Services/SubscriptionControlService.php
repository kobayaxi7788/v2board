<?php

namespace App\Services;

use App\Models\SubscriptionControlHitLog;
use App\Models\SubscriptionControlIpRule;
use App\Models\SubscriptionControlRegionRule;
use App\Models\SubscriptionControlUaRule;
use App\Models\SubscriptionControlUserPolicy;
use App\Models\User;
use App\Models\UserSubscribeLog;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SubscriptionControlService
{
    private const UNKNOWN_LOCATION = '未知';

    private static $tableCache = [];

    private $policyCache = [];
    private $locationCache = [];

    public function recordSubscribeRequest(User $user, Request $request, array $clientInfo = []): ?UserSubscribeLog
    {
        if (!$this->tableExists('v2_user_subscribe_log')) {
            return null;
        }

        return $this->safe(function () use ($user, $request, $clientInfo) {
            $ip = $this->getRequestIp($request);
            $userAgent = (string) $request->userAgent();
            $location = $this->getIpLocationText($ip);
            $clientType = $this->detectClientType($userAgent, $clientInfo['name'] ?? null);
            $subscribeType = $this->resolveSubscribeType($clientInfo, $request);
            $risk = $this->scoreSingleRequest($userAgent, $location, $clientType);
            $policy = $this->resolvePolicy($user, $ip, $userAgent);

            if ($policy !== null) {
                $risk['score'] += 15;
                $risk['tags'][] = '命中封控策略';
            }

            return UserSubscribeLog::create([
                'user_id' => $user->id,
                'request_ip' => $ip,
                'request_user_agent' => $this->truncate($userAgent, 2000),
                'client_type' => $this->truncate($clientType, 64),
                'subscribe_type' => $this->truncate($subscribeType, 64),
                'request_path' => $this->truncate('/' . ltrim($request->path(), '/'), 255),
                'query_string' => $this->truncate((string) $request->getQueryString(), 512),
                'ip_location' => $this->truncate($location, 255),
                'risk_score' => min(100, (int) $risk['score']),
                'risk_tags' => $this->truncate(implode('、', array_unique($risk['tags'])), 255),
                'matched_policy_type' => $policy['type'] ?? '',
                'matched_policy_id' => $policy['id'] ?? 0,
            ]);
        });
    }

    public function applyToServers(
        User $user,
        array $servers,
        Request $request,
        array $clientInfo = [],
        ?UserSubscribeLog $subscribeLog = null
    ): array {
        $ip = $this->getRequestIp($request);
        $userAgent = (string) $request->userAgent();
        $policy = $this->resolvePolicy($user, $ip, $userAgent);

        if ($policy === null || empty($servers)) {
            return $servers;
        }

        $replacedTypes = [];
        $rewritten = [];

        foreach ($servers as $server) {
            if ($this->isSs2022Server($server) && $policy['ss2022_domain'] !== '') {
                $server['host'] = $policy['ss2022_domain'];
                $replacedTypes['ss2022'] = true;
            }

            if (($server['type'] ?? '') === 'anytls' && $policy['anytls_domain'] !== '') {
                $server['host'] = $policy['anytls_domain'];
                $sni = $policy['anytls_sni'] !== '' ? $policy['anytls_sni'] : $policy['anytls_domain'];
                $server['server_name'] = $sni;
                data_set($server, 'protocol_settings.tls.server_name', $sni);
                data_set($server, 'protocol_settings.tls_settings.server_name', $sni);
                $replacedTypes['anytls'] = true;
            }

            $rewritten[] = $server;
        }

        if (!empty($replacedTypes)) {
            $replaced = implode(',', array_keys($replacedTypes));
            $subscribeType = $this->resolveSubscribeType($clientInfo, $request);
            $this->recordPolicyHit($user, $policy, $replaced, $subscribeType, $userAgent);
            $this->markSubscribeLogApplied($subscribeLog, $policy, $replaced);
        }

        return $rewritten;
    }

    public function resolvePolicy(User $user, ?string $requestIp = null, ?string $userAgent = null): ?array
    {
        $requestIp = $this->normalizeIp($requestIp ?: request()->ip());
        $userAgent = (string) ($userAgent ?? request()->userAgent());
        $cacheKey = $user->id . '|' . $requestIp . '|' . md5($userAgent);

        if (array_key_exists($cacheKey, $this->policyCache)) {
            return $this->policyCache[$cacheKey];
        }

        $location = $this->getIpLocationText($requestIp);

        $manual = $this->safe(function () use ($user) {
            if (!$this->tableExists('v2_subscription_control_user_policies')) {
                return null;
            }

            return SubscriptionControlUserPolicy::query()
                ->where('user_id', $user->id)
                ->where('enabled', true)
                ->where('status', SubscriptionControlUserPolicy::STATUS_BLOCKED)
                ->orderByDesc('id')
                ->first();
        });

        if ($manual !== null) {
            return $this->policyCache[$cacheKey] = $this->buildPolicy('user', $manual, $requestIp, $location, '', $userAgent);
        }

        foreach ($this->enabledIpRules() as $rule) {
            if ($this->matchIpRule($requestIp, $rule)) {
                return $this->policyCache[$cacheKey] = $this->buildPolicy('ip', $rule, $requestIp, $location, (string) $rule->rule_value, $userAgent);
            }
        }

        foreach ($this->enabledUaRules() as $rule) {
            $matched = $this->matchTextKeywords($userAgent, (string) $rule->keywords, (string) $rule->match_mode);
            if ($matched !== false) {
                return $this->policyCache[$cacheKey] = $this->buildPolicy('ua', $rule, $requestIp, $location, implode(' ', $matched), $userAgent);
            }
        }

        foreach ($this->enabledRegionRules() as $rule) {
            $matched = $this->matchTextKeywords($location, (string) $rule->keywords, (string) $rule->match_mode);
            if ($matched !== false) {
                return $this->policyCache[$cacheKey] = $this->buildPolicy('region', $rule, $requestIp, $location, implode(' ', $matched), $userAgent);
            }
        }

        return $this->policyCache[$cacheKey] = null;
    }

    public function getMatchedRules(string $requestIp, string $userAgent = '', ?User $user = null): array
    {
        $requestIp = $this->normalizeIp($requestIp);
        $location = $this->getIpLocationText($requestIp);
        $matchedIpRules = [];
        $matchedUaRules = [];
        $matchedRegionRules = [];

        foreach ($this->enabledIpRules() as $rule) {
            if ($this->matchIpRule($requestIp, $rule)) {
                $matchedIpRules[] = $this->formatRuleRow($rule, (string) $rule->rule_value);
            }
        }

        foreach ($this->enabledUaRules() as $rule) {
            $matched = $this->matchTextKeywords($userAgent, (string) $rule->keywords, (string) $rule->match_mode);
            if ($matched !== false) {
                $matchedUaRules[] = $this->formatRuleRow($rule, implode(' ', $matched));
            }
        }

        foreach ($this->enabledRegionRules() as $rule) {
            $matched = $this->matchTextKeywords($location, (string) $rule->keywords, (string) $rule->match_mode);
            if ($matched !== false) {
                $matchedRegionRules[] = $this->formatRuleRow($rule, implode(' ', $matched));
            }
        }

        return [
            'ip' => $requestIp,
            'location' => $location,
            'user_agent' => $userAgent,
            'ip_rules' => $matchedIpRules,
            'ua_rules' => $matchedUaRules,
            'region_rules' => $matchedRegionRules,
            'effective_policy' => $user ? $this->resolvePolicy($user, $requestIp, $userAgent) : null,
        ];
    }

    public function dashboardStats(): array
    {
        $today = now()->startOfDay();
        $riskRows = $this->buildRiskRows(24);

        return [
            'today_subscribe_count' => $this->countTable('v2_user_subscribe_log', function () use ($today) {
                return UserSubscribeLog::where('created_at', '>=', $today)->count();
            }),
            'high_risk_count' => collect($riskRows)->where('risk_level', 'high')->count(),
            'active_user_policy_count' => $this->countTable('v2_subscription_control_user_policies', function () {
                return SubscriptionControlUserPolicy::where('enabled', true)->where('status', SubscriptionControlUserPolicy::STATUS_BLOCKED)->count();
            }),
            'region_rule_count' => $this->countTable('v2_subscription_control_region_rules', function () {
                return SubscriptionControlRegionRule::where('enabled', true)->count();
            }),
            'ip_rule_count' => $this->countTable('v2_subscription_control_ip_rules', function () {
                return SubscriptionControlIpRule::where('enabled', true)->count();
            }),
            'ua_rule_count' => $this->countTable('v2_subscription_control_ua_rules', function () {
                return SubscriptionControlUaRule::where('enabled', true)->count();
            }),
            'today_hit_count' => $this->countTable('v2_subscription_control_hit_logs', function () use ($today) {
                return SubscriptionControlHitLog::where('created_at', '>=', $today)->count();
            }),
        ];
    }

    public function buildRiskRows(int $hours): array
    {
        if (!$this->tableExists('v2_user_subscribe_log')) {
            return [];
        }

        $hours = max(1, min(168, $hours));
        $since = now()->subHours($hours);
        $logs = $this->safe(function () use ($since) {
            return UserSubscribeLog::query()
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')
                ->limit(8000)
                ->get();
        }, collect());

        $groups = [];
        foreach ($logs as $log) {
            $uid = (int) $log->user_id;
            if ($uid <= 0) {
                continue;
            }

            if (!isset($groups[$uid])) {
                $groups[$uid] = [
                    'logs' => 0,
                    'ips' => [],
                    'locations' => [],
                    'uas' => [],
                    'clients' => [],
                    'types' => [],
                    'times' => [],
                    'bad_ua' => false,
                    'empty_ua' => false,
                    'last_time' => '',
                ];
            }

            $ip = (string) $log->request_ip;
            $ua = trim((string) $log->request_user_agent);
            $location = (string) $log->ip_location;
            $client = (string) $log->client_type;
            $time = $log->created_at ? $log->created_at->timestamp : 0;

            $groups[$uid]['logs']++;
            if ($ip !== '') {
                $groups[$uid]['ips'][$ip] = true;
            }
            if ($location !== '') {
                $groups[$uid]['locations'][$location] = true;
            }
            if ($ua !== '') {
                $groups[$uid]['uas'][$ua] = true;
            } else {
                $groups[$uid]['empty_ua'] = true;
            }
            if ($client !== '') {
                $groups[$uid]['clients'][$client] = true;
            }
            if ($log->subscribe_type !== '') {
                $groups[$uid]['types'][(string) $log->subscribe_type] = true;
            }
            if ($time > 0) {
                $groups[$uid]['times'][] = $time;
                if ($groups[$uid]['last_time'] === '' || $time > strtotime($groups[$uid]['last_time'])) {
                    $groups[$uid]['last_time'] = $log->created_at->toDateTimeString();
                }
            }
            if ($this->isBadUserAgent($ua)) {
                $groups[$uid]['bad_ua'] = true;
            }
        }

        $userIds = array_keys($groups);
        $users = $this->loadUsers($userIds);
        $policies = $this->loadActivePolicyMap($userIds);
        $rows = [];

        foreach ($groups as $uid => $data) {
            $requestCount = $data['logs'];
            $ipCount = count($data['ips']);
            $locationCount = count($data['locations']);
            $uaCount = count($data['uas']);
            $clientCount = count($data['clients']);
            $typeCount = count($data['types']);
            $max5m = $this->maxCountInWindow($data['times'], 300);
            $scoreData = $this->scoreAggregate($requestCount, $ipCount, $locationCount, $uaCount, $clientCount, $typeCount, $max5m, $data['empty_ua'], $data['bad_ua']);
            $user = $users[$uid] ?? null;
            $policy = $policies[$uid] ?? null;

            $rows[] = [
                'user_id' => (int) $uid,
                'email' => $user ? $user->email : '',
                'request_count' => $requestCount,
                'ip_count' => $ipCount,
                'location_count' => $locationCount,
                'ua_count' => $uaCount,
                'client_count' => $clientCount,
                'type_count' => $typeCount,
                'max_5m' => $max5m,
                'risk_score' => $scoreData['score'],
                'risk_level' => $scoreData['level'],
                'risk_level_text' => $scoreData['level_text'],
                'risk_tags' => implode('、', $scoreData['tags']),
                'last_time' => $data['last_time'],
                'policy_id' => $policy ? $policy->id : 0,
                'policy_status' => $policy ? $policy->status : '',
                'policy_status_text' => $policy ? $policy->statusText() : '',
            ];
        }

        usort($rows, function (array $a, array $b) {
            if ($a['risk_score'] === $b['risk_score']) {
                return $b['request_count'] <=> $a['request_count'];
            }
            return $b['risk_score'] <=> $a['risk_score'];
        });

        return $rows;
    }

    public function getRequestIp(Request $request): string
    {
        $candidates = [
            $request->headers->get('CF-Connecting-IP'),
            $request->headers->get('X-Real-IP'),
            explode(',', (string) $request->headers->get('X-Forwarded-For'))[0] ?? '',
            $request->ip(),
        ];

        foreach ($candidates as $candidate) {
            $ip = $this->normalizeIp((string) $candidate);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }

    public function getIpLocationText(string $ip): string
    {
        $ip = $this->normalizeIp($ip);
        if ($ip === '') {
            return self::UNKNOWN_LOCATION;
        }
        if (array_key_exists($ip, $this->locationCache)) {
            return $this->locationCache[$ip];
        }

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->locationCache[$ip] = self::UNKNOWN_LOCATION;
        }

        return $this->locationCache[$ip] = $this->safe(function () use ($ip) {
            if (!class_exists('\Ip2Region')) {
                return self::UNKNOWN_LOCATION;
            }

            $location = (string) (new \Ip2Region())->simple($ip);
            $location = trim(str_replace(['0|', '|0', '内网IP|内网IP'], ['', '', '内网IP'], $location), '| ');
            return $location !== '' ? $location : self::UNKNOWN_LOCATION;
        }, self::UNKNOWN_LOCATION);
    }

    public function detectClientType(string $userAgent, ?string $clientName = null): string
    {
        if ($clientName) {
            return strtolower($clientName);
        }

        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return 'unknown';
        }

        $map = [
            'clash-verge' => 'clash-verge',
            'flclash' => 'flclash',
            'clashmetaforandroid' => 'clashmetaforandroid',
            'clash' => 'clash',
            'shadowrocket' => 'shadowrocket',
            'surge' => 'surge',
            'quantumult' => 'quantumultx',
            'quanx' => 'quantumultx',
            'v2rayn' => 'v2rayn',
            'sing-box' => 'sing-box',
            'singbox' => 'sing-box',
            'hiddify' => 'hiddify',
            'loon' => 'loon',
            'surfboard' => 'surfboard',
            'stash' => 'stash',
            'nekobox' => 'nekobox',
            'passwall' => 'passwall',
            'curl' => 'curl',
            'wget' => 'wget',
            'python' => 'python',
            'go-http-client' => 'go-http-client',
        ];

        foreach ($map as $needle => $name) {
            if (strpos($ua, $needle) !== false) {
                return $name;
            }
        }

        return 'other';
    }

    public function scoreSingleRequest(string $userAgent, string $location = '', string $clientType = ''): array
    {
        $score = 0;
        $tags = [];
        $ua = trim($userAgent);
        $uaLower = strtolower($ua);

        if ($ua === '') {
            $score += 30;
            $tags[] = '空UA';
        }

        if ($this->isBadUserAgent($uaLower)) {
            $score += 25;
            $tags[] = '疑似机器UA';
        }

        if (in_array($clientType, ['unknown', 'other'], true)) {
            $score += 8;
            $tags[] = '未知客户端';
        }

        if ($location === '' || $location === self::UNKNOWN_LOCATION) {
            $score += 5;
            $tags[] = '未知归属地';
        }

        return ['score' => min(100, $score), 'tags' => $tags];
    }

    public function scoreAggregate(
        int $requestCount,
        int $ipCount,
        int $locationCount,
        int $uaCount,
        int $clientCount,
        int $typeCount,
        int $max5m,
        bool $emptyUa,
        bool $badUa
    ): array {
        $score = 0;
        $tags = [];

        if ($requestCount >= 200) {
            $score += 60;
            $tags[] = '订阅请求极高';
        } elseif ($requestCount >= 80) {
            $score += 40;
            $tags[] = '订阅请求过高';
        } elseif ($requestCount >= 30) {
            $score += 20;
            $tags[] = '订阅偏频繁';
        }

        if ($max5m >= 30) {
            $score += 45;
            $tags[] = '短时间爆发请求';
        } elseif ($max5m >= 12) {
            $score += 30;
            $tags[] = '短时间高频';
        } elseif ($max5m >= 6) {
            $score += 15;
            $tags[] = '短时间偏频繁';
        }

        if ($ipCount >= 10) {
            $score += 45;
            $tags[] = '来源IP过多';
        } elseif ($ipCount >= 5) {
            $score += 28;
            $tags[] = '多IP访问';
        } elseif ($ipCount >= 3) {
            $score += 12;
            $tags[] = '多来源IP';
        }

        if ($locationCount >= 5) {
            $score += 30;
            $tags[] = '多地区访问';
        } elseif ($locationCount >= 3) {
            $score += 18;
            $tags[] = '跨地区访问';
        }

        if ($clientCount >= 4 || $typeCount >= 4) {
            $score += 15;
            $tags[] = '客户端类型混杂';
        }
        if ($emptyUa) {
            $score += 20;
            $tags[] = '存在空UA';
        }
        if ($badUa) {
            $score += 25;
            $tags[] = '存在机器UA';
        }
        if ($uaCount >= 8) {
            $score += 10;
            $tags[] = 'UA变化较多';
        }

        $score = min(100, $score);
        if ($score >= 70) {
            return ['score' => $score, 'level' => 'high', 'level_text' => '高危', 'tags' => $tags ?: ['暂无明显异常']];
        }
        if ($score >= 40) {
            return ['score' => $score, 'level' => 'medium', 'level_text' => '可疑', 'tags' => $tags ?: ['暂无明显异常']];
        }

        return ['score' => $score, 'level' => 'low', 'level_text' => '观察', 'tags' => $tags ?: ['暂无明显异常']];
    }

    private function enabledIpRules(): Collection
    {
        if (!$this->tableExists('v2_subscription_control_ip_rules')) {
            return collect();
        }

        return $this->safe(function () {
            return SubscriptionControlIpRule::where('enabled', true)->orderBy('priority')->orderBy('id')->get();
        }, collect());
    }

    private function enabledUaRules(): Collection
    {
        if (!$this->tableExists('v2_subscription_control_ua_rules')) {
            return collect();
        }

        return $this->safe(function () {
            return SubscriptionControlUaRule::where('enabled', true)->orderBy('priority')->orderBy('id')->get();
        }, collect());
    }

    private function enabledRegionRules(): Collection
    {
        if (!$this->tableExists('v2_subscription_control_region_rules')) {
            return collect();
        }

        return $this->safe(function () {
            return SubscriptionControlRegionRule::where('enabled', true)->orderBy('priority')->orderBy('id')->get();
        }, collect());
    }

    private function buildPolicy(string $type, object $row, string $requestIp, string $location, string $matchedKeywords, string $userAgent): array
    {
        return [
            'type' => $type,
            'id' => (int) $row->id,
            'user_id' => isset($row->user_id) ? (int) $row->user_id : 0,
            'name' => (string) ($row->name ?? ''),
            'reason' => (string) ($row->reason ?? ''),
            'keywords' => (string) ($row->keywords ?? ''),
            'rule_type' => (string) ($row->rule_type ?? ''),
            'rule_value' => (string) ($row->rule_value ?? ''),
            'matched_keywords' => $matchedKeywords,
            'request_ip' => $requestIp,
            'ip_location' => $location,
            'user_agent' => $userAgent,
            'ss2022_domain' => trim((string) $row->ss2022_domain),
            'anytls_domain' => trim((string) $row->anytls_domain),
            'anytls_sni' => trim((string) $row->anytls_sni),
        ];
    }

    private function formatRuleRow(object $rule, string $matchedKeywords): array
    {
        return [
            'id' => (int) $rule->id,
            'name' => (string) ($rule->name ?? ''),
            'keywords' => (string) ($rule->keywords ?? ''),
            'rule_type' => (string) ($rule->rule_type ?? ''),
            'rule_value' => (string) ($rule->rule_value ?? ''),
            'matched_keywords' => $matchedKeywords,
            'priority' => (int) ($rule->priority ?? 0),
            'ss2022_domain' => (string) ($rule->ss2022_domain ?? ''),
            'anytls_domain' => (string) ($rule->anytls_domain ?? ''),
            'anytls_sni' => (string) ($rule->anytls_sni ?? ''),
        ];
    }

    private function matchTextKeywords(string $text, string $keywords, string $matchMode)
    {
        $text = trim($text);
        $keywords = trim($keywords);
        if ($text === '' || $keywords === '') {
            return false;
        }

        $parts = preg_split('/\s+/u', $keywords) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), function ($part) {
            return $part !== '';
        }));
        if (empty($parts)) {
            return false;
        }

        $matched = [];
        foreach ($parts as $part) {
            if (mb_stripos($text, $part) !== false) {
                $matched[] = $part;
            }
        }

        if ($matchMode === 'all') {
            return count($matched) === count($parts) ? $matched : false;
        }

        return !empty($matched) ? $matched : false;
    }

    private function matchIpRule(string $requestIp, SubscriptionControlIpRule $rule): bool
    {
        $requestIp = $this->normalizeIp($requestIp);
        $ruleValue = trim((string) $rule->rule_value);
        if (!filter_var($requestIp, FILTER_VALIDATE_IP) || $ruleValue === '') {
            return false;
        }

        if ($rule->rule_type === 'cidr') {
            return $this->ipMatchesCidr($requestIp, $ruleValue);
        }

        $ruleIp = $this->normalizeIp($ruleValue);
        return filter_var($ruleIp, FILTER_VALIDATE_IP) && inet_pton($requestIp) === inet_pton($ruleIp);
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', trim($cidr), 2);
        if (count($parts) !== 2) {
            return false;
        }

        $network = $this->normalizeIp($parts[0]);
        $prefixText = trim($parts[1]);
        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($network, FILTER_VALIDATE_IP) || !ctype_digit($prefixText)) {
            return false;
        }

        $ipPacked = inet_pton($ip);
        $networkPacked = inet_pton($network);
        if ($ipPacked === false || $networkPacked === false || strlen($ipPacked) !== strlen($networkPacked)) {
            return false;
        }

        $prefix = (int) $prefixText;
        $maxBits = strlen($ipPacked) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($ipPacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
    }

    private function isSs2022Server(array $server): bool
    {
        $cipher = (string) ($server['cipher'] ?? data_get($server, 'protocol_settings.cipher', ''));
        return ($server['type'] ?? '') === 'shadowsocks'
            && strpos($cipher, '2022-') === 0;
    }

    private function recordPolicyHit(User $user, array $policy, string $replacedTypes, string $subscribeType, string $userAgent): void
    {
        if (!$this->tableExists('v2_subscription_control_hit_logs')) {
            return;
        }

        $this->safe(function () use ($user, $policy, $replacedTypes, $subscribeType, $userAgent) {
            SubscriptionControlHitLog::create([
                'user_id' => $user->id,
                'policy_type' => $policy['type'],
                'policy_id' => $policy['id'],
                'request_ip' => $policy['request_ip'],
                'ip_location' => $this->truncate($policy['ip_location'], 255),
                'user_agent' => $this->truncate($userAgent, 2000),
                'subscribe_type' => $this->truncate($subscribeType, 64),
                'matched_keywords' => $this->truncate($policy['matched_keywords'], 255),
                'replaced_types' => $this->truncate($replacedTypes, 64),
            ]);
        });
    }

    private function markSubscribeLogApplied(?UserSubscribeLog $subscribeLog, array $policy, string $replacedTypes): void
    {
        if ($subscribeLog === null) {
            return;
        }

        $this->safe(function () use ($subscribeLog, $policy, $replacedTypes) {
            $subscribeLog->matched_policy_type = $policy['type'];
            $subscribeLog->matched_policy_id = $policy['id'];
            $subscribeLog->is_policy_applied = true;
            $subscribeLog->replaced_types = $this->truncate($replacedTypes, 64);
            $subscribeLog->save();
        });
    }

    private function resolveSubscribeType(array $clientInfo, Request $request): string
    {
        if (!empty($clientInfo['name'])) {
            return (string) $clientInfo['name'];
        }
        if ($request->filled('flag')) {
            return strtolower((string) $request->input('flag'));
        }

        return $this->detectClientType((string) $request->userAgent());
    }

    private function maxCountInWindow(array $times, int $window): int
    {
        sort($times);
        $max = 0;
        $left = 0;
        $count = count($times);

        for ($right = 0; $right < $count; $right++) {
            while ($left <= $right && $times[$right] - $times[$left] > $window) {
                $left++;
            }
            $max = max($max, $right - $left + 1);
        }

        return $max;
    }

    private function loadUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        return User::whereIn('id', $userIds)->get(['id', 'email'])->keyBy('id')->all();
    }

    private function loadActivePolicyMap(array $userIds): array
    {
        if (empty($userIds) || !$this->tableExists('v2_subscription_control_user_policies')) {
            return [];
        }

        $policies = $this->safe(function () use ($userIds) {
            return SubscriptionControlUserPolicy::whereIn('user_id', $userIds)
                ->where('enabled', true)
                ->orderByDesc('id')
                ->get();
        }, collect());

        $map = [];
        foreach ($policies as $policy) {
            if (!isset($map[$policy->user_id])) {
                $map[$policy->user_id] = $policy;
            }
        }

        return $map;
    }

    private function isBadUserAgent(string $userAgent): bool
    {
        $ua = strtolower(trim($userAgent));
        if ($ua === '') {
            return false;
        }

        foreach (['curl', 'wget', 'python', 'go-http-client', 'httpclient', 'bot', 'spider', 'scrapy'] as $keyword) {
            if (strpos($ua, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalizeIp(string $ip): string
    {
        return trim(str_replace('::ffff:', '', $ip));
    }

    private function countTable(string $table, callable $callback): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        return (int) $this->safe($callback, 0);
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, self::$tableCache)) {
            return self::$tableCache[$table];
        }

        return self::$tableCache[$table] = $this->safe(function () use ($table) {
            return Schema::hasTable($table);
        }, false);
    }

    private function safe(callable $callback, $default = null)
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            Log::warning('Subscription control skipped after error', ['error' => $e->getMessage()]);
            return $default;
        }
    }

    private function truncate($value, int $length): string
    {
        $value = (string) $value;
        return mb_substr($value, 0, $length, 'UTF-8');
    }
}
