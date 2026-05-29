<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_user_subscribe_log')) {
            Schema::create('v2_user_subscribe_log', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id')->default(0);
                $table->string('request_ip', 64)->default('');
                $table->text('request_user_agent')->nullable();
                $table->string('client_type', 64)->default('');
                $table->string('subscribe_type', 64)->default('');
                $table->string('request_path')->default('');
                $table->string('query_string', 512)->default('');
                $table->string('ip_location')->default('');
                $table->unsignedTinyInteger('risk_score')->default(0);
                $table->string('risk_tags')->default('');
                $table->string('matched_policy_type', 32)->default('');
                $table->unsignedBigInteger('matched_policy_id')->default(0);
                $table->boolean('is_policy_applied')->default(false);
                $table->string('replaced_types', 64)->default('');
                $table->timestamps();

                $table->index(['user_id', 'created_at'], 'idx_sub_log_user_created');
                $table->index(['request_ip', 'created_at'], 'idx_sub_log_ip_created');
                $table->index('risk_score', 'idx_sub_log_risk_score');
                $table->index('created_at', 'idx_sub_log_created_at');
            });
        }

        if (!Schema::hasTable('v2_subscription_control_user_policies')) {
            Schema::create('v2_subscription_control_user_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id');
                $table->string('status', 32)->default('blocked');
                $table->string('reason')->default('');
                $table->string('ss2022_domain')->default('');
                $table->string('anytls_domain')->default('');
                $table->string('anytls_sni')->default('');
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('created_by')->default(0);
                $table->timestamps();

                $table->index(['user_id', 'enabled', 'status'], 'idx_sub_policy_user_status');
                $table->index('updated_at', 'idx_sub_policy_updated_at');
            });
        }

        if (!Schema::hasTable('v2_subscription_control_region_rules')) {
            Schema::create('v2_subscription_control_region_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->default('');
                $table->string('keywords')->default('');
                $table->string('match_mode', 16)->default('all');
                $table->string('ss2022_domain')->default('');
                $table->string('anytls_domain')->default('');
                $table->string('anytls_sni')->default('');
                $table->integer('priority')->default(100);
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('created_by')->default(0);
                $table->timestamps();

                $table->index(['enabled', 'priority', 'id'], 'idx_sub_region_enabled_priority');
            });
        }

        if (!Schema::hasTable('v2_subscription_control_ip_rules')) {
            Schema::create('v2_subscription_control_ip_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->default('');
                $table->string('rule_type', 16)->default('single');
                $table->string('rule_value', 128)->default('');
                $table->unsignedTinyInteger('ip_version')->default(4);
                $table->string('ss2022_domain')->default('');
                $table->string('anytls_domain')->default('');
                $table->string('anytls_sni')->default('');
                $table->integer('priority')->default(100);
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('created_by')->default(0);
                $table->timestamps();

                $table->index(['enabled', 'priority', 'id'], 'idx_sub_ip_enabled_priority');
                $table->index('rule_value', 'idx_sub_ip_rule_value');
            });
        }

        if (!Schema::hasTable('v2_subscription_control_ua_rules')) {
            Schema::create('v2_subscription_control_ua_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name', 100)->default('');
                $table->string('keywords')->default('');
                $table->string('match_mode', 16)->default('any');
                $table->string('ss2022_domain')->default('');
                $table->string('anytls_domain')->default('');
                $table->string('anytls_sni')->default('');
                $table->integer('priority')->default(100);
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('created_by')->default(0);
                $table->timestamps();

                $table->index(['enabled', 'priority', 'id'], 'idx_sub_ua_enabled_priority');
            });
        }

        if (!Schema::hasTable('v2_subscription_control_hit_logs')) {
            Schema::create('v2_subscription_control_hit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('user_id')->default(0);
                $table->string('policy_type', 32)->default('');
                $table->unsignedBigInteger('policy_id')->default(0);
                $table->string('request_ip', 64)->default('');
                $table->string('ip_location')->default('');
                $table->text('user_agent')->nullable();
                $table->string('subscribe_type', 64)->default('');
                $table->string('matched_keywords')->default('');
                $table->string('replaced_types', 64)->default('');
                $table->timestamps();

                $table->index(['user_id', 'created_at'], 'idx_sub_hit_user_created');
                $table->index(['policy_type', 'policy_id'], 'idx_sub_hit_policy');
                $table->index('created_at', 'idx_sub_hit_created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscription_control_hit_logs');
        Schema::dropIfExists('v2_subscription_control_ua_rules');
        Schema::dropIfExists('v2_subscription_control_ip_rules');
        Schema::dropIfExists('v2_subscription_control_region_rules');
        Schema::dropIfExists('v2_subscription_control_user_policies');
        Schema::dropIfExists('v2_user_subscribe_log');
    }
};
