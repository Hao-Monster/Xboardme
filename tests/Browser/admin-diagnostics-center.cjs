// Real browser UI for D7.2 Customer Connection Diagnostics Log Center (#/?diagnostics=1); synthetic API boundary only.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { test, before, after } = require('node:test');
const { launchBrowser } = require('./helpers/browser-runtime.cjs');
const { expect } = require('playwright/test');

let browser;
before(async () => { browser = await launchBrowser(); });
after(async () => { await browser?.close(); });

function makeFixtureData() {
  const order101Card = {
    order_id: 101,
    trade_no: 'TRD-20261018-001',
    order_status: 3,
    order_type: 'normal_new',
    customer_display_name: '测试客户<img src=x onerror="window.__xss=1">',
    subscription_name: '极速订阅-A',
    diagnostic_id: '111111111111111111111111',
  };
  const order102Card = {
    order_id: 102,
    trade_no: 'TRD-20261018-002',
    order_status: 3,
    order_type: 'distributor_renewal',
    customer_display_name: '分销续费客户B',
    subscription_name: '分销专属-B',
    diagnostic_id: '222222222222222222222222',
  };
  const connItem101 = {
    node_id: 11,
    runtime_id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    connection_id: 'cccccccccccccccccccccccccccccccc',
    target_id: 0,
    trace_id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    first_event_time: '2026-10-18T10:00:01Z',
    last_event_time: '2026-10-18T10:00:05Z',
    max_seq: 4,
    event_count: 4,
    start_observed: false,
    protocol: 'vless',
    network: 'tcp',
    dest_domain: 'api.example.com',
    requested_host: 'api.example.com',
    observed_domain: 'api.example.com',
    dest_port: 443,
    domain_source: 'sniff_tls',
    outbound_tag: 'proxy-hk-01',
    selected_target_address: '203.0.113.50:443',
    socket_peer: '198.51.100.10:443',
    stage: 'dial',
    transport_state: 'closed',
    outcome: 'error',
    reason_code: 'dial_timeout',
    error_type: 'net_timeout',
    safe_message: 'dial tcp timeout <script>window.__xss=2</script>',
    error_stages: ['dns', 'dial'],
    reason_codes: ['dial_timeout'],
    has_exception_stack: true,
    resolver_operation_ids: ['dddddddddddddddddddddddddddddddd'],
  };
  const connItem101NoStack = {
    node_id: 11,
    runtime_id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    connection_id: 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
    target_id: 1,
    trace_id: 'ffffffffffffffffffffffffffffffff',
    first_event_time: '2026-10-18T10:01:01Z',
    last_event_time: '2026-10-18T10:01:02Z',
    max_seq: 2,
    event_count: 2,
    start_observed: true,
    protocol: 'hysteria2',
    network: 'udp',
    dest_domain: 'dns.example.org',
    requested_host: 'dns.example.org',
    observed_domain: 'dns.example.org',
    dest_port: 53,
    domain_source: 'destination',
    outbound_tag: 'direct',
    selected_target_address: '192.0.2.88:53',
    socket_peer: '192.0.2.88:53',
    stage: 'relay',
    transport_state: 'active',
    outcome: 'ok',
    reason_code: 'none',
    error_type: null,
    safe_message: 'udp relay active',
    error_stages: [],
    reason_codes: [],
    has_exception_stack: false,
    resolver_operation_ids: [],
  };
  const connDetail101 = {
    mode: 'order',
    order_id: 101,
    diagnostic_id: '111111111111111111111111',
    node_id: 11,
    runtime_id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    connection_id: 'cccccccccccccccccccccccccccccccc',
    snapshot_id: 'fedcba9876543210fedcba9876543210',
    as_of: '2026-10-18T10:05:00Z',
    storage_generation: 1,
    start_in_window: false,
    window_note: '开始记录不在当前窗口',
    empty_message: null,
    scan_truncated: false,
    truncated: false,
    coverage: {
      state: 'partial',
      reasons: ['node_unsupported'],
      nodes: [
        { node_id: 11, node_name: 'HK-Node-11', capability: 'supported', collector: 'healthy', known_dropped_events: 0 },
        { node_id: 12, node_name: 'Legacy-Node-12', capability: 'unsupported', collector: 'unknown', known_dropped_events: null },
      ],
    },
    events: [
      {
        seq: 2,
        event_time: '2026-10-18T10:00:02Z',
        event: 'diag.dns.result',
        stage: 'dns',
        span_id: '1111111111111112',
        parent_span_id: '1111111111111111',
        trace_id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        span_status: 'ok',
        outcome: 'ok',
        reason_code: 'none',
        safe_message: 'resolved via shared dns',
        resolver_operation_id: 'dddddddddddddddddddddddddddddddd',
        domain_source: 'sniff_tls',
      },
      {
        seq: 4,
        event_time: '2026-10-18T10:00:05Z',
        event: 'diag.dial.result',
        stage: 'dial',
        span_id: '1111111111111113',
        parent_span_id: '1111111111111111',
        trace_id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        span_status: 'error',
        outcome: 'error',
        reason_code: 'dial_timeout',
        safe_message: 'dial tcp timeout <img src=x onerror="window.__xss=3">',
        selected_target_address: '203.0.113.50:443',
        outbound_tag: 'proxy-hk-01',
        socket_peer: '198.51.100.10:443',
        exception: {
          type: '*net.OpError',
          message: 'i/o timeout',
          stacktrace: 'sing-box/common/dialer.DialContext (dialer.go:88)\n<img src=x onerror="window.__xss=4">',
          stack_capture_kind: 'creation',
          stack_truncated: true,
          stack_frames_total: 19,
        },
      },
    ],
    dns_operations: {
      dddddddddddddddddddddddddddddddd: [
        {
          seq: 1,
          event_time: '2026-10-18T10:00:01Z',
          event: 'diag.dns.summary',
          qname: 'api.example.com',
          qtype: 'A',
          rcode: 'NOERROR',
          resolver_tag: 'dns-remote',
          resolver_server: '1.1.1.1:53',
          dns_wait_ms: 28,
          coalesced_waiters: 3,
          cache_hit: false,
        },
      ],
    },
    call_chain: {
      start_in_window: false,
      spans: [
        {
          span_id: '1111111111111112',
          parent_span_id: '1111111111111111',
          trace_id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          span_name: 'dns',
          stage: 'dns',
          target_id: 0,
          duration_ms: 28,
          outcome: 'ok',
          reason_code: 'none',
          safe_message: 'resolved via shared dns',
          end_observed: true,
          missing_parent: true,
          gap_note: '父操作记录未采集',
          detail_started_mid_operation: true,
          linked_dns_operation_id: 'dddddddddddddddddddddddddddddddd',
        },
        {
          span_id: '1111111111111113',
          parent_span_id: '1111111111111112',
          trace_id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          span_name: 'dial',
          stage: 'dial',
          target_id: 0,
          duration_ms: null,
          outcome: 'error',
          reason_code: 'dial_timeout',
          safe_message: 'dial tcp timeout',
          end_observed: false,
          missing_parent: false,
          gap_note: '未观察到结束事件',
          detail_started_mid_operation: false,
          linked_dns_operation_id: null,
        },
      ],
    },
    next_cursor: null,
  };

  return { order101Card, order102Card, connItem101, connItem101NoStack, connDetail101 };
}

async function createDiagnosticsFixture(t, width = 1440) {
  const page = await browser.newPage({ viewport: { width, height: 900 } });
  page.setDefaultTimeout(5000);
  const data = makeFixtureData();
  const state = {
    lookupCalls: [],
    queryCalls: [],
    connectionCalls: [],
    startCalls: [],
    stopCalls: [],
    statusCalls: 0,
    leasePhase: 'idle',
    delayOrder101Query: false,
    releaseOrder101Query: null,
    forceEmptyItems: false,
  };
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('console', (message) => {
    if (message.type() === 'error' && !/Failed to load resource/.test(message.text())) errors.push(message.text());
  });
  t.after(async () => {
    await page.close();
    assert.deepEqual(errors, []);
  });

  await page.route('**/*', async (route) => {
    const url = new URL(route.request().url());
    if (url.pathname === '/') {
      return route.fulfill({
        contentType: 'text/html',
        body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
        <body style="margin:0">
          <div style="display:flex;max-width:100vw;overflow-x:hidden">
            <aside style="width:220px;flex:none"><nav><a href="#/">仪表盘</a><a href="#/order">订单管理</a></nav></aside>
            <main style="flex:1;min-width:0"><h1>订单管理</h1><table><thead><tr><th>订单号</th></tr></thead><tbody><tr><td>TRD-20261018-001</td></tr></tbody></table></main>
          </div>
        </body></html>`,
      });
    }
    if (url.pathname === '/favicon.ico') return route.fulfill({ status: 204 });

    if (url.pathname.endsWith('/user/distributor/options')) {
      return route.fulfill({ json: { data: [{ id: 7, email: 'dealer@example.invalid', distributor_name: '测试分销商', banned: false }] } });
    }
    if (url.pathname.endsWith('/order/fetch')) {
      return route.fulfill({
        json: {
          total: 1,
          data: [{
            id: 102,
            trade_no: 'TRD-20261018-002',
            subscription_name: '分销专属-B',
            order_type_label: '续费',
            type: 2,
            subscription_trade_no: 'TRD-20261001-000',
            created_at: 1760780000,
            customer_name: '分销续费客户B',
            bound_devices: [],
            used_traffic: 1024,
            distributor_name: '测试分销商',
            plan: { name: '旗舰套餐' },
            total_amount: 5000,
            settlement_status: 0,
            remark: '',
          }],
        },
      });
    }
    if (url.pathname.endsWith('/order/detail')) {
      const body = route.request().postDataJSON() || {};
      const id = Number(body.id || 101);
      if (id === 102) {
        return route.fulfill({
          json: {
            data: {
              id: 102,
              trade_no: 'TRD-20261018-002',
              is_distributor_order: true,
              subscription_name: '分销专属-B',
              subscribe_url: 'https://sub.example.invalid/s/secret-token-102',
              customer_name: '分销续费客户B',
              order_type_label: '续费',
              type: 2,
              total_amount: 5000,
              settlement_status: 0,
            },
          },
        });
      }
      return route.fulfill({
        json: {
          data: {
            id: 101,
            trade_no: 'TRD-20261018-001',
            is_distributor_order: false,
            subscription_name: '极速订阅-A',
            subscribe_url: 'https://sub.example.invalid/s/secret-token-101',
          },
        },
      });
    }

    if (url.pathname.endsWith('/order/diagnostics/lookup')) {
      const body = route.request().postDataJSON() || {};
      state.lookupCalls.push(body);
      if (body.order_id === 101 || body.trade_no === 'TRD-20261018-001') {
        return route.fulfill({ json: { status: 'success', data: data.order101Card } });
      }
      if (body.order_id === 102 || body.trade_no === 'TRD-20261018-002') {
        return route.fulfill({ json: { status: 'success', data: data.order102Card } });
      }
      return route.fulfill({
        status: 404,
        json: { status: 'fail', message: '未找到精确匹配的订单', error: { code: 'order_not_found' } },
      });
    }

    if (url.pathname.endsWith('/order/diagnostics/query')) {
      const body = route.request().postDataJSON() || {};
      state.queryCalls.push(body);
      if (body.order_id === 101 && state.delayOrder101Query) {
        await new Promise((resolve) => { state.releaseOrder101Query = resolve; });
      }
      const isOrder102 = body.order_id === 102 || body.diagnostic_id === '222222222222222222222222';
      const diagId = isOrder102 ? '222222222222222222222222' : '111111111111111111111111';
      const traceFilter = body.filters?.trace_id || '';
      let items = isOrder102
        ? [{ ...data.connItem101NoStack, dest_domain: 'order102-only.example.net' }]
        : [data.connItem101, data.connItem101NoStack];
      if (traceFilter) {
        items = items.filter((item) => item.trace_id === traceFilter);
      }
      if (state.forceEmptyItems) items = [];
      return route.fulfill({
        json: {
          status: 'success',
          data: {
            mode: body.diagnostic_id ? 'diagnostic_id' : 'order',
            order_id: body.order_id || null,
            diagnostic_id: diagId,
            identity_card: body.diagnostic_id
              ? { order_id: null, trade_no: null, order_bound: false, binding_note: '未绑定订单（只读客户日志 ID 模式，开启详细诊断需绑定订单）', diagnostic_id: diagId }
              : (isOrder102 ? data.order102Card : data.order101Card),
            snapshot_id: '33333333333333333333333333333333',
            as_of: '2026-10-18T10:05:00Z',
            storage_generation: 1,
            items,
            next_cursor: null,
            scan_truncated: false,
            truncated: false,
            empty_message: items.length === 0 ? '所选范围没有收到匹配记录' : null,
            coverage: {
              state: body.diagnostic_id ? 'unknown' : 'partial',
              reasons: body.diagnostic_id ? ['diagnostic_id_unbound_order'] : ['node_unsupported'],
              nodes: body.diagnostic_id ? [] : [
                { node_id: 11, node_name: 'HK-Node-11', capability: 'supported', collector: 'healthy', known_dropped_events: 0 },
                { node_id: 12, node_name: 'Legacy-Node-12', capability: 'unsupported', collector: 'unknown', known_dropped_events: null },
              ],
            },
          },
        },
      });
    }

    if (url.pathname.endsWith('/order/diagnostics/connection')) {
      const body = route.request().postDataJSON() || {};
      state.connectionCalls.push(body);
      if (body.connection_id === 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee') {
        return route.fulfill({
          json: {
            status: 'success',
            data: {
              ...data.connDetail101,
              connection_id: 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
              start_in_window: true,
              window_note: null,
              events: [{
                seq: 1,
                event_time: '2026-10-18T10:01:01Z',
                event: 'diag.relay.result',
                stage: 'relay',
                span_id: '2222222222222221',
                trace_id: 'ffffffffffffffffffffffffffffffff',
                span_status: 'ok',
                outcome: 'ok',
                reason_code: 'none',
                safe_message: 'udp relay ok',
                exception: { stack_capture_kind: 'unavailable', stacktrace: null },
              }],
              dns_operations: {},
              call_chain: { start_in_window: true, spans: [] },
            },
          },
        });
      }
      return route.fulfill({ json: { status: 'success', data: data.connDetail101 } });
    }

    if (url.pathname.endsWith('/order/diagnostics/start')) {
      const body = route.request().postDataJSON() || {};
      state.startCalls.push(body);
      state.leasePhase = 'pending';
      return route.fulfill({
        json: {
          status: 'success',
          data: {
            state: 'pending',
            session: {
              session_id: '99999999999999999999999999999999',
              diagnostic_id: '111111111111111111111111',
              order_id: body.order_id,
              enabled: true,
              expires_at: '2026-10-18T10:20:00Z',
              effective_nodes_count: 0,
              supported_nodes_count: 1,
              eligible_nodes_count: 2,
            },
            nodes: [
              { node_id: 11, node_name: 'HK-Node-11', capability: 'supported', state: 'pending', message: '等待节点确认', observer_state: 'healthy', dropped_total: 0 },
              { node_id: 12, node_name: 'Legacy-Node-12', capability: 'unsupported', state: 'unsupported', message: '节点版本不支持动态连接诊断', observer_state: 'unknown', dropped_total: null },
            ],
          },
        },
      });
    }

    if (url.pathname.endsWith('/order/diagnostics/stop')) {
      const body = route.request().postDataJSON() || {};
      state.stopCalls.push(body);
      state.leasePhase = 'stopped';
      return route.fulfill({
        json: {
          status: 'success',
          data: {
            state: 'stopped',
            session: {
              session_id: body.session_id,
              diagnostic_id: '111111111111111111111111',
              order_id: body.order_id,
              enabled: false,
              expires_at: '2026-10-18T10:20:00Z',
              effective_nodes_count: 0,
              supported_nodes_count: 1,
              eligible_nodes_count: 2,
            },
            nodes: [],
          },
        },
      });
    }

    if (url.pathname.endsWith('/order/diagnostics/status')) {
      state.statusCalls += 1;
      const phase = state.leasePhase;
      const session = phase === 'idle' ? null : {
        session_id: '99999999999999999999999999999999',
        diagnostic_id: '111111111111111111111111',
        order_id: Number(url.searchParams.get('order_id') || 101),
        enabled: ['pending', 'partial', 'active'].includes(phase),
        expires_at: '2026-10-18T10:20:00Z',
        effective_nodes_count: phase === 'partial' ? 1 : (phase === 'active' ? 2 : 0),
        supported_nodes_count: 2,
        eligible_nodes_count: 2,
      };
      return route.fulfill({
        json: {
          status: 'success',
          data: {
            state: phase,
            session,
            nodes: [
              { node_id: 11, node_name: 'HK-Node-11', capability: 'supported', state: phase === 'idle' ? 'idle' : phase, message: phase === 'active' ? '详细诊断已生效' : '等待节点确认', observer_state: 'healthy', dropped_total: 0 },
              { node_id: 12, node_name: 'Legacy-Node-12', capability: 'unsupported', state: 'unsupported', message: '节点版本不支持动态连接诊断', observer_state: 'unknown', dropped_total: null },
            ],
          },
        },
      });
    }

    errors.push(`Unexpected request: ${url.pathname}`);
    return route.abort();
  });

  await page.goto('http://127.0.0.1/');
  await page.evaluate(() => {
    localStorage.setItem('XBOARD_ACCESS_TOKEN', JSON.stringify({ value: 'qa-admin-token', expire: null }));
    window.settings = { secure_path: 'qa-admin' };
  });
  await page.addStyleTag({ content: fs.readFileSync('public/assets/admin-distributor.css', 'utf8') });
  await page.addScriptTag({ content: fs.readFileSync('public/assets/admin-distributor.js', 'utf8') });

  return { page, state, data };
}

for (const width of [1440, 390]) {
  test(`diagnostics center layout, idempotent menu, dark/light theme, call chain, DNS and stacktrace at ${width}px`, async (t) => {
    const f = await createDiagnosticsFixture(t, width);

    // Verify idempotent sidebar menu injection even after DOM mutations
    await f.page.evaluate(() => {
      const dummy = document.createElement('span');
      document.body.appendChild(dummy);
      dummy.remove();
    });
    await expect(f.page.locator('.xboard-diagnostics-center-nav')).toHaveCount(1);
    await f.page.locator('.xboard-diagnostics-center-nav').click();
    await expect(f.page.locator('#admin-diagnostics-root.open')).toBeVisible();

    // Lookup by exact trade_no
    await f.page.locator('#diag-trade-no-input').fill('TRD-20261018-001');
    await f.page.locator('[data-diag-action="lookup-trade-no"]').click();
    await expect(f.page.locator('[data-diag-id-value]')).toHaveText('111111111111111111111111');
    await expect(f.page.locator('[data-diag-trade-no]')).toHaveText('TRD-20261018-001');

    // Verify XSS payload in customer_display_name & safe_message is escaped as text, never executed
    assert.equal(await f.page.evaluate(() => window.__xss), undefined);
    await expect(f.page.locator('[data-diag-identity-card]')).toContainText('测试客户<img src=x onerror="window.__xss=1">');

    // Open connection detail with stacktrace, call chain, shared DNS, and window gap
    await f.page.locator('[data-diag-open-connection="0"]').click();
    await expect(f.page.locator('[data-diag-window-gap]')).toHaveText('开始记录不在当前窗口');
    await expect(f.page.locator('[data-diag-gap="missing_parent"]')).toHaveText('父操作记录未采集');
    await expect(f.page.locator('[data-diag-gap="unclosed"]')).toHaveText('未观察到结束事件');
    await expect(f.page.locator('[data-diag-dns-link="dddddddddddddddddddddddddddddddd"]')).toBeVisible();
    await expect(f.page.locator('[data-diag-dns-op="dddddddddddddddddddddddddddddddd"]')).toContainText('NOERROR');
    await expect(f.page.locator('[data-diag-capture-kind="creation"]')).toContainText('creation');
    await expect(f.page.locator('[data-diag-stacktrace]')).toContainText('sing-box/common/dialer.DialContext');

    // Verify distinct badges for IP, domain_source, outbound, socket_peer
    await expect(f.page.locator('[data-diag-detail-panel] [data-diag-tag="ip"]').first()).toContainText('203.0.113.50:443');
    await expect(f.page.locator('[data-diag-detail-panel] [data-diag-tag="domain_source"]').first()).toContainText('sniff_tls');
    await expect(f.page.locator('[data-diag-detail-panel] [data-diag-tag="outbound"]').first()).toContainText('proxy-hk-01');
    await expect(f.page.locator('[data-diag-detail-panel] [data-diag-tag="socket_peer"]').first()).toContainText('198.51.100.10:443');

    // Open second connection without stacktrace: must show "未捕获异常栈", never "没有错误"
    await f.page.locator('[data-diag-open-connection="1"]').click();
    await expect(f.page.locator('[data-diag-no-stack]')).toContainText('未捕获异常栈');
    const detailText = await f.page.locator('[data-diag-detail-panel]').textContent();
    assert.equal(detailText.includes('没有错误'), false);

    // Check dark theme and horizontal overflow in both themes
    assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await f.page.evaluate(() => document.documentElement.classList.add('dark'));
    assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await f.page.evaluate(() => window.__xss), undefined);

    if (process.env.QA_ARTIFACT_DIR) {
      fs.mkdirSync(process.env.QA_ARTIFACT_DIR, { recursive: true });
      await f.page.screenshot({ path: path.join(process.env.QA_ARTIFACT_DIR, `diagnostics-center-${width}.png`) });
    }
  });
}

test('order shortcuts (ordinary detail, distributor row, distributor detail) pass only order_id', async (t) => {
  const f = await createDiagnosticsFixture(t, 1440);

  // 1. Ordinary order detail dialog shortcut
  await f.page.evaluate(async () => {
    const dialog = document.createElement('section');
    dialog.id = 'qa-native-order-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.innerHTML = '<h2>订单详情</h2><div>TRD-20261018-001</div><button>关闭</button>';
    document.body.appendChild(dialog);
    await window.fetch('/api/v2/qa-admin/order/detail', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: 101 }),
    });
  });
  const ordinaryShortcut = f.page.locator('#qa-native-order-dialog [data-open-order-diagnostics="101"]');
  await expect(ordinaryShortcut).toBeVisible();
  await ordinaryShortcut.click();
  await expect.poll(() => f.page.url()).toMatch(/#\/\?diagnostics=1&order_id=101$/);
  await expect(f.page.locator('[data-diag-id-value]')).toHaveText('111111111111111111111111');
  assert.equal(f.page.url().includes('secret-token'), false);

  // 2. Distributor order list row shortcut
  await f.page.locator('[data-diag-action="close-center"]').click();
  await f.page.locator('#admin-dist-entry').click();
  const rowShortcut = f.page.locator('#admin-dist-root [data-open-order-diagnostics="102"]');
  await expect(rowShortcut).toBeVisible();
  await rowShortcut.click();
  await expect.poll(() => f.page.url()).toMatch(/#\/\?diagnostics=1&order_id=102$/);
  await expect(f.page.locator('[data-diag-id-value]')).toHaveText('222222222222222222222222');

  // 3. Distributor order detail modal shortcut
  await f.page.locator('[data-diag-action="close-center"]').click();
  await f.page.locator('#admin-dist-entry').click();
  await f.page.locator('#admin-dist-root [data-order-detail="102"]').click();
  const detailShortcut = f.page.locator('#admin-dist-detail [data-open-order-diagnostics="102"]');
  await expect(detailShortcut).toBeVisible();
  await detailShortcut.click();
  await expect(f.page.locator('#admin-diagnostics-root.open')).toBeVisible();
  await expect(f.page.locator('[data-diag-id-value]')).toHaveText('222222222222222222222222');
});

test('similar trade_no prefix rejection, read-only diagnostic_id mode, trace_id filter, and empty state message', async (t) => {
  const f = await createDiagnosticsFixture(t, 1440);
  await f.page.locator('.xboard-diagnostics-center-nav').click();

  // Similar prefix must be rejected without guessing identity
  await f.page.locator('#diag-trade-no-input').fill('TRD-20261018-00');
  await f.page.locator('[data-diag-action="lookup-trade-no"]').click();
  await expect(f.page.locator('[data-diag-error]')).toContainText('未找到精确匹配的订单');
  await expect(f.page.locator('[data-diag-id-value]')).toHaveCount(0);

  // Switch to read-only diagnostic_id mode
  await f.page.locator('[data-diag-mode="diagnostic_id"]').click();
  await f.page.locator('#diag-id-input').fill('222222222222222222222222');
  await f.page.locator('[data-diag-action="query-diag-id"]').click();

  await expect(f.page.locator('[data-diag-identity-card] .diag-mode-badge.readonly')).toHaveText('未绑定订单');
  await expect(f.page.locator('[data-diag-action="start-lease"]')).toBeDisabled();
  await expect(f.page.locator('[data-diag-action="stop-lease"]')).toBeDisabled();
  await expect(f.page.locator('[data-diag-connections-table]')).toContainText('order102-only.example.net');

  // Exact trace_id filter & empty state message ("所选范围没有收到匹配记录")
  f.state.forceEmptyItems = true;
  await f.page.locator('#diag-filter-trace-id').fill('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
  await f.page.locator('[data-diag-action="apply-filters"]').click();
  await expect(f.page.locator('[data-diag-empty]')).toHaveText('所选范围没有收到匹配记录');
  assert.equal(f.state.queryCalls[f.state.queryCalls.length - 1].filters.trace_id, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
});

test('15-minute lease start/pending/partial/active/stop and 5s polling stops on leave while preserving dashboard polling', async (t) => {
  const f = await createDiagnosticsFixture(t, 1440);
  await f.page.clock.install();

  // Install a simulated native Dashboard polling counter to prove our leave logic does not break Dashboard polling
  await f.page.evaluate(() => {
    window.__dashboardNativeTicks = 0;
    setInterval(() => { window.__dashboardNativeTicks += 1; }, 5000);
  });

  await f.page.evaluate(() => { location.hash = '#/?diagnostics=1&order_id=101'; });
  await expect(f.page.locator('[data-diag-id-value]')).toHaveText('111111111111111111111111');

  // Start 15-minute lease -> pending ("等待节点确认")
  await f.page.locator('[data-diag-action="start-lease"]').click();
  await expect(f.page.locator('[data-diag-lease-status]')).toContainText('等待节点确认');
  assert.equal(f.state.startCalls.length, 1);
  assert.equal(f.state.startCalls[0].order_id, 101);
  assert.match(f.state.startCalls[0].idempotency_key, /^start-101-/);

  // Advance 5s -> status poll updates to partial
  f.state.leasePhase = 'partial';
  await f.page.clock.fastForward(5100);
  await expect(f.page.locator('[data-diag-lease-status]')).toContainText('部分节点已生效（1 / 2 节点）');

  // Advance 5s -> status poll updates to active with original expiration time
  f.state.leasePhase = 'active';
  await f.page.clock.fastForward(5100);
  await expect(f.page.locator('[data-diag-lease-status]')).toContainText('详细诊断已生效（2 / 2 节点）');
  await expect(f.page.locator('[data-diag-lease-status]')).toContainText('2026-10-18T10:20:00Z');

  // Explicit stop button
  await f.page.locator('[data-diag-action="stop-lease"]').click();
  await expect(f.page.locator('[data-diag-lease-status]')).toContainText('已停止详细诊断');
  assert.equal(f.state.stopCalls.length, 1);

  // Leave diagnostics center -> diagnostics polling stops, native Dashboard polling continues
  const statusCallsBeforeLeave = f.state.statusCalls;
  const nativeTicksBeforeLeave = await f.page.evaluate(() => window.__dashboardNativeTicks);
  await f.page.locator('[data-diag-action="close-center"]').click();
  await f.page.clock.fastForward(5100);
  await f.page.clock.fastForward(5100);
  await f.page.clock.fastForward(5100);
  assert.equal(f.state.statusCalls, statusCallsBeforeLeave);
  const nativeTicksAfterLeave = await f.page.evaluate(() => window.__dashboardNativeTicks);
  assert.equal(nativeTicksAfterLeave >= nativeTicksBeforeLeave + 3, true);
});

test('rapid customer/mode switch cancels stale requests, history back/forward restores entry, and auth loss clears data', async (t) => {
  const f = await createDiagnosticsFixture(t, 1440);

  // Delay Order 101 query and immediately switch to Order 102
  f.state.delayOrder101Query = true;
  await f.page.evaluate(() => { location.hash = '#/?diagnostics=1&order_id=101'; });
  await expect.poll(() => f.state.queryCalls.length).toBe(1);

  await f.page.evaluate(() => { location.hash = '#/?diagnostics=1&order_id=102'; });
  await expect(f.page.locator('[data-diag-id-value]')).toHaveText('222222222222222222222222');
  await expect(f.page.locator('[data-diag-connections-table]')).toContainText('order102-only.example.net');

  // Release delayed Order 101 response -> must NOT overwrite Order 102
  f.state.delayOrder101Query = false;
  if (f.state.releaseOrder101Query) f.state.releaseOrder101Query();
  await f.page.waitForTimeout(100);
  await expect(f.page.locator('[data-diag-id-value]')).toHaveText('222222222222222222222222');
  await expect(f.page.locator('[data-diag-connections-table]')).toContainText('order102-only.example.net');

  // History back restores Order 101
  await f.page.goBack();
  await expect(f.page.locator('[data-diag-id-value]')).toHaveText('111111111111111111111111');

  // Removing auth token clears displayed data
  await f.page.evaluate(() => localStorage.removeItem('XBOARD_ACCESS_TOKEN'));
  await expect(f.page.locator('[data-diag-error]')).toContainText('管理员登录已失效');
  await expect(f.page.locator('[data-diag-id-value]')).toHaveCount(0);
});

test('late connection detail response must not overwrite newer list snapshot time range or render stale detail', async (t) => {
  const f = await createDiagnosticsFixture(t, 1440);
  const rangeA = { from: '2026-10-20T10:00:00Z', to: '2026-10-20T10:15:00Z' };
  const rangeB = { from: '2026-10-20T10:00:02Z', to: '2026-10-20T10:15:02Z' };
  let listCount = 0;
  let detailBody = null;
  let releaseDetail;
  const queryRequests = [];
  let pageStatus;

  await f.page.route('**/order/diagnostics/query', async route => {
    const body = route.request().postDataJSON();
    queryRequests.push(body);
    if (body.cursor) {
      pageStatus = ((!body.from || body.from === rangeB.from) && (!body.to || body.to === rangeB.to)) ? 200 : 422;
      if (pageStatus === 422) {
        return route.fulfill({
          status: 422,
          json: { status: 'fail', message: 'cursor_parameter_mismatch', error: { code: 'cursor_parameter_mismatch' } },
        });
      }
    }
    const range = (++listCount === 1) ? rangeA : rangeB;
    return route.fulfill({
      json: {
        status: 'success',
        data: {
          mode: 'order',
          order_id: 101,
          diagnostic_id: f.data.order101Card.diagnostic_id,
          identity_card: f.data.order101Card,
          snapshot_id: listCount === 1 ? 'snapshot-a' : 'snapshot-b',
          ...range,
          items: [f.data.connItem101],
          next_cursor: body.cursor ? null : 'cursor-b',
          scan_truncated: false,
          truncated: false,
          coverage: { state: 'complete', reasons: [], nodes: [] },
        },
      },
    });
  });

  await f.page.route('**/order/diagnostics/connection', async route => {
    detailBody = route.request().postDataJSON();
    await new Promise(resolve => { releaseDetail = resolve; });
    return route.fulfill({
      json: {
        status: 'success',
        data: { ...f.data.connDetail101, ...rangeA },
      },
    });
  });

  await f.page.evaluate(() => { location.hash = '#/?diagnostics=1&order_id=101'; });
  await expect(f.page.locator('[data-diag-open-connection="0"]')).toBeVisible();
  await f.page.locator('[data-diag-open-connection="0"]').click();
  await expect.poll(() => detailBody !== null).toBe(true);

  await f.page.locator('[data-diag-action="apply-filters"]').click();
  await expect.poll(() => queryRequests.length).toBe(2);
  await expect(f.page.locator('[data-diag-action="next-page"]')).toBeEnabled();

  const detailResponse = f.page.waitForResponse(response => response.url().endsWith('/order/diagnostics/connection'));
  releaseDetail();
  await detailResponse;
  await f.page.waitForTimeout(100);

  await f.page.locator('[data-diag-action="next-page"]').click();
  await expect.poll(() => queryRequests.length).toBe(3);
  assert.equal(pageStatus, 200);
  assert.equal(queryRequests.at(-1).from, rangeB.from);
  assert.equal(queryRequests.at(-1).to, rangeB.to);
});
