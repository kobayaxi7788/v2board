<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionControlHitLog;
use App\Models\SubscriptionControlIpRule;
use App\Models\SubscriptionControlRegionRule;
use App\Models\SubscriptionControlUaRule;
use App\Models\SubscriptionControlUserPolicy;
use App\Models\User;
use App\Services\SubscriptionControlService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class SubscriptionControlController extends Controller
{
    private $subscriptionControlService;

    public function __construct(SubscriptionControlService $subscriptionControlService)
    {
        $this->subscriptionControlService = $subscriptionControlService;
    }

    public function stats()
    {
        return $this->success($this->subscriptionControlService->dashboardStats());
    }

    public function riskUsers(Request $request)
    {
        $request->validate([
            'hours' => 'nullable|integer|min:1|max:168',
            'min_score' => 'nullable|integer|min:0|max:100',
        ]);

        $rows = $this->subscriptionControlService->buildRiskRows((int) $request->input('hours', 24));
        $minScore = (int) $request->input('min_score', 0);
        if ($minScore > 0) {
            $rows = array_values(array_filter($rows, function (array $row) use ($minScore) {
                return $row['risk_score'] >= $minScore;
            }));
        }

        return $this->success($rows);
    }

    public function userPolicies()
    {
        if ($response = $this->missingTable('v2_subscription_control_user_policies')) {
            return $response;
        }

        $rows = SubscriptionControlUserPolicy::with('user:id,email')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(function (SubscriptionControlUserPolicy $policy) {
                return $this->formatUserPolicy($policy);
            })
            ->values();

        return $this->success($rows);
    }

    public function saveUserPolicy(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_user_policies')) {
            return $response;
        }

        $request->validate([
            'user_id' => 'required|integer|exists:v2_user,id',
            'status' => 'nullable|string|in:watching,blocked,released',
            'reason' => 'nullable|string|max:255',
            'ss2022_domain' => 'nullable|string|max:255',
            'anytls_domain' => 'nullable|string|max:255',
            'anytls_sni' => 'nullable|string|max:255',
            'enabled' => 'nullable|boolean',
        ]);

        $status = $request->input('status', SubscriptionControlUserPolicy::STATUS_BLOCKED);
        $ss2022Domain = $this->cleanDomain($request->input('ss2022_domain'));
        $anytlsDomain = $this->cleanDomain($request->input('anytls_domain'));

        if ($status === SubscriptionControlUserPolicy::STATUS_BLOCKED && $ss2022Domain === '' && $anytlsDomain === '') {
            return $this->fail([422, '封控状态至少需要填写一个入口域名']);
        }

        $userId = $this->inputInt($request, 'user_id');
        $policy = SubscriptionControlUserPolicy::where('user_id', $userId)
            ->orderByDesc('id')
            ->first() ?? new SubscriptionControlUserPolicy(['user_id' => $userId]);

        if (!$policy->exists) {
            $policy->created_by = $this->createdById($request);
        }

        $policy->fill([
            'status' => $status,
            'reason' => trim((string) $request->input('reason', '')),
            'ss2022_domain' => $ss2022Domain,
            'anytls_domain' => $anytlsDomain,
            'anytls_sni' => $this->cleanDomain($request->input('anytls_sni')),
            'enabled' => $this->inputBool($request, 'enabled', true),
        ]);
        $policy->save();

        return $this->success($this->formatUserPolicy($policy->load('user:id,email')));
    }

    public function releaseUserPolicy(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_user_policies')) {
            return $response;
        }

        $request->validate(['id' => 'required|integer|exists:v2_subscription_control_user_policies,id']);

        $policy = SubscriptionControlUserPolicy::findOrFail($this->inputInt($request, 'id'));
        $policy->status = SubscriptionControlUserPolicy::STATUS_RELEASED;
        $policy->enabled = false;
        $policy->save();

        return $this->success(true);
    }

    public function batchUserPolicy(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_user_policies')) {
            return $response;
        }

        $request->validate([
            'user_ids' => 'required',
            'reason' => 'nullable|string|max:255',
            'ss2022_domain' => 'nullable|string|max:255',
            'anytls_domain' => 'nullable|string|max:255',
            'anytls_sni' => 'nullable|string|max:255',
        ]);

        $userIds = $request->input('user_ids');
        if (!is_array($userIds)) {
            $userIds = explode(',', (string) $userIds);
        }
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));

        $ss2022Domain = $this->cleanDomain($request->input('ss2022_domain'));
        $anytlsDomain = $this->cleanDomain($request->input('anytls_domain'));
        if (empty($userIds)) {
            return $this->fail([422, '请选择用户']);
        }
        if ($ss2022Domain === '' && $anytlsDomain === '') {
            return $this->fail([422, '批量封控至少需要填写一个入口域名']);
        }

        $existingUserIds = User::whereIn('id', $userIds)->pluck('id')->all();
        $saved = 0;
        foreach ($existingUserIds as $userId) {
            $policy = SubscriptionControlUserPolicy::where('user_id', $userId)->orderByDesc('id')->first()
                ?? new SubscriptionControlUserPolicy(['user_id' => $userId]);

            if (!$policy->exists) {
                $policy->created_by = $this->createdById($request);
            }

            $policy->fill([
                'status' => SubscriptionControlUserPolicy::STATUS_BLOCKED,
                'reason' => trim((string) $request->input('reason', '批量高危订阅封控')),
                'ss2022_domain' => $ss2022Domain,
                'anytls_domain' => $anytlsDomain,
                'anytls_sni' => $this->cleanDomain($request->input('anytls_sni')),
                'enabled' => true,
            ]);
            $policy->save();
            $saved++;
        }

        return $this->success(['saved' => $saved]);
    }

    public function regionRules()
    {
        if ($response = $this->missingTable('v2_subscription_control_region_rules')) {
            return $response;
        }

        $rows = SubscriptionControlRegionRule::orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (SubscriptionControlRegionRule $rule) {
                return $this->formatKeywordRule($rule);
            })
            ->values();

        return $this->success($rows);
    }

    public function saveRegionRule(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_region_rules')) {
            return $response;
        }

        $request->validate($this->keywordRuleValidationRules());
        if ($this->cleanDomain($request->input('ss2022_domain')) === '' && $this->cleanDomain($request->input('anytls_domain')) === '') {
            return $this->fail([422, '地区规则至少需要填写一个入口域名']);
        }

        $id = $this->inputInt($request, 'id');
        $rule = $id > 0
            ? SubscriptionControlRegionRule::findOrFail($id)
            : new SubscriptionControlRegionRule(['created_by' => $this->createdById($request)]);

        $this->fillKeywordRule($rule, $request, 'all');
        $rule->save();

        return $this->success($this->formatKeywordRule($rule));
    }

    public function deleteRegionRule(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_region_rules')) {
            return $response;
        }

        $request->validate(['id' => 'required|integer|exists:v2_subscription_control_region_rules,id']);
        SubscriptionControlRegionRule::findOrFail($this->inputInt($request, 'id'))->delete();

        return $this->success(true);
    }

    public function ipRules()
    {
        if ($response = $this->missingTable('v2_subscription_control_ip_rules')) {
            return $response;
        }

        $rows = SubscriptionControlIpRule::orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (SubscriptionControlIpRule $rule) {
                return $this->formatIpRule($rule);
            })
            ->values();

        return $this->success($rows);
    }

    public function saveIpRule(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_ip_rules')) {
            return $response;
        }

        $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:100',
            'rule_value' => 'required|string|max:4000',
            'ss2022_domain' => 'nullable|string|max:255',
            'anytls_domain' => 'nullable|string|max:255',
            'anytls_sni' => 'nullable|string|max:255',
            'priority' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ]);

        $ss2022Domain = $this->cleanDomain($request->input('ss2022_domain'));
        $anytlsDomain = $this->cleanDomain($request->input('anytls_domain'));
        if ($ss2022Domain === '' && $anytlsDomain === '') {
            return $this->fail([422, 'IP/CIDR 规则至少需要填写一个入口域名']);
        }

        $validations = [];
        foreach ($this->parseIpRuleValues((string) $request->input('rule_value')) as $ruleValue) {
            $validation = $this->validateIpRuleValue($ruleValue);
            if (!$validation['ret']) {
                return $this->fail([422, $validation['msg']]);
            }
            $validations[] = $validation;
        }
        if (empty($validations)) {
            return $this->fail([422, '规则名称和 IP/CIDR 不能为空']);
        }

        $saved = [];
        foreach ($validations as $index => $validation) {
            $id = $this->inputInt($request, 'id');
            if ($id > 0 && $index === 0) {
                $rule = SubscriptionControlIpRule::findOrFail($id);
            } else {
                $rule = new SubscriptionControlIpRule(['created_by' => $this->createdById($request)]);
            }

            $rule->fill([
                'name' => trim((string) $request->input('name')),
                'rule_type' => $validation['rule_type'],
                'rule_value' => $validation['rule_value'],
                'ip_version' => $validation['ip_version'],
                'ss2022_domain' => $ss2022Domain,
                'anytls_domain' => $anytlsDomain,
                'anytls_sni' => $this->cleanDomain($request->input('anytls_sni')),
                'priority' => (int) $request->input('priority', 100),
                'enabled' => $this->inputBool($request, 'enabled', true),
            ]);
            $rule->save();
            $saved[] = $this->formatIpRule($rule);
        }

        return $this->success($saved);
    }

    public function deleteIpRule(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_ip_rules')) {
            return $response;
        }

        $request->validate(['id' => 'required|integer|exists:v2_subscription_control_ip_rules,id']);
        SubscriptionControlIpRule::findOrFail($this->inputInt($request, 'id'))->delete();

        return $this->success(true);
    }

    public function uaRules()
    {
        if ($response = $this->missingTable('v2_subscription_control_ua_rules')) {
            return $response;
        }

        $rows = SubscriptionControlUaRule::orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (SubscriptionControlUaRule $rule) {
                return $this->formatKeywordRule($rule);
            })
            ->values();

        return $this->success($rows);
    }

    public function saveUaRule(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_ua_rules')) {
            return $response;
        }

        $request->validate($this->keywordRuleValidationRules());
        if ($this->cleanDomain($request->input('ss2022_domain')) === '' && $this->cleanDomain($request->input('anytls_domain')) === '') {
            return $this->fail([422, 'UA 规则至少需要填写一个入口域名']);
        }

        $id = $this->inputInt($request, 'id');
        $rule = $id > 0
            ? SubscriptionControlUaRule::findOrFail($id)
            : new SubscriptionControlUaRule(['created_by' => $this->createdById($request)]);

        $this->fillKeywordRule($rule, $request, 'any');
        $rule->save();

        return $this->success($this->formatKeywordRule($rule));
    }

    public function deleteUaRule(Request $request)
    {
        if ($response = $this->missingTable('v2_subscription_control_ua_rules')) {
            return $response;
        }

        $request->validate(['id' => 'required|integer|exists:v2_subscription_control_ua_rules,id']);
        SubscriptionControlUaRule::findOrFail($this->inputInt($request, 'id'))->delete();

        return $this->success(true);
    }

    public function hitLogs()
    {
        if ($response = $this->missingTable('v2_subscription_control_hit_logs')) {
            return $response;
        }

        $rows = SubscriptionControlHitLog::with('user:id,email')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(function (SubscriptionControlHitLog $log) {
                return [
                    'id' => $log->id,
                    'user_id' => $log->user_id,
                    'email' => $log->user ? $log->user->email : '',
                    'policy_type' => $log->policy_type,
                    'policy_type_text' => $this->policyTypeText($log->policy_type),
                    'policy_id' => $log->policy_id,
                    'request_ip' => $log->request_ip,
                    'ip_location' => $log->ip_location,
                    'subscribe_type' => $log->subscribe_type,
                    'matched_keywords' => $log->matched_keywords,
                    'replaced_types' => $log->replaced_types,
                    'created_at' => $log->created_at ? $log->created_at->toDateTimeString() : '',
                ];
            })
            ->values();

        return $this->success($rows);
    }

    public function testMatch(Request $request)
    {
        $request->validate([
            'ip' => 'required|ip',
            'user_id' => 'nullable|integer|exists:v2_user,id',
            'user_agent' => 'nullable|string|max:2000',
        ]);

        $user = $request->filled('user_id') ? User::find($this->inputInt($request, 'user_id')) : null;

        return $this->success($this->subscriptionControlService->getMatchedRules(
            (string) $request->input('ip'),
            (string) $request->input('user_agent', ''),
            $user
        ));
    }

    public function exportRisk(Request $request)
    {
        $request->validate([
            'hours' => 'nullable|integer|min:1|max:168',
            'min_score' => 'nullable|integer|min:0|max:100',
        ]);

        $rows = $this->subscriptionControlService->buildRiskRows((int) $request->input('hours', 24));
        $minScore = (int) $request->input('min_score', 0);
        if ($minScore > 0) {
            $rows = array_values(array_filter($rows, function (array $row) use ($minScore) {
                return $row['risk_score'] >= $minScore;
            }));
        }

        $csv = "用户ID,邮箱,请求数,IP数,地区数,UA数,5分钟峰值,风险分,风险等级,标签,策略状态,最后请求时间\n";
        foreach ($rows as $row) {
            $csv .= implode(',', [
                $row['user_id'],
                $this->csv($row['email']),
                $row['request_count'],
                $row['ip_count'],
                $row['location_count'],
                $row['ua_count'],
                $row['max_5m'],
                $row['risk_score'],
                $this->csv($row['risk_level_text']),
                $this->csv($row['risk_tags']),
                $this->csv($row['policy_status_text']),
                $this->csv($row['last_time']),
            ]) . "\n";
        }

        return response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="subscription_risk_' . date('Ymd_His') . '.csv"',
        ]);
    }

    private function keywordRuleValidationRules(): array
    {
        return [
            'id' => 'nullable|integer',
            'name' => 'required|string|max:100',
            'keywords' => 'required|string|max:255',
            'match_mode' => 'nullable|string|in:all,any',
            'ss2022_domain' => 'nullable|string|max:255',
            'anytls_domain' => 'nullable|string|max:255',
            'anytls_sni' => 'nullable|string|max:255',
            'priority' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ];
    }

    private function fillKeywordRule($rule, Request $request, string $defaultMatchMode): void
    {
        $ss2022Domain = $this->cleanDomain($request->input('ss2022_domain'));
        $anytlsDomain = $this->cleanDomain($request->input('anytls_domain'));

        $rule->fill([
            'name' => trim((string) $request->input('name')),
            'keywords' => trim((string) $request->input('keywords')),
            'match_mode' => $request->input('match_mode', $defaultMatchMode) === 'all' ? 'all' : 'any',
            'ss2022_domain' => $ss2022Domain,
            'anytls_domain' => $anytlsDomain,
            'anytls_sni' => $this->cleanDomain($request->input('anytls_sni')),
            'priority' => (int) $request->input('priority', 100),
            'enabled' => $this->inputBool($request, 'enabled', true),
        ]);
    }

    private function formatUserPolicy(SubscriptionControlUserPolicy $policy): array
    {
        return [
            'id' => $policy->id,
            'user_id' => $policy->user_id,
            'email' => $policy->user ? $policy->user->email : '',
            'status' => $policy->status,
            'status_text' => $policy->statusText(),
            'reason' => $policy->reason,
            'ss2022_domain' => $policy->ss2022_domain,
            'anytls_domain' => $policy->anytls_domain,
            'anytls_sni' => $policy->anytls_sni,
            'enabled' => (int) $policy->enabled,
            'created_at' => $policy->created_at ? $policy->created_at->toDateTimeString() : '',
            'updated_at' => $policy->updated_at ? $policy->updated_at->toDateTimeString() : '',
        ];
    }

    private function formatKeywordRule($rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'keywords' => $rule->keywords,
            'match_mode' => $rule->match_mode,
            'match_mode_text' => $rule->matchModeText(),
            'ss2022_domain' => $rule->ss2022_domain,
            'anytls_domain' => $rule->anytls_domain,
            'anytls_sni' => $rule->anytls_sni,
            'priority' => $rule->priority,
            'enabled' => (int) $rule->enabled,
            'created_at' => $rule->created_at ? $rule->created_at->toDateTimeString() : '',
            'updated_at' => $rule->updated_at ? $rule->updated_at->toDateTimeString() : '',
        ];
    }

    private function formatIpRule(SubscriptionControlIpRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'rule_type' => $rule->rule_type,
            'rule_type_text' => $rule->ruleTypeText(),
            'rule_value' => $rule->rule_value,
            'ip_version' => $rule->ip_version,
            'ss2022_domain' => $rule->ss2022_domain,
            'anytls_domain' => $rule->anytls_domain,
            'anytls_sni' => $rule->anytls_sni,
            'priority' => $rule->priority,
            'enabled' => (int) $rule->enabled,
            'created_at' => $rule->created_at ? $rule->created_at->toDateTimeString() : '',
            'updated_at' => $rule->updated_at ? $rule->updated_at->toDateTimeString() : '',
        ];
    }

    private function parseIpRuleValues(string $ruleValue): array
    {
        $normalized = str_replace(["\r\n", "\r", "，", "、", ';', '；', ','], "\n", $ruleValue);
        $parts = preg_split('/\s+/u', $normalized) ?: [];
        $values = [];
        $seen = [];

        foreach ($parts as $part) {
            $part = trim(str_replace('::ffff:', '', $part));
            if ($part === '') {
                continue;
            }
            $key = strtolower($part);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $values[] = $part;
        }

        return $values;
    }

    private function validateIpRuleValue(string $ruleValue): array
    {
        $ruleValue = trim(str_replace('::ffff:', '', $ruleValue));
        if (strpos($ruleValue, '/') === false) {
            if (!filter_var($ruleValue, FILTER_VALIDATE_IP)) {
                return ['ret' => 0, 'msg' => '指定 IP 格式不正确：' . $ruleValue];
            }

            return [
                'ret' => 1,
                'rule_type' => 'single',
                'rule_value' => $ruleValue,
                'ip_version' => strpos($ruleValue, ':') !== false ? 6 : 4,
            ];
        }

        [$network, $prefixText] = array_pad(explode('/', $ruleValue, 2), 2, '');
        $network = trim(str_replace('::ffff:', '', $network));
        $prefixText = trim($prefixText);
        if (!filter_var($network, FILTER_VALIDATE_IP) || $prefixText === '' || !ctype_digit($prefixText)) {
            return ['ret' => 0, 'msg' => 'CIDR IP 或前缀格式不正确：' . $ruleValue];
        }

        $ipVersion = strpos($network, ':') !== false ? 6 : 4;
        $prefix = (int) $prefixText;
        $maxPrefix = $ipVersion === 6 ? 128 : 32;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return ['ret' => 0, 'msg' => 'CIDR 前缀范围不正确：' . $ruleValue];
        }

        return [
            'ret' => 1,
            'rule_type' => 'cidr',
            'rule_value' => $network . '/' . $prefix,
            'ip_version' => $ipVersion,
        ];
    }

    private function policyTypeText(string $type): string
    {
        switch ($type) {
            case 'user':
                return '用户';
            case 'ip':
                return 'IP/CIDR';
            case 'ua':
                return 'UA';
            case 'region':
                return '地区';
            default:
                return $type;
        }
    }

    private function cleanDomain($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('#^https?://#i', '', $value) ?? $value;
        return mb_substr(trim(explode('/', $value, 2)[0]), 0, 255);
    }

    private function missingTable(string $table)
    {
        if (Schema::hasTable($table)) {
            return null;
        }

        return $this->fail([500, '订阅风控数据表不存在，请先执行 php artisan migrate']);
    }

    private function csv($value): string
    {
        return '"' . str_replace('"', '""', (string) $value) . '"';
    }

    private function success($data)
    {
        return response(['data' => $data]);
    }

    private function fail(array $payload)
    {
        $status = isset($payload[0]) ? (int) $payload[0] : 500;
        $message = isset($payload[1]) ? (string) $payload[1] : '操作失败';

        return response([
            'status' => 'fail',
            'message' => $message,
        ], $status);
    }

    private function createdById(Request $request): int
    {
        $user = $request->input('user', []);
        return is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    }

    private function inputInt(Request $request, string $key, int $default = 0): int
    {
        return (int) $request->input($key, $default);
    }

    private function inputBool(Request $request, string $key, bool $default = false): bool
    {
        if (!$request->has($key)) {
            return $default;
        }

        return filter_var($request->input($key), FILTER_VALIDATE_BOOLEAN);
    }
}
