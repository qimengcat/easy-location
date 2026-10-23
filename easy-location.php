<?php
/**
 * Plugin Name:       Easy Location
 * Plugin URI:        https://github.com/qimengcat/easy-location
 * Description:       在 WordPress 评论区显示访客 IP 归属地，基于本地 ip2region xdb 离线库查询，仅适用于 Argon 主题。
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.4
 * Author:            夏慕砚
 * Author URI:        https://www.xiamuyan.com
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       easy-location
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EASY_LOCATION_VERSION', '1.0.0' );
define( 'EASY_LOCATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'EASY_LOCATION_URL', plugin_dir_url( __FILE__ ) );
define( 'EASY_LOCATION_VERSION_FILE', EASY_LOCATION_DIR . 'version.txt' );
define( 'EASY_LOCATION_DB_V4', EASY_LOCATION_DIR . 'ip2region_v4.xdb' );
define( 'EASY_LOCATION_DB_V6', EASY_LOCATION_DIR . 'ip2region_v6.xdb' );
define( 'EASY_LOCATION_XDB_DIR', EASY_LOCATION_DIR . 'ip2region/xdb/' );

/* ============================================================
 * 0. 加载 ip2region 类库
 * 只加载 ip2region/xdb/Searcher.class.php
 * ============================================================ */
function easy_location_load_library() {
	static $loaded = null;
	if ( null !== $loaded ) {
		return $loaded;
	}

	$main = EASY_LOCATION_XDB_DIR . 'Searcher.class.php';
	if ( ! easy_location_file_ok( $main ) ) {
		$loaded = false;
		return $loaded;
	}

	if ( ! class_exists( '\\ip2region\\xdb\\Searcher', false ) && ! class_exists( 'XdbSearcher', false ) ) {
		require_once $main;
	}

	$loaded = class_exists( '\\ip2region\\xdb\\Searcher', false ) || class_exists( 'XdbSearcher', false );
	return $loaded;
}

/* ============================================================
 * 1. 纯离线查询（按 IP 类型自动选择 v4 / v6 库）
 * ============================================================ */
function easy_location_get_region( $ip ) {
	static $cache = array();

	if ( ! is_string( $ip ) || $ip === '' ) {
		return '';
	}
	if ( isset( $cache[ $ip ] ) ) {
		return $cache[ $ip ];
	}
	$cache[ $ip ] = '';

	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$db_file = EASY_LOCATION_DB_V6;
	} elseif ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$db_file = EASY_LOCATION_DB_V4;
	} else {
		return '';
	}

	// 库文件或类库缺失时静默返回，不刷日志；后台设置页会给出提示
	if ( ! easy_location_file_ok( $db_file ) || ! easy_location_load_library() ) {
		return '';
	}

	$region = '';

	// ip2region 3.x 调用方式
	if ( class_exists( '\\ip2region\\xdb\\Searcher', false ) ) {
		try {
			$header = \ip2region\xdb\Util::loadHeaderFromFile( $db_file );
			if ( null !== $header ) {
				$version  = \ip2region\xdb\Util::versionFromHeader( $header );
				$searcher = \ip2region\xdb\Searcher::newWithFileOnly( $version, $db_file );
				$region   = $searcher->search( $ip );
				if ( method_exists( $searcher, 'close' ) ) {
					$searcher->close();
				}
			}
		} catch ( Throwable $e ) {
			easy_location_log( 'Searcher failed: ' . $e->getMessage() );
		}
	}

	// 旧版 XdbSearcher 兜底（仅支持 IPv4）
	if ( ( ! is_string( $region ) || $region === '' ) && class_exists( 'XdbSearcher', false ) && EASY_LOCATION_DB_V4 === $db_file ) {
		try {
			$region = XdbSearcher::newWithFileOnly( $db_file )->search( $ip );
		} catch ( Throwable $e ) {
			easy_location_log( 'XdbSearcher failed: ' . $e->getMessage() );
		}
	}

	if ( ! is_string( $region ) || $region === '' ) {
		return '';
	}

	$parts    = explode( '|', $region );
	$country  = isset( $parts[0] ) ? trim( $parts[0] ) : '';
	$province = isset( $parts[1] ) ? trim( $parts[1] ) : '';
	$city     = isset( $parts[2] ) ? trim( $parts[2] ) : '';

	$text_parts = array();
	// 国内国外都显示国家
	if ( $country  !== '' && $country  !== '0' ) $text_parts[] = $country;
	if ( $province !== '' && $province !== '0' ) $text_parts[] = $province;
	if ( $city     !== '' && $city     !== '0' ) $text_parts[] = $city;

	$result = implode( ' ', $text_parts );
	if ( $result === '' ) {
		return '';
	}
	$result = preg_replace( '/[^\x20-\x7E\x{4e00}-\x{9fff}]/u', '', $result );
	if ( mb_strlen( $result ) > 30 ) {
		$result = mb_substr( $result, 0, 30 );
	}

	$cache[ $ip ] = $result;
	return $result;
}

/**
 * 仅在 WP_DEBUG 开启时写日志，避免生产环境刷屏
 */
function easy_location_log( $msg ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( '[EasyLocation] ' . $msg );
	}
}

/* ============================================================
 * 2. HTML 构建（地球图标 + 文字）
 * ============================================================ */
function easy_location_build_html( $ip ) {
	if ( ! is_string( $ip ) || $ip === '' ) return '';
	$region = easy_location_get_region( $ip );
	if ( $region === '' ) return '';

	$icon = '<svg class="icon" viewBox="0 0 1024 1024" width="32" height="32">
  <path d="M1023.98404 512c0 282.749582-229.216418 512-511.984 512C229.234458 1024 0.00004 794.749582 0.00004 512 0.00004 229.234418 229.234458 0 512.00004 0 794.767622 0 1023.98404 229.234418 1023.98404 512" fill="#5D9CEC"/>
  <path d="M998.890432 353.204481c-24.031625-73.688849-65.404978-142.171779-119.718129-198.046905-54.249152-55.781128-121.438103-99.078452-194.280964-125.218044l-21.281668-7.63988-6.3759 21.71766c-2.937954 9.907845-5.155919 17.063733-6.689896 21.375666-18.999703 8.905861-39.311386 16.937735-58.967078 24.749614-62.71502 24.841612-127.544007 50.54521-172.263309 109.482289-19.455696 25.6256-21.705661 52.391181-5.859908 69.828909 8.29587 9.139857 20.375682 13.781785 35.905439 13.781784 10.123842 0 20.687677-1.90597 29.999531-3.577944 6.561897-1.187981 13.343792-2.405962 17.327729-2.405962 0.295995 0 0.561991 0.016 0.827987 0.031999 22.921642 1.639974 65.624975 9.749848 103.874377 19.717692 42.031343 10.937829 64.312995 20.187685 73.812847 25.579601-6.3759 7.593881-23.467633 19.329698-46.345276 19.329698-11.187825 0-22.061655-2.889955-32.375494-8.579866-15.781753-8.703864-55.281136-16.967735-109.718286-26.687583l-5.281917-0.953985c-2.281964-0.405994-4.889924-0.60999-7.953876-0.609991-22.875643 0-76.8288 11.077827-117.218168 53.031172-35.327448 36.671427-52.045187 87.218637-49.687224 150.217652 1.453977 38.733395 14.593772 71.608881 38.015406 95.044515 23.765629 23.813628 56.875111 36.595428 95.734504 37.063421 4.623928 0.032 9.265855 0.032 13.875783 0.032h3.093952c22.453649 0 45.655287 0 62.811019 6.467899 10.985828 4.123936 24.517617 12.249809 31.829502 38.625396 7.561882 27.437571 9.749848 55.499133 11.999813 85.216669 2.123967 27.967563 4.375932 56.905111 11.49982 85.654661 14.123779 56.93911 43.217325 71.156888 65.122983 73.064859 3.123951 0.249996 6.249902 0.375994 9.311854 0.375994 48.751238 0 81.124732-32.813487 107.594319-63.595007 6.311901-7.345885 13.217793-14.439774 20.531679-21.939657 16.125748-16.531742 32.813487-33.623475 44.689302-55.343135 13.717786-24.999609 18.623709-51.249199 23.437634-76.592803 4.49993-24.061624 8.811862-46.783269 20.249683-66.656959 1.561976-2.687958 3.375947-5.74991 5.375916-9.217856 58.095092-99.610444 72.062874-136.483867 59.783066-157.873533-5.531914-9.59385-15.655755-14.781769-27.469571-13.875783-5.623912 0.437993-11.187825 0.65599-16.499742 0.65599-34.999453 0-63.937001-9.62585-79.404759-26.407588-4.375932-4.749926-6.467899-8.843862-7.405884-11.563819 0.531992-0.032 1.123982-0.045999 1.811971-0.045999 10.24984 0 25.999594 4.203934 41.217356 8.28187 18.405712 4.937923 37.467415 10.031843 54.217153 10.031843 27.283574 0 45.033296-14.171779 48.939235-38.98539 3.875939-7.03189 22.061655-24.171622 34.375463-25.781598l25.499602-3.327948-7.967876-24.431618zM164.297473 611.750441c-16.033749-23.313636-34.391463-45.875283-50.875205-68.828924-15.35976-21.405666-75.310823-118.750145-103.094389-133.717911A514.623959 514.623959 0 0 0 0.00004 512c0 124.624053 44.533304 238.812269 118.532148 327.622881 0.061999 0.061999 0.171997 0.093999 0.327995 0.093998 4.48393 0 46.189278-30.999516 49.969219-34.217465 16.421743-13.937782 30.171529-30.967516 36.327432-51.937188 14.359776-48.811237-13.859783-102.562397-40.859361-141.811785" fill="#A0D468"/>
</svg> ';
	return '<div class="comment-useragent">' . $icon . esc_html( $region ) . '</div>';
}

/* ============================================================
 * 3. 钩子：argon_comment_ua_icon（Argon 主题专用）
 * ============================================================ */
function easy_location_argon_comment_ua( $c ) {
	$ip = get_comment_author_ip();
	if ( empty( $ip ) ) {
		return $c;
	}
	$extra = easy_location_build_html( $ip );
	if ( $extra === '' ) {
		return $c;
	}
	return $c . $extra;
}
add_filter( 'argon_comment_ua_icon', 'easy_location_argon_comment_ua' );

/* ============================================================
 * 4. 后台：设置链接、设置页、提示
 * ============================================================ */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'easy_location_add_action_links' );
add_action( 'admin_menu', 'easy_location_add_admin_page' );
add_action( 'admin_notices', 'easy_location_admin_notices' );
add_action( 'admin_init', 'easy_location_privacy_policy' );

function easy_location_settings_url() {
	return add_query_arg( array( 'page' => 'easy-location-settings' ), admin_url( 'options-general.php' ) );
}

function easy_location_add_action_links( $links ) {
	$links[] = sprintf( '<a href="%s">设置</a>', esc_url( easy_location_settings_url() ) );
	return $links;
}

function easy_location_add_admin_page() {
	add_submenu_page(
		'options-general.php',
		'Easy Location 设置',
		'Easy Location',
		'manage_options',
		'easy-location-settings',
		'easy_location_render_settings_page'
	);
}

/**
 * 检查运行环境，返回问题列表
 */
function easy_location_check_env() {
	$issues = array();
	if ( 'argon' !== strtolower( get_template() ) ) {
		$issues[] = '当前主题不是 Argon，Easy Location 不会生效（插件依赖 Argon 的 argon_comment_ua_icon 钩子）。';
	}
	if ( ! easy_location_file_ok( EASY_LOCATION_XDB_DIR . 'Searcher.class.php' ) ) {
		$issues[] = '缺少 ip2region 类库：ip2region/xdb/Searcher.class.php。';
	}
	if ( ! easy_location_file_ok( EASY_LOCATION_DB_V4 ) ) {
		$issues[] = '缺少 IPv4 数据库：ip2region_v4.xdb。';
	}
	if ( ! easy_location_file_ok( EASY_LOCATION_DB_V6 ) ) {
		$issues[] = '缺少 IPv6 数据库：ip2region_v6.xdb（IPv6 访客将不显示归属地）。';
	}
	return $issues;
}

function easy_location_admin_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'settings_page_easy-location-settings' ), true ) ) {
		return;
	}
	$issues = easy_location_check_env();
	if ( empty( $issues ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>Easy Location：</strong></p><ul style="list-style:disc;padding-left:20px;">';
	foreach ( $issues as $issue ) {
		echo '<li>' . esc_html( $issue ) . '</li>';
	}
	echo '</ul></div>';
}

/**
 * 在「设置 → 隐私」的政策指南中加入说明
 */
function easy_location_privacy_policy() {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}
	$content = '<p>本站评论区会根据评论者的 IP 地址，使用本地离线数据库（ip2region）解析并公开显示其大致归属地（国家/省份/城市），不会公开完整 IP 地址，也不会将 IP 发送给任何第三方服务。</p>';
	wp_add_privacy_policy_content( 'Easy Location', wp_kses_post( $content ) );
}

function easy_location_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( '没有权限。' );
	}

	$v4_info = easy_location_get_file_info( EASY_LOCATION_DB_V4 );
	$v6_info = easy_location_get_file_info( EASY_LOCATION_DB_V6 );
	$version = easy_location_get_stored_version();

	$test_ip     = isset( $_GET['el_test_ip'] ) ? sanitize_text_field( wp_unslash( $_GET['el_test_ip'] ) ) : '';
	$test_result = $test_ip !== '' ? easy_location_get_region( $test_ip ) : null;
	?>
	<div class="wrap">
		<h1>Easy Location 设置</h1>
		<p>插件版本：<?php echo esc_html( EASY_LOCATION_VERSION ); ?></p>

		<h2>IP 库信息</h2>
		<table class="widefat striped" style="max-width:720px;">
			<thead>
			<tr><th>数据库</th><th>版本</th><th>大小</th><th>修改时间</th></tr>
			</thead>
			<tbody>
			<?php foreach ( array( 'IPv4' => $v4_info, 'IPv6' => $v6_info ) as $label => $info ) : ?>
			<tr>
				<td><?php echo esc_html( $label ); ?></td>
				<td><?php echo ( $info && $version ) ? esc_html( $version ) : '—'; ?></td>
				<td><?php echo $info ? esc_html( size_format( $info['size'] ) ) : '<span style="color:#d63638;">未找到</span>'; ?></td>
				<td><?php echo $info ? esc_html( $info['modified'] ) : '—'; ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2>查询测试</h2>
		<form method="get">
			<input type="hidden" name="page" value="easy-location-settings">
			<input type="text" name="el_test_ip" class="regular-text" placeholder="输入 IPv4 或 IPv6 地址" value="<?php echo esc_attr( $test_ip ); ?>">
			<?php submit_button( '查询', 'secondary', '', false ); ?>
		</form>
		<?php if ( null !== $test_result ) : ?>
			<p>结果：<strong><?php echo $test_result !== '' ? esc_html( $test_result ) : '未查到（请检查 IP 格式或库文件）'; ?></strong></p>
		<?php endif; ?>

		<h2>手动更新 IP 库</h2>
		<ol>
			<li>前往 <a href="https://github.com/lionsoul2014/ip2region" target="_blank" rel="noopener">ip2region 项目</a> 下载最新的 <code>ip2region_v4.xdb</code> 和 <code>ip2region_v6.xdb</code>。</li>
			<li>覆盖上传到插件目录：<code><?php echo esc_html( trailingslashit( EASY_LOCATION_DIR ) ); ?></code></li>
			<li>修改同目录下 <code>version.txt</code> 中的版本号（如 v3.x.x）并保存。</li>
		</ol>
		<p class="description">提示：通过后台「上传插件」覆盖升级插件时，插件目录会被替换，请提前备份自己更新过的 xdb 文件。</p>
	</div>
	<?php
}

/* ============================================================
 * 5. 文件信息与版本
 * ============================================================ */
function easy_location_get_file_info( $file ) {
	if ( ! easy_location_file_ok( $file ) ) {
		return null;
	}
	return array(
		'size'     => filesize( $file ),
		'modified' => date_i18n( 'Y-m-d H:i:s', filemtime( $file ) ),
	);
}

function easy_location_get_stored_version() {
	if ( ! file_exists( EASY_LOCATION_VERSION_FILE ) ) {
		return null;
	}
	$version = trim( (string) file_get_contents( EASY_LOCATION_VERSION_FILE ) );
	return $version === '' ? null : $version;
}

/**
 * 文件存在且非空（占位空文件视为缺失）
 */
function easy_location_file_ok( $file ) {
	return file_exists( $file ) && filesize( $file ) > 0;
}
