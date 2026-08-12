<?php

return [
    'cache_ttl' => 21600,
    'clients' => [
        [
            'id' => 'happ', 'name' => 'Happ', 'core' => 'Xray', 'featured' => true,
            'description' => '支持 Android、iOS、macOS、Windows 与 Linux 的多平台客户端。',
            'downloads' => [
                'android' => ['url' => 'https://play.google.com/store/apps/details?id=com.happproxy', 'source' => 'google-play'],
                'ios' => ['url' => 'https://apps.apple.com/us/app/happ-proxy-utility/id6504287215', 'source' => 'app-store'],
                'macos' => ['repo' => 'Happ-proxy/happ-desktop', 'patterns' => ['/\.macOS\.universal\.dmg$/i']],
                'windows' => ['repo' => 'Happ-proxy/happ-desktop', 'patterns' => ['/setup-Happ\.x64\.exe$/i']],
                'linux' => ['repo' => 'Happ-proxy/happ-desktop', 'patterns' => ['/\.linux\.x64\.deb$/i']],
            ],
        ],
        [
            'id' => 'flclashx', 'name' => 'FlClashX', 'core' => 'Mihomo', 'featured' => true,
            'description' => 'FlClash 的增强分支，支持 Android 与主流桌面系统。',
            'downloads' => [
                'android' => ['repo' => 'pluralplay/FlClashX', 'patterns' => ['/android-universal\.apk$/i']],
                'windows' => ['repo' => 'pluralplay/FlClashX', 'patterns' => ['/windows-amd64-setup\.exe$/i']],
                'macos' => ['repo' => 'pluralplay/FlClashX', 'patterns' => ['/macos-arm64\.dmg$/i', '/macos-amd64\.dmg$/i']],
                'linux' => ['repo' => 'pluralplay/FlClashX', 'patterns' => ['/linux-amd64\.AppImage$/i', '/linux-amd64\.deb$/i']],
            ],
        ],
        [
            'id' => 'rabbit-hole', 'name' => 'Rabbit Hole', 'core' => 'Mihomo', 'featured' => true,
            'description' => '面向 Apple 平台的简洁客户端。',
            'downloads' => [
                'ios' => ['url' => 'https://apps.apple.com/app/rabbithole-vpn-client/id6683309629', 'source' => 'app-store'],
                'macos' => ['url' => 'https://apps.apple.com/app/rabbithole-vpn-client/id6683309629', 'source' => 'app-store'],
            ],
        ],
        [
            'id' => 'karing', 'name' => 'Karing', 'core' => 'Sing-box',
            'description' => '基于 Sing-box 的多平台客户端；HWID 需要在客户端中启用。',
            'downloads' => [
                'android' => ['repo' => 'KaringX/karing', 'patterns' => ['/_android_arm\.apk$/i', '/_android_arm64-v8a\.apk$/i']],
                'ios' => ['url' => 'https://apps.apple.com/us/app/karing/id6472431552', 'source' => 'app-store'],
                'macos' => ['repo' => 'KaringX/karing', 'patterns' => ['/_macos_universal\.dmg$/i']],
                'windows' => ['repo' => 'KaringX/karing', 'patterns' => ['/_windows_x64\.exe$/i']],
                'linux' => ['repo' => 'KaringX/karing', 'patterns' => ['/_linux_amd64\.AppImage$/i', '/_linux_amd64\.deb$/i']],
            ],
        ],
        [
            'id' => 'prizrakbox', 'name' => 'Prizrak-Box', 'core' => 'Mihomo',
            'description' => '带有自定义路由模板的桌面客户端。',
            'downloads' => [
                'windows' => ['repo' => 'legiz-ru/Prizrak-Box', 'patterns' => ['/windows-amd64-Setup\.exe$/i', '/windows-amd64\.msi$/i']],
                'macos' => ['repo' => 'legiz-ru/Prizrak-Box', 'patterns' => ['/macos-arm64\.zip$/i', '/macos-amd64\.zip$/i']],
                'linux' => ['repo' => 'legiz-ru/Prizrak-Box', 'patterns' => ['/linux-amd64\.deb$/i']],
            ],
        ],
        [
            'id' => 'koalaclash', 'name' => 'Koala Clash', 'core' => 'Mihomo', 'featured' => true,
            'description' => 'Clash Verge Rev 的轻量增强分支。',
            'downloads' => [
                'windows' => ['repo' => 'coolcoala/clash-verge-rev-lite', 'patterns' => ['/_x64-setup\.exe$/i']],
                'macos' => ['repo' => 'coolcoala/clash-verge-rev-lite', 'patterns' => ['/_arm64\.pkg$/i', '/_x64\.pkg$/i']],
                'linux' => ['repo' => 'coolcoala/clash-verge-rev-lite', 'patterns' => ['/_amd64\.deb$/i']],
            ],
        ],
        [
            'id' => 'flowvy', 'name' => 'Flowvy', 'core' => 'Mihomo', 'featured' => true,
            'description' => '操作简洁的桌面 Mihomo 客户端。',
            'downloads' => [
                'windows' => ['repo' => 'flowvy-proxy/desktop', 'patterns' => ['/_x64\.exe$/i']],
                'macos' => ['repo' => 'flowvy-proxy/desktop', 'patterns' => ['/_arm64\.dmg$/i']],
                'linux' => ['repo' => 'flowvy-proxy/desktop', 'patterns' => ['/_x64\.deb$/i']],
            ],
        ],
        [
            'id' => 'throne', 'name' => 'Throne', 'core' => 'Sing-box',
            'description' => '功能丰富的桌面 Sing-box 客户端；HWID 需要在客户端中启用。',
            'downloads' => [
                'windows' => ['repo' => 'throneproj/Throne', 'patterns' => ['/-windows-universal-installer\.exe$/i']],
                'macos' => ['repo' => 'throneproj/Throne', 'patterns' => ['/-macos-arm64\.zip$/i', '/-macos-amd64\.zip$/i']],
                'linux' => ['repo' => 'throneproj/Throne', 'patterns' => ['/-debian-amd64\.deb$/i']],
            ],
        ],
        [
            'id' => 'v2raytun', 'name' => 'V2rayTun', 'core' => 'Xray',
            'description' => '轻量的多平台 Xray 客户端。',
            'downloads' => [
                'android' => ['url' => 'https://play.google.com/store/apps/details?id=com.v2raytun.android', 'source' => 'google-play'],
                'ios' => ['url' => 'https://apps.apple.com/en/app/v2raytun/id6476628951', 'source' => 'app-store'],
                'macos' => ['url' => 'https://apps.apple.com/en/app/v2raytun/id6476628951', 'source' => 'app-store'],
                'windows' => ['url' => 'https://v2raytun.com/', 'source' => 'website'],
            ],
        ],
        [
            'id' => 'shadowrocket', 'name' => 'ShadowRocket', 'core' => 'Other',
            'description' => 'Apple 平台常用的付费代理客户端；HWID 需要在客户端中启用。',
            'downloads' => [
                'ios' => ['url' => 'https://apps.apple.com/us/app/shadowrocket/id932747118', 'source' => 'app-store'],
                'macos' => ['url' => 'https://apps.apple.com/us/app/shadowrocket/id932747118', 'source' => 'app-store'],
            ],
        ],
        [
            'id' => 'clash-mi', 'name' => 'Clash Mi', 'core' => 'Mihomo',
            'description' => '跨平台 Mihomo 客户端；HWID 需要在客户端中启用。',
            'downloads' => [
                'android' => ['repo' => 'KaringX/clashmi', 'patterns' => ['/_android_arm\.apk$/i', '/_android_arm64-v8a\.apk$/i']],
                'ios' => ['url' => 'https://apps.apple.com/us/app/clash-mi/id6744321968', 'source' => 'app-store'],
                'macos' => ['repo' => 'KaringX/clashmi', 'patterns' => ['/_macos_universal\.dmg$/i']],
                'windows' => ['repo' => 'KaringX/clashmi', 'patterns' => ['/_windows_x64\.exe$/i']],
                'linux' => ['repo' => 'KaringX/clashmi', 'patterns' => ['/_linux_amd64\.AppImage$/i', '/_linux_amd64\.deb$/i']],
            ],
        ],
        [
            'id' => 'incy', 'name' => 'INCY', 'core' => 'Xray',
            'description' => '支持多种协议的现代多平台客户端。',
            'downloads' => [
                'android' => ['repo' => 'INCY-DEV/incy-platforms', 'patterns' => ['/^Incy\.apk$/i'], 'fallback_url' => 'https://play.google.com/store/apps/details?id=llc.itdev.incy'],
                'ios' => ['url' => 'https://apps.apple.com/us/app/incy/id6756943388', 'source' => 'app-store'],
                'macos' => ['url' => 'https://apps.apple.com/us/app/incy/id6756943388', 'source' => 'app-store'],
                'windows' => ['repo' => 'INCY-DEV/incy-platforms', 'patterns' => ['/-windows-setup\.exe$/i']],
                'linux' => ['repo' => 'INCY-DEV/incy-platforms', 'patterns' => ['/-linux-x64\.deb$/i']],
            ],
        ],
        [
            'id' => 'renoarx', 'name' => 'RenoarX', 'core' => 'Xray',
            'description' => '面向 Windows 的现代 Xray 客户端。',
            'downloads' => [
                'windows' => ['repo' => 'RonnyFX/RenoarX', 'patterns' => ['/-Setup-[^/]+\.exe$/i']],
            ],
        ],
        [
            'id' => 'deskbox', 'name' => 'DeskBox', 'core' => 'Sing-box',
            'description' => '用于管理 Sing-box 的简洁桌面客户端。',
            'downloads' => [
                'windows' => ['repo' => 'mihail-jdanov/DeskBox', 'patterns' => ['/_windows\.zip$/i']],
                'macos' => ['repo' => 'mihail-jdanov/DeskBox', 'patterns' => ['/_macos_apple_silicon\.zip$/i', '/_macos_intel\.zip$/i']],
                'linux' => ['repo' => 'mihail-jdanov/DeskBox', 'patterns' => ['/_ubuntu\.tar\.gz$/i']],
            ],
        ],
        [
            'id' => 'inhive', 'name' => 'InHive', 'core' => 'Sing-box',
            'description' => '基于 Sing-box 的邀请制客户端。',
            'downloads' => [
                'android' => ['repo' => 'TwilgateLabs/inhive-android', 'patterns' => ['/^InHive\.apk$/i', '/-arm64-v8a\.apk$/i']],
                'windows' => ['repo' => 'TwilgateLabs/inhive-windows', 'patterns' => ['/-setup\.exe$/i']],
            ],
        ],
    ],
];
