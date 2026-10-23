=== Easy Location ===
Tags: comments, ip, location, argon, ip2region
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

在 Argon 主题的评论区显示访客 IP 归属地，基于 ip2region 离线库，不调用任何外部接口。

== Description ==

Easy Location 会在 Argon 主题评论的 UA 信息后追加一个地球图标和评论者的 IP 归属地（国家 / 省份 / 城市）。

* 纯离线查询：使用本地 ip2region xdb 数据库，不依赖第三方 API
* 同时支持 IPv4 与 IPv6
* 后台「设置 → Easy Location」可查看库文件信息、在线测试查询
* 仅公开归属地，不公开完整 IP

**注意：本插件仅适用于 Argon 主题**，依赖主题提供的 `argon_comment_ua_icon` 过滤器。

== Installation ==

1. 后台「插件 → 安装插件 → 上传插件」，选择 easy-location.zip 安装并启用
2. 进入「设置 → Easy Location」确认 IPv4 / IPv6 库文件状态正常
3. 打开任意带评论的文章即可看到归属地

== Frequently Asked Questions ==

= 为什么评论下没有显示归属地？ =
请确认当前主题是 Argon，并在「设置 → Easy Location」中检查库文件是否齐全；本地测试环境（127.0.0.1 等内网 IP）无法解析归属地。

= 如何更新 IP 库？ =
从 ip2region 项目下载最新的 ip2region_v4.xdb、ip2region_v6.xdb 覆盖到插件目录，并修改 version.txt 中的版本号。

== Changelog ==

= 1.0.0 =
* 首次发布

== Credits ==

IP 数据与查询类库来自 ip2region（https://github.com/lionsoul2014/ip2region），遵循 Apache License 2.0。
