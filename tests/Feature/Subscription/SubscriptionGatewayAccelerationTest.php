<?php

namespace Tests\Feature\Subscription;

use App\Models\DistributorOrder;
use App\Models\SubscribeTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\BuildsSubscriptionFixtures;
use Tests\TestCase;

class SubscriptionGatewayAccelerationTest extends TestCase
{
    use BuildsSubscriptionFixtures;
    use RefreshDatabase;

    private const GATEWAY_IP = '103.133.179.159';
    private const GATEWAY_URL = 'https://sub.thinderbox.uk';
    private const ORIGIN_URL = 'https://cloud.thinderbox.com';

    public function test_trusted_hk_gateway_chain_preserves_client_ip_while_blocking_public_spoofing(): void
    {
        admin_setting([
            'app_url' => self::ORIGIN_URL,
            'subscribe_url' => self::GATEWAY_URL,
        ]);

        $fixtureA = $this->makeDistributorSubscriptionFixture();
        $tokenA = str_repeat('a', 64);
        $fixtureA['delivery']->update([
            'delivery_status' => DistributorOrder::DELIVERY_PENDING,
            'claim_token' => $tokenA,
            'claim_token_hash' => hash('sha256', $tokenA),
        ]);

        // Multi-hop chain: Mainland client -> HK gateway (103.133.179.159) -> Host Caddy -> V2 Edge (172.18.0.5)
        $responseA = $this->call(
            'GET',
            "/api/v1/client/distributor/claim/{$tokenA}",
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '172.18.0.5',
                'HTTP_HOST' => 'cloud.thinderbox.com',
                'HTTP_X_FORWARDED_HOST' => 'cloud.thinderbox.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '113.108.12.34, ' . self::GATEWAY_IP . ', 172.18.0.1',
                'HTTP_USER_AGENT' => 'Karing/1.2.0',
            ]
        );

        $responseA->assertStatus(302);
        $location = (string) $responseA->headers->get('Location');
        $this->assertStringStartsWith(self::GATEWAY_URL . '/s/' . $fixtureA['subscriber']->token, $location);

        $fixtureA['delivery']->refresh();
        $this->assertSame('113.108.12.34', $fixtureA['delivery']->claim_ip);

        // Untrusted public caller attempting to spoof X-Forwarded-For must be ignored
        $fixtureB = $this->makeDistributorSubscriptionFixture();
        $tokenB = str_repeat('b', 64);
        $fixtureB['delivery']->update([
            'delivery_status' => DistributorOrder::DELIVERY_PENDING,
            'claim_token' => $tokenB,
            'claim_token_hash' => hash('sha256', $tokenB),
        ]);

        $responseB = $this->call(
            'GET',
            "/api/v1/client/distributor/claim/{$tokenB}",
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '198.51.100.77',
                'HTTP_HOST' => 'cloud.thinderbox.com',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
                'HTTP_USER_AGENT' => 'Karing/1.2.0',
            ]
        );

        $responseB->assertStatus(302);
        $fixtureB['delivery']->refresh();
        $this->assertSame('198.51.100.77', $fixtureB['delivery']->claim_ip);
    }

    public function test_singbox_normalizes_mainland_rulesets_and_emits_direct_rules_for_gateway_and_origin(): void
    {
        admin_setting([
            'app_url' => self::ORIGIN_URL,
            'subscribe_url' => self::GATEWAY_URL,
        ]);

        $plan = $this->makeSubscriptionPlan();
        $subscriber = $this->makeOrdinarySubscriber($plan);
        $this->makeOnlineServer();

        $legacySingbox = json_decode((string) file_get_contents(base_path('resources/rules/default.sing-box.json')), true);
        $this->assertIsArray($legacySingbox);
        $legacySingbox['route']['rule_set'] = [
            [
                'tag' => 'geosite-cn',
                'type' => 'remote',
                'format' => 'binary',
                'url' => 'https://raw.githubusercontent.com/SagerNet/sing-geosite/rule-set/geosite-cn.srs',
                'download_detour' => '节点选择',
            ],
            [
                'tag' => 'geoip-cn',
                'type' => 'remote',
                'format' => 'binary',
                'url' => 'https://raw.githubusercontent.com/SagerNet/sing-geoip/rule-set/geoip-cn.srs',
                'download_detour' => '节点选择',
            ],
        ];
        SubscribeTemplate::setContent('singbox', json_encode($legacySingbox, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $response = $this->call(
            'GET',
            "/s/{$subscriber->token}",
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => self::GATEWAY_IP,
                'HTTP_HOST' => 'cloud.thinderbox.com',
                'HTTP_X_FORWARDED_FOR' => '113.108.12.34',
                'HTTP_USER_AGENT' => 'SFA/1.11.0 (sing-box 1.11.0)',
            ]
        );

        $response->assertOk();
        $payload = $response->json();
        $this->assertIsArray($payload);

        $ruleSets = collect($payload['route']['rule_set'] ?? [])->keyBy('tag');
        $this->assertSame(
            'https://testingcf.jsdelivr.net/gh/MetaCubeX/meta-rules-dat@sing/geo/geosite/cn.srs',
            $ruleSets['geosite-cn']['url'] ?? null
        );
        $this->assertSame('direct', $ruleSets['geosite-cn']['download_detour'] ?? null);
        $this->assertSame(
            'https://testingcf.jsdelivr.net/gh/MetaCubeX/meta-rules-dat@sing/geo/geoip/cn.srs',
            $ruleSets['geoip-cn']['url'] ?? null
        );
        $this->assertSame('direct', $ruleSets['geoip-cn']['download_detour'] ?? null);

        $directRouteDomains = collect($payload['route']['rules'] ?? [])
            ->filter(fn(array $rule) => ($rule['outbound'] ?? null) === 'direct' && isset($rule['domain']))
            ->pluck('domain')
            ->flatten()
            ->all();
        $this->assertContains('sub.thinderbox.uk', $directRouteDomains);
        $this->assertContains('cloud.thinderbox.com', $directRouteDomains);

        $localDnsDomains = collect($payload['dns']['rules'] ?? [])
            ->filter(fn(array $rule) => ($rule['server'] ?? null) === 'local' && isset($rule['domain']))
            ->pluck('domain')
            ->flatten()
            ->all();
        $this->assertContains('sub.thinderbox.uk', $localDnsDomains);
        $this->assertContains('cloud.thinderbox.com', $localDnsDomains);
    }

    public function test_clash_family_strips_bare_blocked_fake_ip_dns_and_prepends_gateway_and_origin_direct_rules(): void
    {
        admin_setting([
            'app_url' => self::ORIGIN_URL,
            'subscribe_url' => self::GATEWAY_URL,
        ]);

        $plan = $this->makeSubscriptionPlan();
        $subscriber = $this->makeOrdinarySubscriber($plan);
        $this->makeOnlineServer();

        $legacyClashYaml = <<<'YAML'
port: 7890
allow-lan: true
mode: rule
dns:
  enable: true
  enhanced-mode: fake-ip
  nameserver:
    - 119.29.29.29
    - 223.5.5.5
  nameserver-policy:
    "geosite:geolocation-!cn":
      - https://dns.cloudflare.com/dns-query
    "geosite:google":
      - https://dns.cloudflare.com/dns-query#节点选择
  fallback:
    - https://1.1.1.1/dns-query
    - tls://8.8.8.8:853
  fallback-filter:
    geoip: true
proxies:
proxy-groups:
  - name: 节点选择
    type: select
    proxies:
      - DIRECT
rules:
  - MATCH,节点选择
YAML;

        foreach ([
            ['clashmeta', 'Clash.Meta/v1.18.0'],
            ['clash', 'ClashForAndroid/2.5.12'],
            ['stash', 'Stash/2.6.0 Clash/1.15.0'],
        ] as [$templateName, $userAgent]) {
            SubscribeTemplate::setContent($templateName, $legacyClashYaml);

            $response = $this->call(
                'GET',
                "/s/{$subscriber->token}",
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => self::GATEWAY_IP,
                    'HTTP_HOST' => 'cloud.thinderbox.com',
                    'HTTP_X_FORWARDED_FOR' => '113.108.12.34',
                    'HTTP_USER_AGENT' => $userAgent,
                ]
            );

            $response->assertOk();
            $parsed = Yaml::parse((string) $response->getContent());
            $this->assertIsArray($parsed, $templateName);

            $this->assertSame(['119.29.29.29', '223.5.5.5'], $parsed['dns']['nameserver'] ?? null, $templateName);
            $this->assertArrayNotHasKey('geosite:geolocation-!cn', $parsed['dns']['nameserver-policy'] ?? [], $templateName);
            $this->assertSame(
                ['https://dns.cloudflare.com/dns-query#节点选择'],
                $parsed['dns']['nameserver-policy']['geosite:google'] ?? null,
                $templateName
            );
            $this->assertArrayNotHasKey('fallback', $parsed['dns'] ?? [], $templateName);
            $this->assertArrayNotHasKey('fallback-filter', $parsed['dns'] ?? [], $templateName);

            $rules = $parsed['rules'] ?? [];
            $this->assertContains('DOMAIN,sub.thinderbox.uk,DIRECT', $rules, $templateName);
            $this->assertContains('DOMAIN,cloud.thinderbox.com,DIRECT', $rules, $templateName);
            $this->assertSame('DOMAIN,sub.thinderbox.uk,DIRECT', $rules[0] ?? null, $templateName);
            $this->assertSame('DOMAIN,cloud.thinderbox.com,DIRECT', $rules[1] ?? null, $templateName);
        }
    }

    public function test_surge_and_surfboard_emit_gateway_managed_url_and_direct_domains(): void
    {
        admin_setting([
            'app_url' => self::ORIGIN_URL,
            'subscribe_url' => self::GATEWAY_URL,
        ]);

        $plan = $this->makeSubscriptionPlan();
        $subscriber = $this->makeOrdinarySubscriber($plan);
        $this->makeOnlineServer();

        SubscribeTemplate::flushCache('surge');
        SubscribeTemplate::flushCache('surfboard');

        foreach (['Surge iOS/3100', 'Surfboard/2.24.0'] as $userAgent) {
            $response = $this->call(
                'GET',
                "/s/{$subscriber->token}",
                [],
                [],
                [],
                [
                    'REMOTE_ADDR' => self::GATEWAY_IP,
                    'HTTP_HOST' => 'cloud.thinderbox.com',
                    'HTTP_X_FORWARDED_FOR' => '113.108.12.34',
                    'HTTP_USER_AGENT' => $userAgent,
                ]
            );

            $response->assertOk();
            $body = (string) $response->getContent();
            $this->assertStringContainsString(
                "#!MANAGED-CONFIG https://sub.thinderbox.uk/s/{$subscriber->token}",
                $body,
                $userAgent
            );
            $this->assertStringContainsString('DOMAIN,sub.thinderbox.uk,DIRECT', $body, $userAgent);
            $this->assertStringContainsString('DOMAIN,cloud.thinderbox.com,DIRECT', $body, $userAgent);
        }
    }

    public function test_gateway_proxy_hop_is_strictly_confined_to_read_only_subscription_routes(): void
    {
        admin_setting([
            'app_url' => self::ORIGIN_URL,
            'subscribe_url' => self::GATEWAY_URL,
        ]);

        $plan = $this->makeSubscriptionPlan();
        $subscriber = $this->makeOrdinarySubscriber($plan);
        $this->makeOnlineServer();

        $fixture = $this->makeDistributorSubscriptionFixture();
        $claimToken = str_repeat('c', 64);
        $fixture['delivery']->update([
            'delivery_status' => DistributorOrder::DELIVERY_PENDING,
            'claim_token' => $claimToken,
            'claim_token_hash' => hash('sha256', $claimToken),
        ]);

        $gatewayEnvironments = [
            'direct_gateway_peer' => [
                'REMOTE_ADDR' => self::GATEWAY_IP,
                'HTTP_HOST' => 'cloud.thinderbox.com',
                'HTTP_X_FORWARDED_FOR' => '113.108.12.34',
                'HTTP_USER_AGENT' => 'Clash.Meta/v1.18.0',
            ],
            'via_host_caddy_and_v2_edge' => [
                'REMOTE_ADDR' => '172.18.0.5',
                'HTTP_HOST' => 'cloud.thinderbox.com',
                'HTTP_X_FORWARDED_FOR' => '113.108.12.34, ' . self::GATEWAY_IP . ', 172.18.0.1',
                'HTTP_USER_AGENT' => 'Clash.Meta/v1.18.0',
            ],
        ];

        foreach ($gatewayEnvironments as $label => $serverVars) {
            foreach ([
                ['POST', '/api/v1/passport/auth/login', ['email' => 'a@b.com', 'password' => 'secret']],
                ['GET', '/api/v1/guest/comm/config', []],
                ['GET', '/', []],
                ['POST', "/s/{$subscriber->token}", []],
            ] as [$method, $uri, $params]) {
                $blocked = $this->call($method, $uri, $params, [], [], $serverVars);
                $this->assertSame(403, $blocked->getStatusCode(), "{$label}: expected 403 on {$method} {$uri}");
            }

            $allowedSub = $this->call('GET', "/s/{$subscriber->token}", [], [], [], $serverVars);
            $allowedSub->assertOk();

            $allowedLegacy = $this->call('GET', '/api/v1/client/subscribe', ['token' => $subscriber->token], [], [], $serverVars);
            $allowedLegacy->assertOk();
        }

        $allowedClaim = $this->call(
            'GET',
            "/api/v1/client/distributor/claim/{$claimToken}",
            [],
            [],
            [],
            $gatewayEnvironments['via_host_caddy_and_v2_edge']
        );
        $allowedClaim->assertStatus(302);

        // Anti-spoofing: attacker behind Cloudflare (172.68.10.20) spoofing X-Forwarded-For with gateway IP must NOT trigger false 403
        $spoofedViaCloudflare = $this->call(
            'GET',
            '/api/v1/guest/comm/config',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '172.18.0.5',
                'HTTP_HOST' => 'cloud.thinderbox.com',
                'HTTP_X_FORWARDED_FOR' => self::GATEWAY_IP . ', 203.0.113.9, 172.68.10.20, 172.18.0.1',
            ]
        );
        $spoofedViaCloudflare->assertOk();
    }

    public function test_edge_caddy_and_release_workflow_converge_gateway_trust_and_compression(): void
    {
        $caddyV2 = (string) file_get_contents(base_path('.docker/caddy/Caddyfile.v2'));
        $this->assertStringContainsString('trusted_proxies static private_ranges 103.133.179.159/32', $caddyV2);
        $this->assertStringContainsString('trusted_proxies_strict', $caddyV2);
        $this->assertStringContainsString('encode zstd gzip', $caddyV2);
        $this->assertStringContainsString('@untrusted_gateway_path', $caddyV2);
        $this->assertStringContainsString('respond @untrusted_gateway_path "Forbidden" 403', $caddyV2);

        $defaultSingBox = json_decode((string) file_get_contents(base_path('resources/rules/default.sing-box.json')), true);
        $this->assertIsArray($defaultSingBox);
        foreach ($defaultSingBox['route']['rule_set'] ?? [] as $ruleSet) {
            $this->assertStringStartsWith('https://testingcf.jsdelivr.net/', (string) ($ruleSet['url'] ?? ''));
            $this->assertSame('direct', $ruleSet['download_detour'] ?? null);
        }

        $defaultClash = Yaml::parse((string) file_get_contents(base_path('resources/rules/default.clash.yaml')));
        $this->assertIsArray($defaultClash);
        $this->assertArrayNotHasKey('fallback', $defaultClash['dns'] ?? []);
        $this->assertArrayNotHasKey('nameserver-policy', $defaultClash['dns'] ?? []);

        $releaseWorkflow = (string) file_get_contents(base_path('.github/workflows/production-release.yml'));
        $this->assertStringContainsString('converge-xboard-host-network.sh', $releaseWorkflow);
        $this->assertStringContainsString('converge-xboard-subscription-gateway.sh', $releaseWorkflow);
    }
}
