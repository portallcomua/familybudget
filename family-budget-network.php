<?php
/**
 * Plugin Name: Family Budget Network PRO
 * Description: Керування бюджетом, комунальними платежами, інтернетом та мобільним
 * Version: 4.1.2
 * Author: Your Name
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action('init', 'fbm_hide_admin_bar');
function fbm_hide_admin_bar() {
    if (!current_user_can('administrator') && !is_admin()) {
        add_filter('show_admin_bar', '__return_false');
    }
}

// GitHub Updater
add_filter('pre_set_site_transient_update_plugins', 'fbm_check_for_plugin_update');
function fbm_check_for_plugin_update($checked_data) {
    global $wp_version;
    if (empty($checked_data->checked)) {
        return $checked_data;
    }

    $plugin_slug = plugin_basename(__FILE__);
    $plugin_data = get_plugin_data(__FILE__);
    $current_version = $plugin_data['Version'];

    $response = wp_remote_get('https://api.github.com/repos/portallcomua/familybudget/releases/latest');
    if (is_wp_error($response)) {
        return $checked_data;
    }

    $release_data = json_decode(wp_remote_retrieve_body($response), true);
    if (version_compare($current_version, $release_data['tag_name'], '<')) {
        $plugin_info = new stdClass();
        $plugin_info->slug = $plugin_slug;
        $plugin_info->new_version = $release_data['tag_name'];
        $plugin_info->url = $release_data['html_url'];

        // Check for the specific release asset
        $asset_url = '';
        if (!empty($release_data['assets'])) {
            foreach ($release_data['assets'] as $asset) {
                if ($asset['name'] === 'family-budget-network.zip') {
                    $asset_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        // Use the asset URL if available, otherwise fallback to zipball
        $plugin_info->package = $asset_url ? $asset_url : $release_data['zipball_url'];
        $checked_data->response[$plugin_slug] = $plugin_info;
    }

    return $checked_data;
}
// 1. СТВОРЕННЯ ТАБЛИЦЬ
// ============================================
function fbm_activate() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$tables = array(
		'fbm_transactions' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_transactions (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			type varchar(20) NOT NULL,
			amount decimal(10,2) NOT NULL,
			category varchar(100) NOT NULL,
			description text DEFAULT '',
			payment_method varchar(50) DEFAULT 'manual',
			status varchar(20) DEFAULT 'completed',
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id)
		) $charset_collate;",
		'fbm_categories' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_categories (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL DEFAULT 0,
			name varchar(100) NOT NULL,
			type varchar(20) NOT NULL,
			is_default tinyint(1) DEFAULT 0,
			PRIMARY KEY (id)
		) $charset_collate;",
		'fbm_utilities' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_utilities (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			service_name varchar(100) NOT NULL,
			provider varchar(100) DEFAULT '',
			personal_account varchar(50) NOT NULL,
			meter_previous varchar(50) DEFAULT '',
			meter_current varchar(50) DEFAULT '',
			tariff decimal(10,2) DEFAULT 0,
			amount decimal(10,2) NOT NULL,
			status varchar(20) DEFAULT 'pending',
			paid_at datetime DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id),
			KEY user_id (user_id)
		) $charset_collate;",
		'fbm_accounts' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_accounts (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			service_name varchar(100) NOT NULL,
			provider varchar(100) DEFAULT '',
			personal_account varchar(50) NOT NULL,
			tariff decimal(10,2) DEFAULT 0,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_service (user_id, service_name, provider)
		) $charset_collate;",
		'fbm_phones' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_phones (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			phone varchar(20) NOT NULL,
			operator varchar(50) NOT NULL,
			label varchar(100) DEFAULT '',
			tariff decimal(10,2) DEFAULT 0,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_phone (user_id, phone)
		) $charset_collate;",
		'fbm_telegram' => "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_telegram (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			user_id bigint(20) NOT NULL,
			chat_id varchar(50) NOT NULL,
			username varchar(100) DEFAULT '',
			is_active tinyint(1) DEFAULT 1,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY (id)
		) $charset_collate;"
	);

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	foreach ( $tables as $sql ) {
		dbDelta( $sql );
	}

	fbm_add_categories();
}
register_activation_hook( __FILE__, 'fbm_activate' );

// ============================================
// 2. КАТЕГОРІЇ
// ============================================
function fbm_add_categories() {
	global $wpdb;
	$table = $wpdb->prefix . 'fbm_categories';

	$cats = array(
		array( 'Зарплата', 'income' ),
		array( 'Заробіток', 'income' ),
		array( 'Подарунок', 'income' ),
		array( 'Кешбек', 'income' ),
		array( 'Інше (дохід)', 'income' ),
		array( 'Продукти', 'expense' ),
		array( 'Транспорт', 'expense' ),
		array( 'Одяг', 'expense' ),
		array( 'Розваги', 'expense' ),
		array( 'Здоров\'я', 'expense' ),
		array( 'Освіта', 'expense' ),
		array( 'Електроенергія', 'expense' ),
		array( 'Водопостачання', 'expense' ),
		array( 'Газ', 'expense' ),
		array( 'Інтернет', 'expense' ),
		array( 'Мобільний', 'expense' ),
		array( 'Інше (витрата)', 'expense' )
	);

	foreach ( $cats as $cat ) {
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE user_id = 0 AND name = %s",
			$cat[0]
		) );
		if ( ! $exists ) {
			$wpdb->insert( $table, array(
				'user_id' => 0,
				'name' => $cat[0],
				'type' => $cat[1],
				'is_default' => 1
			) );
		}
	}
}

// ============================================
// 3. ВИЗНАЧЕННЯ ОПЕРАТОРА ЗА НОМЕРОМ
// ============================================
function fbm_detect_operator( $phone ) {
	$phone = preg_replace('/[^0-9]/', '', $phone);
	$prefix = substr( $phone, 0, 3 );

	$operators = array(
		'Київстар' => array('067', '068', '096', '097', '098'),
		'Vodafone' => array('050', '066', '095', '099'),
		'Lifecell' => array('063', '073', '093')
	);

	foreach ( $operators as $name => $prefixes ) {
		if ( in_array( $prefix, $prefixes ) ) {
			return $name;
		}
	}
	return '';
}

// ============================================
// 4. TELEGRAM
// ============================================
function fbm_send_telegram( $user_id, $message ) {
	global $wpdb;
	$table = $wpdb->prefix . 'fbm_telegram';
	$bot_token = get_option( 'fbm_telegram_bot' );

	if ( empty( $bot_token ) ) {
		return false;
	}

	$chats = $wpdb->get_results( $wpdb->prepare(
		"SELECT chat_id FROM $table WHERE user_id = %d AND is_active = 1",
		$user_id
	) );

	if ( empty( $chats ) ) {
		return false;
	}

	foreach ( $chats as $chat ) {
		$url = "https://api.telegram.org/bot{$bot_token}/sendMessage";
		wp_remote_post( $url, array(
			'body' => array(
				'chat_id' => $chat->chat_id,
				'text' => $message,
				'parse_mode' => 'HTML'
			)
		) );
	}
	return true;
}

// ============================================
// 5. ГЕНЕРАЦІЯ ПОСИЛАННЯ НА iPAY.UA
// ============================================
function fbm_generate_ipay_link( $service, $provider, $personal_account, $amount ) {
	$service_codes = array(
		'Електроенергія' => 'electricity',
		'Водопостачання' => 'water',
		'Газ'            => 'gas',
		'Інтернет'       => 'internet',
		'Мобільний'      => 'mobile'
	);

	$service_code = isset( $service_codes[ $service ] ) ? $service_codes[ $service ] : 'other';

	$account = $personal_account;
	if ( $service === 'Мобільний' ) {
		$account = preg_replace('/[^0-9]/', '', $personal_account);
		if ( strpos( $account, '0' ) === 0 ) {
			$account = '38' . $account;
		}
		if ( strpos( $account, '+38' ) === 0 ) {
			$account = substr( $account, 1 );
		}
		if ( strpos( $account, '380' ) !== 0 && strlen( $account ) === 10 ) {
			$account = '38' . $account;
		}
	}

	$url = 'https://www.ipay.ua/pay/' . $service_code . '/?account=' . urlencode( $account ) . '&amount=' . number_format( $amount, 2, '.', '' );

	if ( ! empty( $provider ) ) {
		$url .= '&provider=' . urlencode( $provider );
	}

	return $url;
}

// ============================================
// 6. ЕКСПОРТ CSV
// ============================================
function fbm_export_csv() {
	global $wpdb;
	$user_id = get_current_user_id();
	$table = $wpdb->prefix . 'fbm_transactions';

	$data = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC",
		$user_id
	), ARRAY_A );

	if ( empty( $data ) ) {
		wp_die( 'Немає даних для експорту' );
	}

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="budget-' . date( 'Y-m-d' ) . '.csv"' );

	$output = fopen( 'php://output', 'w' );
	fputcsv( $output, array( 'ID', 'Тип', 'Сума', 'Категорія', 'Опис', 'Статус', 'Дата' ) );

	foreach ( $data as $row ) {
		fputcsv( $output, array(
			$row['id'],
			$row['type'] == 'income' ? 'Дохід' : 'Витрата',
			$row['amount'],
			$row['category'],
			$row['description'],
			$row['status'],
			$row['created_at']
		) );
	}
	fclose( $output );
	exit;
}

// ============================================
// 7. ЕКСПОРТ PDF
// ============================================
function fbm_export_pdf() {
	global $wpdb;
	$user_id = get_current_user_id();
	$table = $wpdb->prefix . 'fbm_transactions';

	$data = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC",
		$user_id
	) );

	if ( empty( $data ) ) {
		wp_die( 'Немає даних для експорту' );
	}

	$user = get_userdata( $user_id );
	$income = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(amount) FROM $table WHERE user_id = %d AND type = 'income' AND status = 'completed'",
		$user_id
	) ) ?: 0;

	$expense = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(amount) FROM $table WHERE user_id = %d AND type = 'expense' AND status = 'completed'",
		$user_id
	) ) ?: 0;

	?>
	<!DOCTYPE html>
	<html>
	<head>
		<meta charset="UTF-8">
		<title>Бюджет - <?php echo date( 'd.m.Y' ); ?></title>
		<style>
			body { font-family: Arial, sans-serif; padding: 20px; }
			h1 { color: #2271b1; text-align: center; }
			.summary { display: flex; justify-content: space-around; margin: 20px 0; padding: 15px; background: #f5f5f5; border-radius: 8px; }
			.summary div { text-align: center; }
			.summary .label { font-size: 12px; color: #666; }
			.summary .num { font-size: 20px; font-weight: bold; }
			table { width: 100%; border-collapse: collapse; margin-top: 20px; }
			th { background: #2271b1; color: #fff; padding: 10px; text-align: left; }
			td { padding: 8px; border-bottom: 1px solid #ddd; }
			.income { color: #4caf50; }
			.expense { color: #f44336; }
			.pending { color: #ff9800; }
			.print-btn { display: block; width: 200px; margin: 20px auto; padding: 10px; background: #2271b1; color: #fff; text-align: center; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
			@media print { .print-btn { display: none; } }
		</style>
	</head>
	<body>
		<h1>📊 Звіт по бюджету</h1>
		<p style="text-align:center;color:#666;">Користувач: <?php echo esc_html( $user->display_name ); ?> | Дата: <?php echo date( 'd.m.Y H:i' ); ?></p>

		<div class="summary">
			<div>
				<div class="label">💵 Баланс</div>
				<div class="num" style="color:<?php echo ($income - $expense) >= 0 ? '#4caf50' : '#f44336'; ?>;">
					<?php echo number_format( $income - $expense, 2 ); ?> грн
				</div>
			</div>
			<div>
				<div class="label">📈 Доходи</div>
				<div class="num" style="color:#4caf50;">+<?php echo number_format( $income, 2 ); ?> грн</div>
			</div>
			<div>
				<div class="label">📉 Витрати</div>
				<div class="num" style="color:#f44336;">-<?php echo number_format( $expense, 2 ); ?> грн</div>
			</div>
		</div>

		<table>
			<thead>
				<tr>
					<th>Дата</th>
					<th>Тип</th>
					<th>Сума</th>
					<th>Категорія</th>
					<th>Статус</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data as $t ) : ?>
				<tr>
					<td><?php echo date( 'd.m.Y H:i', strtotime( $t->created_at ) ); ?></td>
					<td><?php echo $t->type == 'income' ? '💰 Дохід' : '🛒 Витрата'; ?></td>
					<td class="<?php echo $t->type == 'income' ? 'income' : 'expense'; ?>">
						<?php echo $t->type == 'income' ? '+' : '-'; ?> <?php echo number_format( $t->amount, 2 ); ?> грн
					</td>
					<td><?php echo esc_html( $t->category ); ?></td>
					<td><?php echo $t->status == 'completed' ? '✅ Сплачено' : '⏳ Очікує'; ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<button class="print-btn" onclick="window.print()">🖨️ Роздрукувати</button>
	</body>
	</html>
	<?php
	exit;
}

// ============================================
// 8. АДМІН-ПАНЕЛЬ
// ============================================
add_action( 'admin_menu', 'fbm_menu' );
function fbm_menu() {
	add_menu_page(
		'Мій Бюджет',
		'Мій Бюджет',
		'manage_options',
		'family-budget',
		'fbm_admin_page',
		'dashicons-money-alt',
		6
	);
	add_submenu_page(
		'family-budget',
		'Налаштування',
		'⚙️ Налаштування',
		'manage_options',
		'fbm-settings',
		'fbm_admin_settings_page'
	);
	add_submenu_page(
		'family-budget',
		'Google Login',
		'🔑 Google Login',
		'manage_options',
		'fbm-google',
		'fbm_google_settings_page'
	);
}

function fbm_admin_page() {
	global $wpdb;
	$user_id = get_current_user_id();

	$income = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(amount) FROM {$wpdb->prefix}fbm_transactions WHERE user_id = %d AND type = 'income' AND status = 'completed'",
		$user_id
	) ) ?: 0;

	$expense = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(amount) FROM {$wpdb->prefix}fbm_transactions WHERE user_id = %d AND type = 'expense' AND status = 'completed'",
		$user_id
	) ) ?: 0;

	$transactions = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fbm_transactions WHERE user_id = %d ORDER BY created_at DESC LIMIT 30",
		$user_id
	) );
	?>
	<div class="wrap">
		<h1>📊 Сімейний бюджет</h1>

		<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:15px;margin:20px 0;">
			<div style="background:#fff;padding:15px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);text-align:center;">
				<h3>💵 Баланс</h3>
				<div style="font-size:24px;font-weight:bold;color:<?php echo ($income - $expense) >= 0 ? '#4caf50' : '#f44336'; ?>;">
					<?php echo number_format( $income - $expense, 2 ); ?> грн
				</div>
			</div>
			<div style="background:#fff;padding:15px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);text-align:center;">
				<h3>📈 Доходи</h3>
				<div style="font-size:24px;font-weight:bold;color:#4caf50;">+<?php echo number_format( $income, 2 ); ?> грн</div>
			</div>
			<div style="background:#fff;padding:15px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);text-align:center;">
				<h3>📉 Витрати</h3>
				<div style="font-size:24px;font-weight:bold;color:#f44336;">-<?php echo number_format( $expense, 2 ); ?> грн</div>
			</div>
		</div>

		<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
			<h3>📋 Історія операцій</h3>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>Дата</th><th>Сума</th><th>Категорія</th><th>Статус</th></tr></thead>
				<tbody>
					<?php if ( $transactions ) : foreach ( $transactions as $t ) : ?>
					<tr>
						<td><?php echo date( 'd.m.Y H:i', strtotime( $t->created_at ) ); ?></td>
						<td style="color:<?php echo $t->type == 'income' ? '#4caf50' : '#f44336'; ?>;font-weight:bold;">
							<?php echo $t->type == 'income' ? '+' : '-'; ?> <?php echo number_format( $t->amount, 2 ); ?> грн
						</td>
						<td><?php echo esc_html( $t->category ); ?></td>
						<td><?php echo $t->status == 'completed' ? '✅ Сплачено' : '⏳ Очікує'; ?></td>
					</tr>
					<?php endforeach; else : ?>
					<tr><td colspan="4" style="text-align:center;">Немає даних</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
}

function fbm_admin_settings_page() {
	if ( isset( $_POST['fbm_save_admin_settings'] ) && check_admin_referer( 'fbm_admin_settings' ) ) {
		update_option( 'fbm_telegram_bot', sanitize_text_field( $_POST['telegram_bot'] ) );
		echo '<div class="updated"><p>✅ Налаштування збережено!</p></div>';
	}

	$bot_token = get_option( 'fbm_telegram_bot' );
	$bot_info = '';
	$bot_username_admin = '';
	if ( $bot_token ) {
		$response = wp_remote_get( "https://api.telegram.org/bot{$bot_token}/getMe" );
		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! empty( $data['ok'] ) ) {
				$bot_username_admin = $data['result']['username'];
				$bot_info = '🤖 Бот: <strong>@' . esc_html( $bot_username_admin ) . '</strong>';
			}
		}
	}
	?>
	<div class="wrap">
		<h1>⚙️ Налаштування адміністратора</h1>

		<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
			<h3>🤖 Telegram Bot</h3>
			<p style="color:#666;font-size:13px;">Введіть токен бота для Telegram сповіщень.</p>

			<?php if ( $bot_info ) : ?>
				<div style="background:#f0f8ff;padding:10px;border-radius:4px;margin-bottom:15px;">
					<?php echo $bot_info; ?>
					<a href="https://t.me/<?php echo esc_attr( $bot_username_admin ); ?>" target="_blank" class="button button-secondary" style="margin-left:10px;">📲 Відкрити бота</a>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'fbm_admin_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="telegram_bot">Bot Token</label></th>
						<td>
							<input type="password" id="telegram_bot" name="telegram_bot"
								   value="<?php echo esc_attr( $bot_token ); ?>" class="regular-text" placeholder="1234567890:ABCdefGHIjklMNOpqrsTUVwxyz">
							<p class="description">
								Отримайте токен у <a href="https://t.me/botfather" target="_blank">@BotFather</a>
							</p>
						</td>
					</tr>
				</table>
				<p><input type="submit" name="fbm_save_admin_settings" class="button button-primary" value="💾 Зберегти"></p>
			</form>
		</div>
	</div>
	<?php
}

// ============================================
// 9. GOOGLE LOGIN
// ============================================
add_shortcode( 'fbm_google_login', 'fbm_google_login_shortcode' );
function fbm_google_login_shortcode() {
	$client_id = get_option( 'fbm_google_client_id' );
	if ( ! $client_id ) {
		return '<p style="color:#f44336;">⚠️ Google Login не налаштовано. <a href="' . admin_url( 'admin.php?page=fbm-google' ) . '">Налаштувати</a></p>';
	}
	$redirect = home_url( '/google-login-callback/' );
	$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
		'client_id' => $client_id,
		'redirect_uri' => $redirect,
		'response_type' => 'code',
		'scope' => 'email profile'
	) );

	ob_start();
	?>
	<div style="text-align:center;padding:40px 20px;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);max-width:400px;margin:40px auto;">
		<h2>👋 Ласкаво просимо</h2>
		<p style="color:#666;">Увійдіть через Google</p>
		<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#4285f4;color:#fff;padding:12px 30px;border-radius:4px;text-decoration:none;font-weight:500;font-size:16px;border:none;cursor:pointer;">
			🔑 Увійти через Google
		</a>
		<p style="margin-top:15px;font-size:13px;color:#999;">
			або <a href="<?php echo wp_login_url(); ?>">звичайний вхід</a>
		</p>
	</div>
	<?php
	return ob_get_clean();
}

add_action( 'init', 'fbm_google_callback_handler' );
function fbm_google_callback_handler() {
	if ( ! isset( $_GET['code'] ) || strpos( $_SERVER['REQUEST_URI'], '/google-login-callback/' ) === false ) {
		return;
	}
	$client_id = get_option( 'fbm_google_client_id' );
	$client_secret = get_option( 'fbm_google_client_secret' );
	$redirect = home_url( '/google-login-callback/' );
	$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
		'body' => array(
			'code' => $_GET['code'],
			'client_id' => $client_id,
			'client_secret' => $client_secret,
			'redirect_uri' => $redirect,
			'grant_type' => 'authorization_code'
		)
	) );
	if ( is_wp_error( $response ) ) {
		wp_die( 'Помилка підключення до Google' );
	}
	$token = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( isset( $token['access_token'] ) ) {
		$userinfo = wp_remote_get( 'https://www.googleapis.com/oauth2/v2/userinfo', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $token['access_token'] )
		) );
		$user = json_decode( wp_remote_retrieve_body( $userinfo ), true );
		if ( ! empty( $user['email'] ) ) {
			$user_id = email_exists( $user['email'] );
			if ( ! $user_id ) {
				$user_id = wp_create_user( $user['email'], wp_generate_password(), $user['email'] );
				update_user_meta( $user_id, 'first_name', $user['given_name'] ?? '' );
				update_user_meta( $user_id, 'last_name', $user['family_name'] ?? '' );
			}
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id );
			$wp_user_obj = get_userdata( $user_id );
			do_action( 'wp_login', $wp_user_obj->user_login, $wp_user_obj );
			wp_redirect( home_url( '/my-budget/' ) );
			exit;
		}
	}
	wp_die( 'Помилка авторизації через Google' );
}

function fbm_google_settings_page() {
	if ( isset( $_POST['fbm_save_google'] ) && check_admin_referer( 'fbm_google_settings' ) ) {
		update_option( 'fbm_google_client_id', sanitize_text_field( $_POST['client_id'] ) );
		update_option( 'fbm_google_client_secret', sanitize_text_field( $_POST['client_secret'] ) );
		echo '<div class="updated"><p>✅ Налаштування збережено!</p></div>';
	}
	?>
	<div class="wrap">
		<h1>🔑 Налаштування Google Login</h1>
		<div style="background:#fff3cd;padding:15px;border-left:4px solid #ffc107;margin-bottom:20px;">
			<p><strong>📋 Як отримати Client ID та Secret:</strong></p>
			<ol>
				<li>Перейдіть на <a href="https://console.cloud.google.com" target="_blank">Google Cloud Console</a></li>
				<li>Створіть новий проєкт</li>
				<li>APIs & Services → Credentials → Create OAuth client ID</li>
				<li>Redirect URI: <code><?php echo home_url( '/google-login-callback/' ); ?></code></li>
			</ol>
		</div>
		<form method="post">
			<?php wp_nonce_field( 'fbm_google_settings' ); ?>
			<table class="form-table">
				<tr><th>Client ID</th><td><input type="text" name="client_id" value="<?php echo esc_attr( get_option( 'fbm_google_client_id' ) ); ?>" class="regular-text"></td></tr>
				<tr><th>Client Secret</th><td><input type="password" name="client_secret" value="<?php echo esc_attr( get_option( 'fbm_google_client_secret' ) ); ?>" class="regular-text"></td></tr>
			</table>
			<p><input type="submit" name="fbm_save_google" class="button button-primary" value="💾 Зберегти"></p>
		</form>
	</div>
	<?php
}

// ============================================
// 10. ФРОНТЕНД - НАЛАШТУВАННЯ КОРИСТУВАЧА
// ============================================
add_shortcode( 'fbm_settings', 'fbm_frontend_settings' );
function fbm_frontend_settings() {
	if ( ! is_user_logged_in() ) {
		return '<p>🔒 <a href="' . wp_login_url() . '">Увійдіть</a></p>';
	}

	global $wpdb;
	$user_id = get_current_user_id();

	// Збереження особових рахунків
	if ( isset( $_POST['fbm_save_accounts'] ) && check_admin_referer( 'fbm_settings' ) ) {
		$services = array( 'Електроенергія', 'Водопостачання', 'Газ', 'Інтернет' );
		foreach ( $services as $s ) {
			$account = sanitize_text_field( $_POST['account_' . $s] );
			$tariff = floatval( $_POST['tariff_' . $s] );
			if ( ! empty( $account ) ) {
				$wpdb->replace( $wpdb->prefix . 'fbm_accounts', array(
					'user_id' => $user_id,
					'service_name' => $s,
					'provider' => $s === 'Інтернет' ? sanitize_text_field( $_POST['provider_' . $s] ) : '',
					'personal_account' => $account,
					'tariff' => $tariff
				) );
			}
		}
		echo '<div style="background:#c6f6d5;padding:10px;border-radius:4px;margin:10px 0;">✅ Налаштування збережено!</div>';
	}

	// Збереження телефону
	if ( isset( $_POST['fbm_save_phone'] ) && check_admin_referer( 'fbm_settings' ) ) {
		$phone_raw = sanitize_text_field( $_POST['phone_number'] );
		$phone_clean = preg_replace('/[^0-9]/', '', $phone_raw);

		if ( strlen( $phone_clean ) !== 10 ) {
			echo '<div style="background:#ffebee;padding:10px;border-radius:4px;margin:10px 0;border-left:4px solid #f44336;">
				❌ Помилка: номер телефону має містити рівно 10 цифр (наприклад, 0985555555)
			</div>';
		} else {
			$phone = '+38' . $phone_clean;
			$operator = sanitize_text_field( $_POST['phone_operator'] );
			$label = sanitize_text_field( $_POST['phone_label'] );
			$tariff = floatval( $_POST['phone_tariff'] );

			$wpdb->replace( $wpdb->prefix . 'fbm_phones', array(
				'user_id' => $user_id,
				'phone' => $phone,
				'operator' => $operator,
				'label' => $label,
				'tariff' => $tariff
			) );
			echo '<div style="background:#c6f6d5;padding:10px;border-radius:4px;margin:10px 0;">✅ Номер додано!</div>';
		}
	}

	if ( isset( $_GET['delete_phone'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'fbm_delete_phone_' . intval( $_GET['delete_phone'] ) ) ) {
		$wpdb->delete( $wpdb->prefix . 'fbm_phones', array(
			'id' => intval( $_GET['delete_phone'] ),
			'user_id' => $user_id
		) );
		echo '<div style="background:#c6f6d5;padding:10px;border-radius:4px;margin:10px 0;">✅ Номер видалено!</div>';
	}

	// Telegram для користувача
	if ( isset( $_POST['fbm_save_telegram'] ) && check_admin_referer( 'fbm_settings' ) ) {
		$chat_id = sanitize_text_field( $_POST['telegram_chat_id'] );
		$username = sanitize_text_field( $_POST['telegram_username'] );
		if ( ! empty( $chat_id ) ) {
			$wpdb->replace( $wpdb->prefix . 'fbm_telegram', array(
				'user_id' => $user_id,
				'chat_id' => $chat_id,
				'username' => $username,
				'is_active' => 1
			) );
			echo '<div style="background:#c6f6d5;padding:10px;border-radius:4px;margin:10px 0;">✅ Telegram підключено!</div>';
		}
	}

	$saved_accounts_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fbm_accounts WHERE user_id = %d",
		$user_id
	) );
	$saved_accounts = array();
	foreach ( $saved_accounts_raw as $acc ) {
		$saved_accounts[ $acc->service_name ] = $acc;
	}

	$saved_phones = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fbm_phones WHERE user_id = %d ORDER BY id",
		$user_id
	) );

	$telegram = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fbm_telegram WHERE user_id = %d",
		$user_id
	) );

	$bot_token = get_option( 'fbm_telegram_bot' );
	$bot_username = '';
	if ( $bot_token ) {
		$response = wp_remote_get( "https://api.telegram.org/bot{$bot_token}/getMe" );
		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( $data['ok'] ) {
				$bot_username = $data['result']['username'];
			}
		}
	}
	?>
	<style>
		.fbm-wrap { max-width:1000px; margin:20px auto; padding:0 15px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,sans-serif; }
		.fbm-wrap h2 { font-size:22px; margin-bottom:15px; }
		.fbm-nav { display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap; }
		.fbm-nav a { padding:8px 16px; background:#f1f1f1; border-radius:4px; text-decoration:none; color:#333; }
		.fbm-nav a.active { background:#2271b1; color:#fff; }
		.fbm-card { background:#fff; padding:20px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1); margin-bottom:20px; }
		.fbm-card h3 { margin:0 0 15px 0; font-size:16px; border-bottom:1px solid #eee; padding-bottom:10px; }
		.fbm-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
		.fbm-wrap input, .fbm-wrap select, .fbm-wrap textarea { width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:14px; }
		.fbm-wrap .btn { display:inline-block; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-size:14px; text-decoration:none; }
		.fbm-wrap .btn-primary { background:#2271b1; color:#fff; }
		.fbm-wrap .btn-success { background:#46b450; color:#fff; }
		.fbm-wrap .btn-danger { background:#f44336; color:#fff; }
		.fbm-wrap .btn-secondary { background:#f1f1f1; color:#333; }
		.fbm-phone-item { display:flex; gap:10px; align-items:center; padding:8px; background:#f9f9f9; border-radius:4px; margin-bottom:5px; flex-wrap:wrap; }
		.fbm-phone-item .label { font-weight:bold; min-width:80px; }
		.fbm-phone-item .phone { color:#333; }
		.fbm-phone-item .operator { color:#666; font-size:12px; }
		.fbm-hidden { display:none; }
		.fbm-error-message { color:#f44336; font-size:12px; margin-top:5px; display:none; }
		.fbm-field-error { border-color:#f44336 !important; background-color:#ffebee !important; }
		.fbm-field-success { border-color:#4caf50 !important; background-color:#e8f5e9 !important; }
		@media (max-width:768px) {
			.fbm-grid { grid-template-columns:1fr; }
		}
	</style>

	<div class="fbm-wrap">
		<div class="fbm-nav">
			<a href="/my-budget/">📊 Бюджет</a>
			<a href="/my-settings/" class="active">⚙️ Налаштування</a>
			<a href="<?php echo wp_logout_url( home_url() ); ?>">🚪 Вийти</a>
		</div>

		<h2>⚙️ Мої налаштування</h2>

		<div class="fbm-grid">
			<div class="fbm-card">
				<h3>📋 Особові рахунки та тарифи</h3>
				<form method="post">
					<?php wp_nonce_field( 'fbm_settings' ); ?>

					<?php $services = array( 'Електроенергія', 'Водопостачання', 'Газ', 'Інтернет' ); ?>
					<?php foreach ( $services as $s ) : ?>
						<div style="border-bottom:1px solid #eee;padding:10px 0;">
							<strong><?php echo $s; ?></strong>
							<?php if ( $s === 'Інтернет' ) : ?>
								<select name="provider_<?php echo $s; ?>" style="margin-top:5px;width:100%;">
									<option value="Київстар" <?php selected( isset( $saved_accounts[$s] ) ? $saved_accounts[$s]->provider : '', 'Київстар' ); ?>>Київстар</option>
									<option value="Укртелеком" <?php selected( isset( $saved_accounts[$s] ) ? $saved_accounts[$s]->provider : '', 'Укртелеком' ); ?>>Укртелеком</option>
									<option value="Vodafone" <?php selected( isset( $saved_accounts[$s] ) ? $saved_accounts[$s]->provider : '', 'Vodafone' ); ?>>Vodafone</option>
									<option value="Lifecell" <?php selected( isset( $saved_accounts[$s] ) ? $saved_accounts[$s]->provider : '', 'Lifecell' ); ?>>Lifecell</option>
									<option value="БКМ" <?php selected( isset( $saved_accounts[$s] ) ? $saved_accounts[$s]->provider : '', 'БКМ' ); ?>>БКМ</option>
									<option value="Triolan" <?php selected( isset( $saved_accounts[$s] ) ? $saved_accounts[$s]->provider : '', 'Triolan' ); ?>>Triolan</option>
									<option value="Vega" <?php selected( isset( $saved_accounts[$s] ) ? $saved_accounts[$s]->provider : '', 'Vega' ); ?>>Vega</option>
									<option value="Фрегат" <?php selected( isset( $saved_accounts[$s] ) ? $saved_accounts[$s]->provider : '', 'Фрегат' ); ?>>Фрегат</option>
								</select>
							<?php endif; ?>
							<input type="text" name="account_<?php echo $s; ?>" placeholder="Особовий рахунок"
								value="<?php echo isset( $saved_accounts[$s] ) ? esc_attr( $saved_accounts[$s]->personal_account ) : ''; ?>" style="margin:5px 0;width:100%;">
							<input type="number" step="0.01" name="tariff_<?php echo $s; ?>" placeholder="Тариф (за замовчуванням)"
								value="<?php echo isset( $saved_accounts[$s] ) && $saved_accounts[$s]->tariff ? esc_attr( $saved_accounts[$s]->tariff ) : ''; ?>" style="margin:5px 0;width:100%;">
						</div>
					<?php endforeach; ?>

					<p><input type="submit" name="fbm_save_accounts" class="btn btn-primary" value="💾 Зберегти"></p>
				</form>
			</div>

			<div>
				<div class="fbm-card">
					<h3>📱 Номери телефонів</h3>
					<?php if ( $saved_phones ) : foreach ( $saved_phones as $p ) : ?>
						<div class="fbm-phone-item">
							<span class="label"><?php echo esc_html( $p->label ?: 'Телефон' ); ?></span>
							<span class="phone"><?php echo esc_html( $p->phone ); ?></span>
							<span class="operator">(<?php echo esc_html( $p->operator ); ?>)</span>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'delete_phone', $p->id ), 'fbm_delete_phone_' . $p->id ) ); ?>" onclick="return confirm('Видалити номер?')" style="color:#f44336;text-decoration:none;margin-left:auto;">×</a>
						</div>
					<?php endforeach; else : ?>
						<p style="color:#999;font-size:13px;">Немає збережених номерів</p>
					<?php endif; ?>

					<hr>
					<form method="post">
						<?php wp_nonce_field( 'fbm_settings' ); ?>
						<input type="text" name="phone_number" placeholder="Введіть 10 цифр (наприклад, 0985555555)" required style="margin-bottom:5px;width:100%;">
						<div style="font-size:12px;color:#999;margin-bottom:5px;">📌 Автоматично додасться +38</div>
						<select name="phone_operator" style="margin-bottom:5px;width:100%;">
							<option value="Київстар">Київстар</option>
							<option value="Vodafone">Vodafone</option>
							<option value="Lifecell">Lifecell</option>
						</select>
						<input type="text" name="phone_label" placeholder="Підпис (наприклад, Мій, Дружина)" style="margin-bottom:5px;width:100%;">
						<input type="number" step="0.01" name="phone_tariff" placeholder="Тариф (сума за замовчуванням)" style="margin-bottom:5px;width:100%;">
						<input type="submit" name="fbm_save_phone" class="btn btn-secondary" value="➕ Додати номер">
					</form>
				</div>

				<div class="fbm-card">
					<h3>🤖 Telegram сповіщення</h3>

					<?php if ( $bot_username ) : ?>
						<div style="background:#f0f8ff;padding:10px;border-radius:4px;margin-bottom:15px;">
							🤖 Бот: <strong>@<?php echo $bot_username; ?></strong>
							<a href="https://t.me/<?php echo $bot_username; ?>" target="_blank" class="btn btn-secondary" style="padding:4px 12px;font-size:12px;margin-left:10px;">📲 Відкрити бота</a>
						</div>
					<?php else : ?>
						<div style="background:#fff3cd;padding:10px;border-radius:4px;margin-bottom:15px;">
							⚠️ Telegram бот ще не налаштований адміністратором
						</div>
					<?php endif; ?>

					<form method="post">
						<?php wp_nonce_field( 'fbm_settings' ); ?>
						<input type="text" name="telegram_chat_id" placeholder="Ваш Chat ID"
							value="<?php echo $telegram ? esc_attr( $telegram->chat_id ) : ''; ?>" style="margin-bottom:5px;width:100%;">
						<input type="text" name="telegram_username" placeholder="Ваш @username (необов'язково)"
							value="<?php echo $telegram ? esc_attr( $telegram->username ) : ''; ?>" style="margin-bottom:5px;width:100%;">
						<p style="font-size:12px;color:#999;">
							💡 Як дізнатися Chat ID:
							<a href="https://t.me/userinfobot" target="_blank">@userinfobot</a> → Написати "start"
						</p>
						<input type="submit" name="fbm_save_telegram" class="btn btn-success" value="🔗 Підключити Telegram">
					</form>
				</div>
			</div>
		</div>
	</div>
	<?php
}

// ============================================
// 11. ФРОНТЕНД - КАБІНЕТ
// ============================================
add_shortcode( 'fbm_dashboard', 'fbm_frontend' );
function fbm_frontend() {
	if ( ! is_user_logged_in() ) {
		return '<div style="text-align:center;padding:40px;max-width:400px;margin:40px auto;background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);">
			<h2>🔒 Вхід</h2>
			<p><a href="' . wp_login_url() . '" class="button button-primary">Увійти</a></p>
			<p style="font-size:13px;color:#999;"><a href="' . wp_registration_url() . '">Зареєструватися</a></p>
		</div>';
	}

	global $wpdb;
	$user_id = get_current_user_id();
	$user = get_userdata( $user_id );

	if ( isset( $_POST['fbm_clear_history'] ) && check_admin_referer( 'fbm_clear_history' ) ) {
		$wpdb->delete( $wpdb->prefix . 'fbm_transactions', array( 'user_id' => $user_id ) );
		$wpdb->delete( $wpdb->prefix . 'fbm_utilities', array( 'user_id' => $user_id ) );
		echo '<div style="background:#c6f6d5;padding:15px;border-radius:8px;margin:10px 0;">✅ Історію очищено!</div>';
	}

	$transactions = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fbm_transactions WHERE user_id = %d ORDER BY created_at DESC LIMIT 20",
		$user_id
	) );

	$utilities = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fbm_utilities WHERE user_id = %d ORDER BY created_at DESC LIMIT 10",
		$user_id
	) );

	$income = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(amount) FROM {$wpdb->prefix}fbm_transactions WHERE user_id = %d AND type = 'income' AND status = 'completed'",
		$user_id
	) ) ?: 0;

	$expense = $wpdb->get_var( $wpdb->prepare(
		"SELECT SUM(amount) FROM {$wpdb->prefix}fbm_transactions WHERE user_id = %d AND type = 'expense' AND status = 'completed'",
		$user_id
	) ) ?: 0;

	$saved_accounts_raw = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fbm_accounts WHERE user_id = %d",
		$user_id
	) );
	$saved_accounts = array();
	foreach ( $saved_accounts_raw as $acc ) {
		$saved_accounts[ $acc->service_name ] = $acc;
	}

	$saved_phones = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}fbm_phones WHERE user_id = %d ORDER BY id",
		$user_id
	) );

	$last_meters = array();
	$services = array( 'Електроенергія', 'Водопостачання', 'Газ' );
	foreach ( $services as $s ) {
		$last = $wpdb->get_row( $wpdb->prepare(
			"SELECT meter_current FROM {$wpdb->prefix}fbm_utilities WHERE user_id = %d AND service_name = %s ORDER BY created_at DESC LIMIT 1",
			$user_id, $s
		) );
		if ( $last ) {
			$last_meters[ $s ] = $last;
		}
	}
	?>
	<style>
		.fbm-wrap { max-width:1000px; margin:20px auto; padding:0 15px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Oxygen,Ubuntu,Cantarell,sans-serif; }
		.fbm-wrap h2 { font-size:22px; margin-bottom:15px; }
		.fbm-stats { display:grid; grid-template-columns:repeat(3,1fr); gap:15px; margin:20px 0; }
		.fbm-stat { background:#fff; padding:15px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1); text-align:center; }
		.fbm-stat .label { font-size:12px; color:#666; }
		.fbm-stat .num { font-size:22px; font-weight:bold; }
		.fbm-card { background:#fff; padding:20px; border-radius:8px; box-shadow:0 1px 3px rgba(0,0,0,0.1); margin-bottom:20px; }
		.fbm-card h3 { margin:0 0 15px 0; font-size:16px; border-bottom:1px solid #eee; padding-bottom:10px; }
		.fbm-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
		.fbm-wrap input, .fbm-wrap select, .fbm-wrap textarea { width:100%; padding:10px; border:1px solid #ddd; border-radius:4px; box-sizing:border-box; font-size:14px; }
		.fbm-wrap .btn { display:inline-block; padding:10px 20px; border:none; border-radius:4px; cursor:pointer; font-size:14px; text-decoration:none; }
		.fbm-wrap .btn-primary { background:#2271b1; color:#fff; }
		.fbm-wrap .btn-success { background:#46b450; color:#fff; }
		.fbm-wrap .btn-danger { background:#f44336; color:#fff; }
		.fbm-wrap .btn-secondary { background:#f1f1f1; color:#333; }
		.fbm-table { width:100%; border-collapse:collapse; font-size:14px; }
		.fbm-table th { background:#f7fafc; padding:10px; text-align:left; border-bottom:2px solid #e2e8f0; }
		.fbm-table td { padding:10px; border-bottom:1px solid #edf2f7; }
		.fbm-table tr:hover { background:#f7fafc; }
		.fbm-calc { background:#f0f8ff; padding:15px; border-radius:8px; margin:15px 0; text-align:center; }
		.fbm-calc .diff { font-size:18px; font-weight:bold; color:#2271b1; }
		.fbm-calc .amount { font-size:28px; font-weight:bold; color:#d63638; }
		.fbm-nav { display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap; }
		.fbm-nav a { padding:8px 16px; background:#f1f1f1; border-radius:4px; text-decoration:none; color:#333; }
		.fbm-nav a.active { background:#2271b1; color:#fff; }
		.fbm-info-box { background:#f5f5f5; padding:8px 12px; border-radius:4px; margin-bottom:10px; font-size:13px; color:#333; }
		.fbm-info-box strong { color:#2271b1; }
		@media (max-width:768px) {
			.fbm-stats { grid-template-columns:1fr; }
			.fbm-grid { grid-template-columns:1fr; }
			.fbm-table { font-size:12px; }
			.fbm-table th, .fbm-table td { padding:6px; }
		}
	</style>

	<div class="fbm-wrap">
		<div class="fbm-nav">
			<a href="/my-budget/" class="active">📊 Бюджет</a>
			<a href="/my-settings/">⚙️ Налаштування</a>
			<a href="<?php echo wp_logout_url( home_url() ); ?>">🚪 Вийти</a>
		</div>

		<h2>👋 Привіт, <?php echo esc_html( $user->display_name ); ?></h2>

		<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:15px;">
			<a href="?fbm_export_csv=1" class="btn btn-primary">⬇️ CSV</a>
			<a href="?fbm_export_pdf=1" class="btn btn-secondary">🖨️ PDF</a>
			<form method="post" style="display:inline;" onsubmit="return confirm('⚠️ Ви впевнені, що хочете видалити ВСЮ історію? Це НЕ МОЖНА буде відновити!');">
				<?php wp_nonce_field( 'fbm_clear_history' ); ?>
				<input type="submit" name="fbm_clear_history" class="btn btn-danger" value="🗑️ Очистити історію">
			</form>
		</div>

		<div class="fbm-stats">
			<div class="fbm-stat">
				<div class="label">💵 Баланс</div>
				<div class="num" style="color:<?php echo ($income - $expense) >= 0 ? '#4caf50' : '#f44336'; ?>;">
					<?php echo number_format( $income - $expense, 2 ); ?> грн
				</div>
			</div>
			<div class="fbm-stat">
				<div class="label">📈 Доходи</div>
				<div class="num" style="color:#4caf50;">+<?php echo number_format( $income, 2 ); ?> грн</div>
			</div>
			<div class="fbm-stat">
				<div class="label">📉 Витрати</div>
				<div class="num" style="color:#f44336;">-<?php echo number_format( $expense, 2 ); ?> грн</div>
			</div>
		</div>

		<div class="fbm-grid">
			<!-- ЕЛЕКТРОЕНЕРГІЯ -->
			<div class="fbm-card">
				<h3>⚡ Електроенергія</h3>
				<form method="post" id="fbm_fe_utility_elec">
					<?php wp_nonce_field( 'fbm_fe_utility' ); ?>
					<input type="hidden" name="service_name" value="Електроенергія">

					<div class="fbm-info-box">
						<strong>Особовий рахунок:</strong>
						<?php if ( isset( $saved_accounts['Електроенергія'] ) && ! empty( $saved_accounts['Електроенергія']->personal_account ) ) : ?>
							<?php echo esc_html( $saved_accounts['Електроенергія']->personal_account ); ?>
							<br><strong>Тариф:</strong> <?php echo number_format( $saved_accounts['Електроенергія']->tariff, 2 ); ?> грн
						<?php else : ?>
							<span style="color:#f44336;">не вказано → <a href="/my-settings/">налаштувати</a></span>
						<?php endif; ?>
					</div>

					<input type="hidden" id="fbm_fe_tariff_elec" value="<?php echo isset( $saved_accounts['Електроенергія'] ) ? esc_attr( $saved_accounts['Електроенергія']->tariff ) : '0'; ?>">
					<input type="text" name="meter_current" placeholder="Поточний показник" required style="margin-bottom:10px;" id="fbm_fe_meter_elec">

					<div class="fbm-calc">
						<div>📉 Різниця: <span class="diff" id="fbm_fe_diff_elec">0.00</span></div>
						<div>💵 Сума: <span class="amount" id="fbm_fe_amount_elec">0.00 грн</span></div>
					</div>

					<input type="number" step="0.01" name="amount" placeholder="Сума (можна змінити)" required style="margin-bottom:10px;" id="fbm_fe_amount_input_elec">

					<input type="submit" name="fbm_fe_pay_utility" class="btn btn-success" value="💳 Сформувати рахунок" style="width:100%;">
				</form>
			</div>

			<!-- ВОДОПОСТАЧАННЯ -->
			<div class="fbm-card">
				<h3>💧 Водопостачання</h3>
				<form method="post" id="fbm_fe_utility_water">
					<?php wp_nonce_field( 'fbm_fe_utility' ); ?>
					<input type="hidden" name="service_name" value="Водопостачання">

					<div class="fbm-info-box">
						<strong>Особовий рахунок:</strong>
						<?php if ( isset( $saved_accounts['Водопостачання'] ) && ! empty( $saved_accounts['Водопостачання']->personal_account ) ) : ?>
							<?php echo esc_html( $saved_accounts['Водопостачання']->personal_account ); ?>
							<br><strong>Тариф:</strong> <?php echo number_format( $saved_accounts['Водопостачання']->tariff, 2 ); ?> грн
						<?php else : ?>
							<span style="color:#f44336;">не вказано → <a href="/my-settings/">налаштувати</a></span>
						<?php endif; ?>
					</div>

					<input type="hidden" id="fbm_fe_tariff_water" value="<?php echo isset( $saved_accounts['Водопостачання'] ) ? esc_attr( $saved_accounts['Водопостачання']->tariff ) : '0'; ?>">
					<input type="text" name="meter_current" placeholder="Поточний показник" required style="margin-bottom:10px;" id="fbm_fe_meter_water">

					<div class="fbm-calc">
						<div>📉 Різниця: <span class="diff" id="fbm_fe_diff_water">0.00</span></div>
						<div>💵 Сума: <span class="amount" id="fbm_fe_amount_water">0.00 грн</span></div>
					</div>

					<input type="number" step="0.01" name="amount" placeholder="Сума (можна змінити)" required style="margin-bottom:10px;" id="fbm_fe_amount_input_water">

					<input type="submit" name="fbm_fe_pay_utility" class="btn btn-success" value="💳 Сформувати рахунок" style="width:100%;">
				</form>
			</div>

			<!-- ГАЗ -->
			<div class="fbm-card">
				<h3>🔥 Газ</h3>
				<form method="post" id="fbm_fe_utility_gas">
					<?php wp_nonce_field( 'fbm_fe_utility' ); ?>
					<input type="hidden" name="service_name" value="Газ">

					<div class="fbm-info-box">
						<strong>Особовий рахунок:</strong>
						<?php if ( isset( $saved_accounts['Газ'] ) && ! empty( $saved_accounts['Газ']->personal_account ) ) : ?>
							<?php echo esc_html( $saved_accounts['Газ']->personal_account ); ?>
							<br><strong>Тариф:</strong> <?php echo number_format( $saved_accounts['Газ']->tariff, 2 ); ?> грн
						<?php else : ?>
							<span style="color:#f44336;">не вказано → <a href="/my-settings/">налаштувати</a></span>
						<?php endif; ?>
					</div>

					<input type="hidden" id="fbm_fe_tariff_gas" value="<?php echo isset( $saved_accounts['Газ'] ) ? esc_attr( $saved_accounts['Газ']->tariff ) : '0'; ?>">
					<input type="text" name="meter_current" placeholder="Поточний показник" required style="margin-bottom:10px;" id="fbm_fe_meter_gas">

					<div class="fbm-calc">
						<div>📉 Різниця: <span class="diff" id="fbm_fe_diff_gas">0.00</span></div>
						<div>💵 Сума: <span class="amount" id="fbm_fe_amount_gas">0.00 грн</span></div>
					</div>

					<input type="number" step="0.01" name="amount" placeholder="Сума (можна змінити)" required style="margin-bottom:10px;" id="fbm_fe_amount_input_gas">

					<input type="submit" name="fbm_fe_pay_utility" class="btn btn-success" value="💳 Сформувати рахунок" style="width:100%;">
				</form>
			</div>

			<!-- ІНТЕРНЕТ -->
			<div class="fbm-card">
				<h3>🌐 Інтернет</h3>
				<form method="post" id="fbm_fe_internet">
					<?php wp_nonce_field( 'fbm_fe_internet' ); ?>

					<div class="fbm-info-box">
						<?php if ( isset( $saved_accounts['Інтернет'] ) && ! empty( $saved_accounts['Інтернет']->personal_account ) ) : ?>
							<strong>Провайдер:</strong> <?php echo esc_html( $saved_accounts['Інтернет']->provider ?: 'не вказано' ); ?><br>
							<strong>Особовий рахунок:</strong> <?php echo esc_html( $saved_accounts['Інтернет']->personal_account ); ?><br>
							<strong>Тариф:</strong> <?php echo number_format( $saved_accounts['Інтернет']->tariff, 2 ); ?> грн
						<?php else : ?>
							<span style="color:#f44336;">не вказано → <a href="/my-settings/">налаштувати</a></span>
						<?php endif; ?>
					</div>

					<input type="number" step="0.01" name="internet_amount" placeholder="Сума до сплати" required style="margin-bottom:10px;"
						value="<?php echo isset( $saved_accounts['Інтернет'] ) && $saved_accounts['Інтернет']->tariff ? esc_attr( $saved_accounts['Інтернет']->tariff ) : ''; ?>">

					<input type="submit" name="fbm_fe_pay_internet" class="btn btn-success" value="💳 Сформувати рахунок" style="width:100%;">
				</form>
			</div>

			<!-- МОБІЛЬНИЙ -->
			<div class="fbm-card">
				<h3>📱 Мобільний</h3>
				<form method="post" id="fbm_fe_mobile">
					<?php wp_nonce_field( 'fbm_fe_mobile' ); ?>

					<div style="margin-bottom:10px;">
						<label>Оберіть номер:</label>
						<select name="mobile_phone" id="fbm_fe_mobile_phone" style="margin-top:5px;width:100%;padding:8px;">
							<?php if ( $saved_phones ) : foreach ( $saved_phones as $p ) : ?>
								<option value="<?php echo esc_attr( $p->phone . '|' . $p->operator ); ?>">
									<?php echo esc_html( $p->label ? $p->label . ' (' . $p->phone . ')' : $p->phone ); ?> - <?php echo esc_html( $p->operator ); ?>
									<?php if ( $p->tariff > 0 ) : ?>
										(<?php echo number_format( $p->tariff, 2 ); ?> грн)
									<?php endif; ?>
								</option>
							<?php endforeach; endif; ?>
							<option value="new">➕ Новий номер</option>
						</select>
					</div>

					<div id="fbm_fe_mobile_new" class="fbm-hidden">
						<input type="text" name="mobile_phone_new" placeholder="Введіть номер (наприклад, 0985555555)" style="margin-bottom:5px;width:100%;padding:8px;" id="fbm_fe_phone_input">
						<div style="font-size:12px;color:#999;margin-bottom:5px;">📌 Формат: 0XX XXX-XX-XX (автоматично додасться 380)</div>
						<div class="fbm-error-message" id="fbm_fe_phone_error" style="color:#f44336;font-size:12px;display:none;">❌ Невірний формат. Введіть 10 цифр (наприклад, 0985555555)</div>
						<select name="mobile_operator" style="margin-bottom:5px;width:100%;padding:8px;" id="fbm_fe_operator_select">
							<option value="">Автовизначення (за номером)</option>
							<option value="Київстар">Київстар</option>
							<option value="Vodafone">Vodafone</option>
							<option value="Lifecell">Lifecell</option>
						</select>
						<input type="text" name="mobile_label" placeholder="Підпис (наприклад, Дружина)" style="margin-bottom:5px;width:100%;padding:8px;">
						<input type="number" step="0.01" name="mobile_tariff" placeholder="Сума за замовчуванням" style="margin-bottom:5px;width:100%;padding:8px;">
					</div>

					<input type="number" step="0.01" name="mobile_amount" placeholder="Сума до сплати" required style="margin-bottom:10px;width:100%;padding:8px;">

					<input type="submit" name="fbm_fe_pay_mobile" class="btn btn-success" value="💳 Сформувати рахунок" style="width:100%;">
				</form>
			</div>
		</div>

		<!-- Історія операцій -->
		<div class="fbm-card">
			<h3>📋 Історія операцій</h3>
			<?php if ( $transactions ) : ?>
			<table class="fbm-table">
				<thead><tr><th>Дата</th><th>Сума</th><th>Категорія</th><th>Статус</th></tr></thead>
				<tbody>
					<?php foreach ( $transactions as $t ) : ?>
					<tr>
						<td><?php echo date( 'd.m.Y', strtotime( $t->created_at ) ); ?></td>
						<td style="color:<?php echo $t->type == 'income' ? '#4caf50' : '#f44336'; ?>;font-weight:bold;">
							<?php echo $t->type == 'income' ? '+' : '-'; ?> <?php echo number_format( $t->amount, 2 ); ?> грн
						</td>
						<td><?php echo esc_html( $t->category ); ?></td>
						<td><?php echo $t->status == 'completed' ? '✅ Сплачено' : '⏳ Очікує'; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
			<p style="text-align:center;color:#a0aec0;">📭 Немає даних</p>
			<?php endif; ?>
		</div>

		<!-- Історія платежів -->
		<div class="fbm-card">
			<h3>📋 Історія платежів</h3>
			<?php if ( $utilities ) : ?>
			<table class="fbm-table">
				<thead><tr><th>Дата</th><th>Послуга</th><th>Сума</th><th>Статус</th><th>Дія</th></tr></thead>
				<tbody>
					<?php foreach ( $utilities as $u ) : ?>
					<tr>
						<td><?php echo date( 'd.m.Y', strtotime( $u->created_at ) ); ?></td>
						<td><?php echo esc_html( $u->service_name ) . ( $u->provider ? ' (' . esc_html( $u->provider ) . ')' : '' ); ?></td>
						<td style="font-weight:bold;"><?php echo number_format( $u->amount, 2 ); ?> грн</td>
						<td style="color:<?php echo $u->status == 'paid' ? 'green' : 'orange'; ?>;">
							<?php echo $u->status == 'paid' ? '✅ Сплачено' : '⏳ Очікує'; ?>
						</td>
						<td>
							<?php if ( $u->status == 'pending' ) : ?>
								<?php
								$link = fbm_generate_ipay_link( $u->service_name, $u->provider, $u->personal_account, $u->amount );
								?>
								<a href="<?php echo esc_url( $link ); ?>" target="_blank" class="btn btn-success" style="padding:4px 8px;font-size:12px;">💳 Сплатити</a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php else : ?>
			<p style="text-align:center;color:#a0aec0;">📭 Немає платежів</p>
			<?php endif; ?>
		</div>

		<!-- PWA -->
		<div style="text-align:center;padding:20px;background:#f0f8ff;border-radius:8px;margin-top:20px;">
			<p><strong>📱 Встановіть додаток на телефон</strong></p>
			<button id="fbm_install_app" class="btn btn-primary" style="display:none;font-size:16px;padding:12px 30px;">
				📲 Встановити на головний екран
			</button>
			<p style="color:#666;font-size:13px;" id="fbm_install_hint">
				Натисніть "Поділитися" → "На екран "Додому" у Safari, або "Додати до головного екрана" в Chrome
			</p>
		</div>

		<script>
		document.addEventListener('DOMContentLoaded', function() {
			const lastMeters = <?php echo json_encode( $last_meters ); ?>;
			const savedAccounts = <?php echo json_encode( $saved_accounts ); ?>;

			['elec', 'water', 'gas'].forEach(function(field) {
				const serviceMap = {
					'elec': 'Електроенергія',
					'water': 'Водопостачання',
					'gas': 'Газ'
				};

				const meter = document.getElementById('fbm_fe_meter_' + field);
				const tariff = document.getElementById('fbm_fe_tariff_' + field);
				const amountInput = document.getElementById('fbm_fe_amount_input_' + field);
				const diffSpan = document.getElementById('fbm_fe_diff_' + field);
				const amountSpan = document.getElementById('fbm_fe_amount_' + field);

				if (!meter || !tariff) return;

				function calculate() {
					const s = serviceMap[field];
					const prev = lastMeters[s] ? parseFloat(lastMeters[s].meter_current) : 0;
					const current = parseFloat(meter.value) || 0;
					const t = parseFloat(tariff.value) || 0;

					let diff = 0;
					let sum = 0;

					if (current > 0) {
						diff = current - prev;
						if (diff < 0) diff = 0;
						sum = diff * t;
					}

					if (diffSpan) diffSpan.textContent = diff.toFixed(2);
					if (amountSpan) {
						amountSpan.textContent = sum.toFixed(2) + ' грн';
						amountSpan.style.color = sum > 0 ? '#d63638' : '#999';
					}
					if (amountInput) amountInput.value = sum.toFixed(2);
				}

				meter.addEventListener('input', calculate);
				tariff.addEventListener('input', calculate);
				calculate();
			});

			const phoneSelect = document.getElementById('fbm_fe_mobile_phone');
			const newPhoneBlock = document.getElementById('fbm_fe_mobile_new');

			if (phoneSelect) {
				phoneSelect.addEventListener('change', function() {
					if (this.value === 'new') {
						newPhoneBlock.classList.remove('fbm-hidden');
					} else {
						newPhoneBlock.classList.add('fbm-hidden');
					}
				});
			}

			const phoneInput = document.getElementById('fbm_fe_phone_input');
			const operatorSelect = document.getElementById('fbm_fe_operator_select');
			const phoneError = document.getElementById('fbm_fe_phone_error');

			if (phoneInput && operatorSelect) {
				const detectOperator = function(phone) {
					const clean = phone.replace(/[^0-9]/g, '');
					if (clean.length < 3) return '';
					const prefix = clean.substring(0, 3);
					const operators = {
						'Київстар': ['067', '068', '096', '097', '098'],
						'Vodafone': ['050', '066', '095', '099'],
						'Lifecell': ['063', '073', '093']
					};
					for (const [name, prefixes] of Object.entries(operators)) {
						if (prefixes.includes(prefix)) return name;
					}
					return '';
				};

				phoneInput.addEventListener('input', function() {
					let value = this.value.replace(/[^0-9]/g, '');

					if (value.length > 0) {
						if (value.length <= 3) {
							value = value;
						} else if (value.length <= 6) {
							value = value.substring(0, 3) + ' ' + value.substring(3);
						} else if (value.length <= 8) {
							value = value.substring(0, 3) + ' ' + value.substring(3, 6) + '-' + value.substring(6);
						} else {
							value = value.substring(0, 3) + ' ' + value.substring(3, 6) + '-' + value.substring(6, 8) + '-' + value.substring(8, 10);
						}
						this.value = value;
					}

					const cleanValue = this.value.replace(/[^0-9]/g, '');
					const isValid = cleanValue.length === 10;

					if (cleanValue.length > 0 && !isValid) {
						this.classList.add('fbm-field-error');
						this.classList.remove('fbm-field-success');
						phoneError.style.display = 'block';
					} else if (isValid) {
						this.classList.remove('fbm-field-error');
						this.classList.add('fbm-field-success');
						phoneError.style.display = 'none';

						const detected = detectOperator(cleanValue);
						if (detected) {
							operatorSelect.value = detected;
						}
					} else {
						this.classList.remove('fbm-field-error');
						this.classList.remove('fbm-field-success');
						phoneError.style.display = 'none';
					}
				});

				phoneInput.addEventListener('blur', function() {
					const cleanValue = this.value.replace(/[^0-9]/g, '');
					if (cleanValue.length > 0 && cleanValue.length !== 10) {
						this.classList.add('fbm-field-error');
						this.classList.remove('fbm-field-success');
						phoneError.style.display = 'block';
					}
				});
			}

			let deferredPrompt;
			window.addEventListener('beforeinstallprompt', (e) => {
				e.preventDefault();
				deferredPrompt = e;
				const installBtn = document.getElementById('fbm_install_app');
				const hint = document.getElementById('fbm_install_hint');
				if (installBtn) installBtn.style.display = 'inline-block';
				if (hint) hint.style.display = 'none';
			});

			document.getElementById('fbm_install_app')?.addEventListener('click', function() {
				if (deferredPrompt) {
					deferredPrompt.prompt();
					deferredPrompt.userChoice.then((result) => {
						if (result.outcome === 'accepted') {
							console.log('Додаток встановлено');
						}
						deferredPrompt = null;
					});
				}
			});
		});
		</script>
	</div>
	<?php
}

// ============================================
// 12. ОБРОБКА ФОРМ НА ФРОНТЕНДІ
// ============================================
add_action( 'template_redirect', 'fbm_frontend_actions' );
function fbm_frontend_actions() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	global $wpdb;
	$user_id = get_current_user_id();

	// Оплата комуналки
	if ( isset( $_POST['fbm_fe_pay_utility'] ) && check_admin_referer( 'fbm_fe_utility' ) ) {
		$service = sanitize_text_field( $_POST['service_name'] );
		$meter_current = sanitize_text_field( $_POST['meter_current'] );
		$amount = floatval( $_POST['amount'] );

		$saved = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fbm_accounts WHERE user_id = %d AND service_name = %s",
			$user_id, $service
		) );

		if ( ! $saved || empty( $saved->personal_account ) ) {
			wp_redirect( home_url( '/my-budget/?error=no_account' ) );
			exit;
		}

		$tariff = $saved->tariff ?: 0;
		$account = $saved->personal_account;

		$last = $wpdb->get_row( $wpdb->prepare(
			"SELECT meter_current FROM {$wpdb->prefix}fbm_utilities WHERE user_id = %d AND service_name = %s ORDER BY created_at DESC LIMIT 1",
			$user_id, $service
		) );
		$meter_previous = $last ? $last->meter_current : '0';

		$wpdb->insert( $wpdb->prefix . 'fbm_utilities', array(
			'user_id' => $user_id,
			'service_name' => $service,
			'personal_account' => $account,
			'meter_previous' => $meter_previous,
			'meter_current' => $meter_current,
			'tariff' => $tariff,
			'amount' => $amount,
			'status' => 'pending'
		) );

		$util_id = $wpdb->insert_id;
		$link = fbm_generate_ipay_link( $service, '', $account, $amount );

		wp_redirect( add_query_arg( array(
			'pay_utility' => $util_id,
			'ipay_link' => urlencode( $link )
		), home_url( '/my-budget/' ) ) );
		exit;
	}

	// Підтвердження оплати
	if ( isset( $_GET['confirm_payment'] ) ) {
		$util_id = intval( $_GET['confirm_payment'] );
		$util = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fbm_utilities WHERE id = %d AND user_id = %d",
			$util_id, $user_id
		) );

		if ( $util && $util->status == 'pending' ) {
			$wpdb->update(
				$wpdb->prefix . 'fbm_utilities',
				array( 'status' => 'paid', 'paid_at' => current_time( 'mysql' ) ),
				array( 'id' => $util_id )
			);

			$wpdb->insert( $wpdb->prefix . 'fbm_transactions', array(
				'user_id' => $user_id,
				'type' => 'expense',
				'amount' => $util->amount,
				'category' => $util->service_name . ( $util->provider ? ' (' . $util->provider . ')' : '' ),
				'description' => 'Показники: ' . $util->meter_previous . ' → ' . $util->meter_current,
				'status' => 'completed'
			) );

			fbm_send_telegram( $user_id, '✅ Платіж сплачено: ' . $util->service_name . ' - ' . $util->amount . ' грн' );
		}

		wp_redirect( home_url( '/my-budget/?paid=1' ) );
		exit;
	}

	// Оплата інтернету
	if ( isset( $_POST['fbm_fe_pay_internet'] ) && check_admin_referer( 'fbm_fe_internet' ) ) {
		$saved = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}fbm_accounts WHERE user_id = %d AND service_name = 'Інтернет'",
			$user_id
		) );

		if ( ! $saved || empty( $saved->personal_account ) ) {
			wp_redirect( home_url( '/my-budget/?error=no_account' ) );
			exit;
		}

		$provider = $saved->provider;
		$account = $saved->personal_account;
		$amount = floatval( $_POST['internet_amount'] );

		$wpdb->insert( $wpdb->prefix . 'fbm_utilities', array(
			'user_id' => $user_id,
			'service_name' => 'Інтернет',
			'provider' => $provider,
			'personal_account' => $account,
			'amount' => $amount,
			'status' => 'pending'
		) );

		$util_id = $wpdb->insert_id;
		$link = fbm_generate_ipay_link( 'Інтернет', $provider, $account, $amount );

		wp_redirect( add_query_arg( array(
			'pay_utility' => $util_id,
			'ipay_link' => urlencode( $link )
		), home_url( '/my-budget/' ) ) );
		exit;
	}

	// Оплата мобільного
	if ( isset( $_POST['fbm_fe_pay_mobile'] ) && check_admin_referer( 'fbm_fe_mobile' ) ) {
		$phone_data = sanitize_text_field( $_POST['mobile_phone'] );
		$amount = floatval( $_POST['mobile_amount'] );

		if ( $phone_data === 'new' ) {
			$phone_raw = sanitize_text_field( $_POST['mobile_phone_new'] );
			$phone_clean = preg_replace('/[^0-9]/', '', $phone_raw);

			if ( strlen( $phone_clean ) !== 10 ) {
				wp_redirect( home_url( '/my-budget/?error=invalid_phone' ) );
				exit;
			}

			$operator = sanitize_text_field( $_POST['mobile_operator'] );
			if ( empty( $operator ) ) {
				$operator = fbm_detect_operator( $phone_clean );
				if ( empty( $operator ) ) {
					$operator = 'Київстар';
				}
			}

			$phone = '+38' . $phone_clean;
			$label = sanitize_text_field( $_POST['mobile_label'] );
			$tariff = floatval( $_POST['mobile_tariff'] );

			$wpdb->insert( $wpdb->prefix . 'fbm_phones', array(
				'user_id' => $user_id,
				'phone' => $phone,
				'operator' => $operator,
				'label' => $label,
				'tariff' => $tariff
			) );
		} else {
			list( $phone, $operator ) = explode( '|', $phone_data );
		}

		$wpdb->insert( $wpdb->prefix . 'fbm_utilities', array(
			'user_id' => $user_id,
			'service_name' => 'Мобільний',
			'provider' => $operator,
			'personal_account' => $phone,
			'amount' => $amount,
			'status' => 'pending'
		) );

		$util_id = $wpdb->insert_id;
		$link = fbm_generate_ipay_link( 'Мобільний', $operator, $phone, $amount );

		wp_redirect( add_query_arg( array(
			'pay_utility' => $util_id,
			'ipay_link' => urlencode( $link )
		), home_url( '/my-budget/' ) ) );
		exit;
	}

	// Додавання транзакції
	if ( isset( $_POST['fbm_fe_add'] ) && check_admin_referer( 'fbm_fe_add' ) ) {
		$wpdb->insert( $wpdb->prefix . 'fbm_transactions', array(
			'user_id' => $user_id,
			'type' => sanitize_text_field( $_POST['type'] ),
			'amount' => floatval( $_POST['amount'] ),
			'category' => sanitize_text_field( $_POST['category'] ),
			'description' => sanitize_textarea_field( $_POST['description'] ),
			'status' => 'completed'
		) );
		fbm_send_telegram( $user_id, '✅ Нова транзакція: ' . $_POST['category'] . ' - ' . $_POST['amount'] . ' грн' );
		wp_redirect( add_query_arg( 'added', '1', home_url( '/my-budget/' ) ) );
		exit;
	}

	// Експорт
	if ( isset( $_GET['fbm_export_csv'] ) ) {
		fbm_export_csv();
	}
	if ( isset( $_GET['fbm_export_pdf'] ) ) {
		fbm_export_pdf();
	}
}

// ============================================
// 13. СТВОРЕННЯ СТОРІНОК
// ============================================
register_activation_hook( __FILE__, 'fbm_create_pages' );
function fbm_create_pages() {
	$pages = array(
		'my-budget' => array( 'title' => 'Мій бюджет', 'content' => '[fbm_dashboard]', 'slug' => 'my-budget' ),
		'my-settings' => array( 'title' => 'Налаштування', 'content' => '[fbm_settings]', 'slug' => 'my-settings' ),
		'login' => array( 'title' => 'Вхід', 'content' => '[fbm_google_login]', 'slug' => 'login' )
	);
	foreach ( $pages as $page ) {
		if ( ! get_page_by_path( $page['slug'] ) ) {
			wp_insert_post( array(
				'post_title' => $page['title'],
				'post_content' => $page['content'],
				'post_name' => $page['slug'],
				'post_status' => 'publish',
				'post_type' => 'page'
			) );
		}
	}
}

// ============================================
// 14. ПЕРЕНАПРАВЛЕННЯ ПІСЛЯ ВХОДУ
// ============================================
add_filter( 'login_redirect', 'fbm_login_redirect', 10, 3 );
function fbm_login_redirect( $redirect_to, $request, $user ) {
	if ( isset( $user->ID ) && ! is_wp_error( $user ) ) {
		return home_url( '/my-budget/' );
	}
	return $redirect_to;
}

// ============================================
// 16. РЕЄСТРАЦІЯ - ШОРТКОД [fbm_register]
// ============================================
add_shortcode( 'fbm_register', 'fbm_register_shortcode' );
function fbm_register_shortcode() {
	if ( is_user_logged_in() ) {
		wp_redirect( home_url( '/my-budget/' ) );
		exit;
	}

	$error = '';
	$success = '';

	if ( isset( $_POST['fbm_do_register'] ) && check_admin_referer( 'fbm_register' ) ) {
		$username  = sanitize_user( $_POST['reg_username'] );
		$email     = sanitize_email( $_POST['reg_email'] );
		$password  = $_POST['reg_password'];
		$password2 = $_POST['reg_password2'];

		if ( empty( $username ) || empty( $email ) || empty( $password ) ) {
			$error = 'Заповніть усі поля.';
		} elseif ( ! is_email( $email ) ) {
			$error = 'Невірний формат email.';
		} elseif ( $password !== $password2 ) {
			$error = 'Паролі не співпадають.';
		} elseif ( strlen( $password ) < 6 ) {
			$error = 'Пароль має бути не менше 6 символів.';
		} elseif ( username_exists( $username ) ) {
			$error = 'Такий логін вже існує.';
		} elseif ( email_exists( $email ) ) {
			$error = 'Цей email вже зареєстровано.';
		} else {
			$user_id = wp_create_user( $username, $password, $email );
			if ( is_wp_error( $user_id ) ) {
				$error = $user_id->get_error_message();
			} else {
				update_user_meta( $user_id, 'first_name', sanitize_text_field( $_POST['reg_firstname'] ?? '' ) );
				update_user_meta( $user_id, 'last_name', sanitize_text_field( $_POST['reg_lastname'] ?? '' ) );
				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id );
				wp_redirect( home_url( '/my-budget/' ) );
				exit;
			}
		}
	}

	ob_start();
	?>
	<div style="max-width:420px;margin:40px auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.1);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
		<h2 style="text-align:center;margin-bottom:20px;">📝 Реєстрація</h2>

		<?php if ( $error ) : ?>
			<div style="background:#ffebee;color:#c62828;padding:10px;border-radius:6px;margin-bottom:15px;border-left:4px solid #f44336;">❌ <?php echo esc_html( $error ); ?></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'fbm_register' ); ?>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Ім'я</label>
				<input type="text" name="reg_firstname" value="<?php echo esc_attr( $_POST['reg_firstname'] ?? '' ); ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Ваше ім'я">
			</div>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Прізвище</label>
				<input type="text" name="reg_lastname" value="<?php echo esc_attr( $_POST['reg_lastname'] ?? '' ); ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Ваше прізвище">
			</div>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Логін *</label>
				<input type="text" name="reg_username" value="<?php echo esc_attr( $_POST['reg_username'] ?? '' ); ?>" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Введіть логін">
			</div>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Email *</label>
				<input type="email" name="reg_email" value="<?php echo esc_attr( $_POST['reg_email'] ?? '' ); ?>" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="your@email.com">
			</div>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Пароль *</label>
				<input type="password" name="reg_password" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Мін. 6 символів">
			</div>
			<div style="margin-bottom:20px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Повторіть пароль *</label>
				<input type="password" name="reg_password2" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Повторіть пароль">
			</div>
			<button type="submit" name="fbm_do_register" style="width:100%;padding:12px;background:#2271b1;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;">✅ Зареєструватися</button>
		</form>

		<?php
		$google_client_id = get_option( 'fbm_google_client_id' );
		if ( $google_client_id ) :
			$redirect = home_url( '/google-login-callback/' );
			$g_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
				'client_id'     => $google_client_id,
				'redirect_uri'  => $redirect,
				'response_type' => 'code',
				'scope'         => 'email profile',
			) );
		?>
		<div style="text-align:center;margin:15px 0;color:#999;font-size:13px;">— або —</div>
		<a href="<?php echo esc_url( $g_url ); ?>" style="display:block;text-align:center;background:#4285f4;color:#fff;padding:11px;border-radius:6px;text-decoration:none;font-size:14px;">🔑 Зареєструватися через Google</a>
		<?php endif; ?>

		<p style="text-align:center;margin-top:15px;font-size:13px;color:#999;">
			Вже є акаунт? <a href="<?php echo home_url( '/login/' ); ?>">Увійти</a>
		</p>
	</div>
	<?php
	return ob_get_clean();
}

// ============================================
// 17. АВТОРИЗАЦІЯ - ШОРТКОД [fbm_login]
// ============================================
add_shortcode( 'fbm_login', 'fbm_login_shortcode' );
function fbm_login_shortcode() {
	if ( is_user_logged_in() ) {
		wp_redirect( home_url( '/my-budget/' ) );
		exit;
	}

	$error = '';

	if ( isset( $_POST['fbm_do_login'] ) && check_admin_referer( 'fbm_login' ) ) {
		$credentials = array(
			'user_login'    => sanitize_text_field( $_POST['log_username'] ),
			'user_password' => $_POST['log_password'],
			'remember'      => isset( $_POST['log_remember'] ),
		);
		$user = wp_signon( $credentials, false );
		if ( is_wp_error( $user ) ) {
			$error = 'Невірний логін або пароль.';
		} else {
			wp_redirect( home_url( '/my-budget/' ) );
			exit;
		}
	}

	ob_start();
	?>
	<div style="max-width:420px;margin:40px auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.1);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
		<h2 style="text-align:center;margin-bottom:20px;">🔑 Вхід</h2>

		<?php if ( isset( $_GET['registered'] ) ) : ?>
			<div style="background:#e8f5e9;color:#1b5e20;padding:10px;border-radius:6px;margin-bottom:15px;">✅ Реєстрація успішна! Увійдіть.</div>
		<?php endif; ?>

		<?php if ( $error ) : ?>
			<div style="background:#ffebee;color:#c62828;padding:10px;border-radius:6px;margin-bottom:15px;border-left:4px solid #f44336;">❌ <?php echo esc_html( $error ); ?></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'fbm_login' ); ?>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Логін або Email</label>
				<input type="text" name="log_username" value="<?php echo esc_attr( $_POST['log_username'] ?? '' ); ?>" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Ваш логін або email" autocomplete="username">
			</div>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Пароль</label>
				<input type="password" name="log_password" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Ваш пароль" autocomplete="current-password">
			</div>
			<div style="margin-bottom:20px;">
				<label style="font-size:13px;color:#555;cursor:pointer;">
					<input type="checkbox" name="log_remember" style="width:auto;"> Запам'ятати мене
				</label>
			</div>
			<button type="submit" name="fbm_do_login" style="width:100%;padding:12px;background:#2271b1;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;">🚀 Увійти</button>
		</form>

		<?php
		$google_client_id = get_option( 'fbm_google_client_id' );
		if ( $google_client_id ) :
			$redirect = home_url( '/google-login-callback/' );
			$g_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query( array(
				'client_id'     => $google_client_id,
				'redirect_uri'  => $redirect,
				'response_type' => 'code',
				'scope'         => 'email profile',
			) );
		?>
		<div style="text-align:center;margin:15px 0;color:#999;font-size:13px;">— або —</div>
		<a href="<?php echo esc_url( $g_url ); ?>" style="display:block;text-align:center;background:#4285f4;color:#fff;padding:11px;border-radius:6px;text-decoration:none;font-size:14px;">🔑 Увійти через Google</a>
		<?php endif; ?>

		<div style="text-align:center;margin-top:15px;font-size:13px;color:#999;">
			<a href="<?php echo home_url( '/forgot-password/' ); ?>">Забули пароль?</a>
			&nbsp;·&nbsp;
			<a href="<?php echo home_url( '/register/' ); ?>">Зареєструватися</a>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

// ============================================
// 18. ВІДНОВЛЕННЯ ПАРОЛЯ - ШОРТКОД [fbm_forgot_password]
// ============================================
add_shortcode( 'fbm_forgot_password', 'fbm_forgot_password_shortcode' );
function fbm_forgot_password_shortcode() {
	if ( is_user_logged_in() ) {
		wp_redirect( home_url( '/my-budget/' ) );
		exit;
	}

	$error   = '';
	$success = '';

	// Крок 1: запит на скидання
	if ( isset( $_POST['fbm_do_forgot'] ) && check_admin_referer( 'fbm_forgot' ) ) {
		$email = sanitize_email( $_POST['forgot_email'] );
		if ( ! is_email( $email ) ) {
			$error = 'Введіть коректний email.';
		} else {
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				// Не кажемо чи існує — безпека
				$success = 'Якщо цей email зареєстровано, ви отримаєте лист із інструкціями.';
			} else {
				$result = retrieve_password( $user->user_login );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
				} else {
					$success = 'Лист із посиланням для скидання пароля відправлено на ваш email.';
				}
			}
		}
	}

	// Крок 2: форма нового пароля (за токеном з email)
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'rp' && isset( $_GET['key'] ) && isset( $_GET['login'] ) ) {
		$login = sanitize_user( wp_unslash( $_GET['login'] ) );
		$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) );
		$check = check_password_reset_key( $key, $login );

		if ( is_wp_error( $check ) ) {
			ob_start();
			?>
			<div style="max-width:420px;margin:40px auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.1);text-align:center;">
				<p style="color:#f44336;">❌ Посилання недійсне або застаріло. <a href="<?php echo home_url( '/forgot-password/' ); ?>">Спробувати знову</a></p>
			</div>
			<?php
			return ob_get_clean();
		}

		$reset_error   = '';
		$reset_success = '';

		if ( isset( $_POST['fbm_do_reset'] ) && check_admin_referer( 'fbm_reset_password' ) ) {
			$new_pass  = $_POST['new_password'];
			$new_pass2 = $_POST['new_password2'];
			if ( empty( $new_pass ) || strlen( $new_pass ) < 6 ) {
				$reset_error = 'Пароль має бути не менше 6 символів.';
			} elseif ( $new_pass !== $new_pass2 ) {
				$reset_error = 'Паролі не співпадають.';
			} else {
				reset_password( $check, $new_pass );
				$reset_success = 'Пароль змінено! Тепер можна увійти.';
			}
		}

		ob_start();
		?>
		<div style="max-width:420px;margin:40px auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.1);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
			<h2 style="text-align:center;margin-bottom:20px;">🔐 Новий пароль</h2>
			<?php if ( $reset_error ) : ?>
				<div style="background:#ffebee;color:#c62828;padding:10px;border-radius:6px;margin-bottom:15px;border-left:4px solid #f44336;">❌ <?php echo esc_html( $reset_error ); ?></div>
			<?php endif; ?>
			<?php if ( $reset_success ) : ?>
				<div style="background:#e8f5e9;color:#1b5e20;padding:10px;border-radius:6px;margin-bottom:15px;">✅ <?php echo esc_html( $reset_success ); ?></div>
				<p style="text-align:center;"><a href="<?php echo home_url( '/login/' ); ?>">👉 Перейти до входу</a></p>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'fbm_reset_password' ); ?>
					<input type="hidden" name="action" value="rp">
					<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
					<input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">
					<div style="margin-bottom:12px;">
						<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Новий пароль</label>
						<input type="password" name="new_password" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Мін. 6 символів">
					</div>
					<div style="margin-bottom:20px;">
						<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Повторіть пароль</label>
						<input type="password" name="new_password2" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Повторіть пароль">
					</div>
					<button type="submit" name="fbm_do_reset" style="width:100%;padding:12px;background:#2271b1;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;">💾 Зберегти пароль</button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	ob_start();
	?>
	<div style="max-width:420px;margin:40px auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,0.1);font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
		<h2 style="text-align:center;margin-bottom:20px;">🔑 Відновлення пароля</h2>

		<?php if ( $error ) : ?>
			<div style="background:#ffebee;color:#c62828;padding:10px;border-radius:6px;margin-bottom:15px;border-left:4px solid #f44336;">❌ <?php echo esc_html( $error ); ?></div>
		<?php endif; ?>
		<?php if ( $success ) : ?>
			<div style="background:#e8f5e9;color:#1b5e20;padding:10px;border-radius:6px;margin-bottom:15px;">✅ <?php echo esc_html( $success ); ?></div>
		<?php endif; ?>

		<?php if ( ! $success ) : ?>
		<form method="post">
			<?php wp_nonce_field( 'fbm_forgot' ); ?>
			<div style="margin-bottom:12px;">
				<label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Ваш Email</label>
				<input type="email" name="forgot_email" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="your@email.com">
			</div>
			<button type="submit" name="fbm_do_forgot" style="width:100%;padding:12px;background:#2271b1;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;">📧 Надіслати лист</button>
		</form>
		<?php endif; ?>

		<p style="text-align:center;margin-top:15px;font-size:13px;color:#999;">
			<a href="<?php echo home_url( '/login/' ); ?>">← Повернутися до входу</a>
		</p>
	</div>
	<?php
	return ob_get_clean();
}

// ============================================
// 19. ОСОБИСТИЙ КАБІНЕТ - ШОРТКОД [fbm_profile]
// ============================================
add_shortcode( 'fbm_profile', 'fbm_profile_shortcode' );
function fbm_profile_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<div style="text-align:center;padding:30px;"><p>🔒 <a href="' . home_url( '/login/' ) . '">Увійдіть</a>, щоб переглянути кабінет.</p></div>';
	}

	$user_id = get_current_user_id();
	$user    = get_userdata( $user_id );
	$error   = '';
	$success = '';

	// Оновлення профілю
	if ( isset( $_POST['fbm_save_profile'] ) && check_admin_referer( 'fbm_profile' ) ) {
		$first_name  = sanitize_text_field( $_POST['profile_firstname'] );
		$last_name   = sanitize_text_field( $_POST['profile_lastname'] );
		$new_email   = sanitize_email( $_POST['profile_email'] );
		$new_pass    = $_POST['profile_password'];
		$new_pass2   = $_POST['profile_password2'];

		$update = array( 'ID' => $user_id );

		if ( $new_email && $new_email !== $user->user_email ) {
			if ( ! is_email( $new_email ) ) {
				$error = 'Невірний формат email.';
			} elseif ( email_exists( $new_email ) && email_exists( $new_email ) !== $user_id ) {
				$error = 'Цей email вже використовується.';
			} else {
				$update['user_email'] = $new_email;
			}
		}

		if ( ! $error && ! empty( $new_pass ) ) {
			if ( strlen( $new_pass ) < 6 ) {
				$error = 'Пароль має бути не менше 6 символів.';
			} elseif ( $new_pass !== $new_pass2 ) {
				$error = 'Паролі не співпадають.';
			} else {
				$update['user_pass'] = $new_pass;
			}
		}

		if ( ! $error ) {
			wp_update_user( $update );
			update_user_meta( $user_id, 'first_name', $first_name );
			update_user_meta( $user_id, 'last_name', $last_name );
			$user    = get_userdata( $user_id );
			$success = 'Профіль оновлено!';
		}
	}

	// Видалення акаунту
	if ( isset( $_POST['fbm_delete_account'] ) && check_admin_referer( 'fbm_delete_account' ) ) {
		global $wpdb;
		$tables = array( 'fbm_transactions', 'fbm_utilities', 'fbm_accounts', 'fbm_phones', 'fbm_telegram', 'fbm_categories' );
		foreach ( $tables as $t ) {
			$wpdb->delete( $wpdb->prefix . $t, array( 'user_id' => $user_id ) );
		}
		wp_delete_user( $user_id );
		wp_logout();
		wp_redirect( home_url( '/' ) );
		exit;
	}

	ob_start();
	?>
	<style>
		.fbm-profile-wrap { max-width:700px;margin:30px auto;padding:0 15px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
		.fbm-profile-wrap .fbm-nav { display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap; }
		.fbm-profile-wrap .fbm-nav a { padding:8px 16px;background:#f1f1f1;border-radius:6px;text-decoration:none;color:#333;font-size:14px; }
		.fbm-profile-wrap .fbm-nav a.active { background:#2271b1;color:#fff; }
		.fbm-profile-card { background:#fff;padding:25px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,0.1);margin-bottom:20px; }
		.fbm-profile-card h3 { margin:0 0 18px 0;font-size:16px;padding-bottom:10px;border-bottom:1px solid #eee; }
		.fbm-profile-card label { display:block;font-size:13px;color:#555;margin-bottom:4px; }
		.fbm-profile-card input[type=text],
		.fbm-profile-card input[type=email],
		.fbm-profile-card input[type=password] { width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;margin-bottom:12px;font-size:14px; }
		.fbm-profile-card .fbm-btn { padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px; }
		.fbm-profile-card .fbm-btn-primary { background:#2271b1;color:#fff; }
		.fbm-profile-card .fbm-btn-danger { background:#f44336;color:#fff; }
		.fbm-profile-avatar { width:80px;height:80px;border-radius:50%;background:#2271b1;color:#fff;font-size:32px;display:flex;align-items:center;justify-content:center;margin:0 auto 15px auto; }
		.fbm-profile-info { text-align:center;margin-bottom:20px; }
		.fbm-profile-info h2 { margin:5px 0;font-size:20px; }
		.fbm-profile-info p { color:#888;font-size:13px;margin:0; }
		.fbm-two-col { display:grid;grid-template-columns:1fr 1fr;gap:15px; }
		@media(max-width:600px){ .fbm-two-col { grid-template-columns:1fr; } }
	</style>

	<div class="fbm-profile-wrap">
		<div class="fbm-nav">
			<a href="<?php echo home_url( '/my-budget/' ); ?>">📊 Бюджет</a>
			<a href="<?php echo home_url( '/my-settings/' ); ?>">⚙️ Налаштування</a>
			<a href="<?php echo home_url( '/my-profile/' ); ?>" class="active">👤 Профіль</a>
			<a href="<?php echo wp_logout_url( home_url() ); ?>">🚪 Вийти</a>
		</div>

		<div class="fbm-profile-card">
			<div class="fbm-profile-avatar"><?php echo mb_substr( $user->display_name, 0, 1 ); ?></div>
			<div class="fbm-profile-info">
				<h2><?php echo esc_html( $user->display_name ); ?></h2>
				<p><?php echo esc_html( $user->user_email ); ?></p>
				<p>Зареєстровано: <?php echo date( 'd.m.Y', strtotime( $user->user_registered ) ); ?></p>
			</div>
		</div>

		<?php if ( $error ) : ?>
			<div style="background:#ffebee;color:#c62828;padding:10px;border-radius:6px;margin-bottom:15px;border-left:4px solid #f44336;">❌ <?php echo esc_html( $error ); ?></div>
		<?php endif; ?>
		<?php if ( $success ) : ?>
			<div style="background:#e8f5e9;color:#1b5e20;padding:10px;border-radius:6px;margin-bottom:15px;">✅ <?php echo esc_html( $success ); ?></div>
		<?php endif; ?>

		<div class="fbm-profile-card">
			<h3>✏️ Редагувати профіль</h3>
			<form method="post">
				<?php wp_nonce_field( 'fbm_profile' ); ?>
				<div class="fbm-two-col">
					<div>
						<label>Ім'я</label>
						<input type="text" name="profile_firstname" value="<?php echo esc_attr( $user->first_name ); ?>" placeholder="Ваше ім'я">
					</div>
					<div>
						<label>Прізвище</label>
						<input type="text" name="profile_lastname" value="<?php echo esc_attr( $user->last_name ); ?>" placeholder="Ваше прізвище">
					</div>
				</div>
				<label>Email</label>
				<input type="email" name="profile_email" value="<?php echo esc_attr( $user->user_email ); ?>">
				<label>Новий пароль <small style="color:#999;">(залиште порожнім, щоб не змінювати)</small></label>
				<input type="password" name="profile_password" placeholder="Новий пароль (мін. 6 символів)" autocomplete="new-password">
				<label>Повторіть новий пароль</label>
				<input type="password" name="profile_password2" placeholder="Повторіть новий пароль" autocomplete="new-password">
				<button type="submit" name="fbm_save_profile" class="fbm-btn fbm-btn-primary">💾 Зберегти зміни</button>
			</form>
		</div>

		<div class="fbm-profile-card">
			<h3 style="color:#f44336;">⚠️ Небезпечна зона</h3>
			<p style="font-size:13px;color:#666;">Видалення акаунту призведе до видалення всіх ваших даних. Цю дію неможливо відновити.</p>
			<form method="post" onsubmit="return confirm('⚠️ Ви ТОЧНО хочете видалити акаунт і всі дані? Це неможливо відновити!');">
				<?php wp_nonce_field( 'fbm_delete_account' ); ?>
				<button type="submit" name="fbm_delete_account" class="fbm-btn fbm-btn-danger">🗑️ Видалити мій акаунт</button>
			</form>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

// ============================================
// 20. ДОДАТКОВІ СТОРІНКИ ПРИ АКТИВАЦІЇ
// ============================================
// Перезаписуємо fbm_create_pages щоб додати нові сторінки
remove_action( 'init', 'fbm_create_pages' ); // на випадок якщо хтось чіпляв на init
register_activation_hook( __FILE__, 'fbm_create_all_pages' );
function fbm_create_all_pages() {
	fbm_activate();
	$pages = array(
		array( 'title' => 'Мій бюджет',        'content' => '[fbm_dashboard]',        'slug' => 'my-budget' ),
		array( 'title' => 'Налаштування',       'content' => '[fbm_settings]',         'slug' => 'my-settings' ),
		array( 'title' => 'Мій профіль',        'content' => '[fbm_profile]',          'slug' => 'my-profile' ),
		array( 'title' => 'Вхід',               'content' => '[fbm_login]',            'slug' => 'login' ),
		array( 'title' => 'Реєстрація',         'content' => '[fbm_register]',         'slug' => 'register' ),
		array( 'title' => 'Забули пароль',      'content' => '[fbm_forgot_password]',  'slug' => 'forgot-password' ),
	);
	foreach ( $pages as $page ) {
		if ( ! get_page_by_path( $page['slug'] ) ) {
			wp_insert_post( array(
				'post_title'   => $page['title'],
				'post_content' => $page['content'],
				'post_name'    => $page['slug'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
			) );
		}
	}
}

// ============================================
// 21. ПЕРЕНАПРАВЛЕННЯ НА КАСТОМНИЙ ЛОГІН
// ============================================
// Замінюємо стандартну сторінку wp-login.php на нашу
add_action( 'login_init', 'fbm_redirect_login_page' );
function fbm_redirect_login_page() {
	$login_page  = home_url( '/login/' );
	$action      = isset( $_GET['action'] ) ? $_GET['action'] : 'login';

	// Пропускаємо адмін-входи та спеціальні дії
	if ( is_admin() || in_array( $action, array( 'logout', 'lostpassword', 'resetpass', 'rp', 'postpass', 'register' ) ) ) {
		return;
	}
	if ( ! isset( $_POST['log'] ) ) {
		wp_redirect( $login_page );
		exit;
	}
}

// Перенаправлення після виходу
add_action( 'wp_logout', 'fbm_logout_redirect' );
function fbm_logout_redirect() {
	wp_redirect( home_url( '/login/' ) );
	exit;
}

// Посилання для скидання пароля ведуть на нашу сторінку
add_filter( 'lostpassword_url', 'fbm_lostpassword_url', 10, 2 );
function fbm_lostpassword_url( $lostpassword_url, $redirect ) {
	return home_url( '/forgot-password/' );
}

// Посилання реєстрації → наша сторінка
add_filter( 'register_url', 'fbm_register_url' );
function fbm_register_url( $url ) {
	return home_url( '/register/' );
}

// Посилання входу → наша сторінка
add_filter( 'login_url', 'fbm_custom_login_url', 10, 3 );
function fbm_custom_login_url( $login_url, $redirect, $force_reauth ) {
	return home_url( '/login/' );
}

// Посилання для скидання пароля з email → наша сторінка
add_filter( 'retrieve_password_message', 'fbm_custom_reset_password_message', 10, 4 );
function fbm_custom_reset_password_message( $message, $key, $user_login, $user_data ) {
	$reset_url = add_query_arg( array(
		'action' => 'rp',
		'key'    => $key,
		'login'  => rawurlencode( $user_login ),
	), home_url( '/forgot-password/' ) );

	$message = sprintf(
		"Ви або хтось інший запросив скидання пароля для акаунту: %s\n\nЯкщо це були не ви — проігноруйте цей лист.\n\nДля скидання пароля перейдіть за посиланням:\n%s\n\nПосилання дійсне 24 години.",
		home_url(),
		$reset_url
	);
	return $message;
}

// Тема листа скидання пароля
add_filter( 'retrieve_password_title', 'fbm_reset_password_title' );
function fbm_reset_password_title( $title ) {
	return 'Скидання пароля — ' . get_bloginfo( 'name' );
}

add_action( 'wp_head', 'fbm_pwa_head' );
function fbm_pwa_head() {
	?>
	<link rel="manifest" href="<?php echo home_url( '/?fbm_manifest=1' ); ?>">
	<meta name="theme-color" content="#2271b1">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="Мій бюджет">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
	<?php
}

add_action( 'template_redirect', 'fbm_manifest' );
function fbm_manifest() {
	if ( isset( $_GET['fbm_manifest'] ) ) {
		header( 'Content-Type: application/json' );
		echo json_encode( array(
			'name' => 'Мій бюджет',
			'short_name' => 'Бюджет',
			'start_url' => home_url( '/my-budget/' ),
			'display' => 'standalone',
			'background_color' => '#ffffff',
			'theme_color' => '#2271b1',
			'icons' => array(
				array( 'src' => 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">💰</text></svg>', 'sizes' => '192x192', 'type' => 'image/svg+xml' )
			)
		) );
		exit;
	}
}
