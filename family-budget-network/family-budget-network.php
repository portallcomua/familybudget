<?php
/**
 * Plugin Name: Family Budget Network PRO
 * Description: Керування бюджетом, комунальними платежами, інтернетом та мобільним
 * Version: 4.2.2
 * Author: UAServer
 * Author URI: https://uaserver.pp.ua
 * License: GPL v2 or later
 * Update URI: https://github.com/portallcomua/familybudget
 */

define( 'FBM_VERSION', '4.1.0' );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FBM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FBM_GITHUB_REPO', 'portallcomua/familybudget' );
define( 'FBM_GITHUB_RELEASE_ASSET', 'family-budget-network.zip' );
define( 'FBM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// ============================================
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
            PRIMARY KEY (id),
            UNIQUE KEY user_category (user_id, name, type)
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

    fbm_add_default_categories();
    fbm_update_tables_if_needed();
    fbm_400_create_tables();
}
register_activation_hook( __FILE__, 'fbm_activate' );

// ============================================
// 2. СТАНДАРТНІ КАТЕГОРІЇ
// ============================================
function fbm_add_default_categories() {
    global $wpdb;
    $table = $wpdb->prefix . 'fbm_categories';

    $cats = array(
        array( 'Зарплата', 'income' ),
        array( 'Дохід', 'income' ),
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
        array( 'Інше (витрата)', 'expense' )
    );

    foreach ( $cats as $cat ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = 0 AND name = %s AND type = %s",
            $cat[0], $cat[1]
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
// 3. ОНОВЛЕННЯ ТАБЛИЦЬ
// ============================================
function fbm_update_tables_if_needed() {
    global $wpdb;
    
    $table_trans = $wpdb->prefix . 'fbm_transactions';
    $table_util = $wpdb->prefix . 'fbm_utilities';
    
    $row = $wpdb->get_results("SHOW COLUMNS FROM $table_trans LIKE 'status'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE $table_trans ADD COLUMN status varchar(20) DEFAULT 'completed' AFTER payment_method");
    }
    
    $row = $wpdb->get_results("SHOW COLUMNS FROM $table_util LIKE 'meter_current'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE $table_util ADD COLUMN meter_current varchar(50) DEFAULT '' AFTER meter_previous");
    }
    
    $row = $wpdb->get_results("SHOW COLUMNS FROM $table_util LIKE 'meter_previous'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE $table_util ADD COLUMN meter_previous varchar(50) DEFAULT '' AFTER personal_account");
    }
    
    $row = $wpdb->get_results("SHOW COLUMNS FROM $table_util LIKE 'tariff'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE $table_util ADD COLUMN tariff decimal(10,2) DEFAULT 0 AFTER meter_current");
    }
    
    $row = $wpdb->get_results("SHOW COLUMNS FROM $table_util LIKE 'provider'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE $table_util ADD COLUMN provider varchar(100) DEFAULT '' AFTER service_name");
    }
    
    $row = $wpdb->get_results("SHOW COLUMNS FROM $table_util LIKE 'personal_account'");
    if (empty($row)) {
        $wpdb->query("ALTER TABLE $table_util ADD COLUMN personal_account varchar(50) NOT NULL DEFAULT '' AFTER provider");
    }
}
add_action('plugins_loaded', 'fbm_update_tables_if_needed');

// ============================================
// 2.2. FAMILY BUDGET 4.0: СІМЕЙНИЙ ДОСТУП + PORTMONE TABLES
// ============================================
function fbm_400_create_tables() {
    global $wpdb;
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    $charset = $wpdb->get_charset_collate();

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_families (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        owner_user_id bigint(20) NOT NULL,
        name varchar(150) NOT NULL DEFAULT 'Сімейний бюджет',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY owner_user_id (owner_user_id)
    ) $charset;" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_family_members (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        family_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        email varchar(190) DEFAULT '',
        role varchar(30) DEFAULT 'member',
        status varchar(30) DEFAULT 'pending',
        invite_token varchar(64) DEFAULT '',
        invited_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        accepted_at datetime DEFAULT NULL,
        PRIMARY KEY (id),
        KEY family_id (family_id),
        KEY user_id (user_id),
        KEY invite_token (invite_token)
    ) $charset;" );

    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fbm_portmone_bills (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        bill_id varchar(80) NOT NULL,
        payee_id varchar(80) DEFAULT '',
        payee_name varchar(190) DEFAULT '',
        contract_number varchar(120) DEFAULT '',
        amount decimal(10,2) DEFAULT 0,
        status varchar(40) DEFAULT 'created',
        raw_response longtext DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY bill_id (bill_id)
    ) $charset;" );
}
add_action( 'plugins_loaded', 'fbm_400_create_tables' );

function fbm_get_or_create_family_id( $owner_user_id ) {
    global $wpdb;
    $owner_user_id = (int) $owner_user_id;
    $table = $wpdb->prefix . 'fbm_families';
    $family_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE owner_user_id = %d", $owner_user_id ) );
    if ( ! $family_id ) {
        $wpdb->insert( $table, array( 'owner_user_id' => $owner_user_id, 'name' => 'Сімейний бюджет' ) );
        $family_id = (int) $wpdb->insert_id;
    }
    return $family_id;
}

function fbm_get_budget_owner_id( $user_id = 0 ) {
    global $wpdb;
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if ( ! $user_id ) return 0;
    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT f.owner_user_id FROM {$wpdb->prefix}fbm_family_members m
         INNER JOIN {$wpdb->prefix}fbm_families f ON f.id = m.family_id
         WHERE m.user_id = %d AND m.status = 'accepted'
         ORDER BY m.accepted_at DESC LIMIT 1",
        $user_id
    ) );
    return $member ? (int) $member->owner_user_id : $user_id;
}

function fbm_user_is_family_owner( $user_id = 0 ) {
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    return $user_id && fbm_get_budget_owner_id( $user_id ) === $user_id;
}

add_action( 'init', 'fbm_accept_family_invite' );
function fbm_accept_family_invite() {
    if ( empty( $_GET['fbm_accept_family'] ) || ! is_user_logged_in() ) return;
    global $wpdb;
    $token = sanitize_text_field( wp_unslash( $_GET['fbm_accept_family'] ) );
    $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fbm_family_members WHERE invite_token = %s AND status = 'pending'", $token ) );
    if ( $row ) {
        $wpdb->update( $wpdb->prefix . 'fbm_family_members', array(
            'user_id' => get_current_user_id(),
            'status' => 'accepted',
            'accepted_at' => current_time( 'mysql' ),
        ), array( 'id' => $row->id ) );
        wp_safe_redirect( add_query_arg( 'family_accepted', '1', home_url( '/my-settings/' ) ) );
        exit;
    }
}


// ============================================
// 4. ВИЗНАЧЕННЯ ОПЕРАТОРА
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
// 5. TELEGRAM
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
// 6. ГЕНЕРАЦІЯ ПОСИЛАННЯ НА iPAY.UA
// ============================================
function fbm_generate_ipay_link( $service, $provider, $personal_account, $amount, $return_url = '' ) {
    // API-ready режим: реальна онлайн-оплата буде підключена через PortmoneDirect/PaymentGateway після договору та production-доступів.
    // Поки що не відправляємо користувача на сторонні сторінки, щоб не створювати хибне враження, що пакетна оплата вже працює.
    return add_query_arg(
        array(
            'fbm_payment_pending' => 1,
            'service'             => rawurlencode( $service ),
            'amount'              => rawurlencode( number_format( (float) $amount, 2, '.', '' ) ),
        ),
        home_url( '/my-budget/' )
    );
}

// ============================================
// 7. ЕКСПОРТ CSV
// ============================================
function fbm_export_csv() {
    global $wpdb;
    $user_id = fbm_get_budget_owner_id( get_current_user_id() );
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
// 8. ЕКСПОРТ PDF
// ============================================
function fbm_export_pdf() {
    global $wpdb;
    $user_id = fbm_get_budget_owner_id( get_current_user_id() );
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
            .print-btn { display: block; width: 200px; margin: 20px auto; padding: 10px; background: #2271b1; color: #fff; text-align: center; border: none; border-radius: 4px; cursor: pointer; font-size:14px; }
            .back-btn { display: block; width: 200px; margin: 10px auto; padding: 10px; background: #46b450; color: #fff; text-align: center; border: none; border-radius: 4px; cursor: pointer; font-size:14px; text-decoration: none; }
            @media print { .print-btn { display: none; } .back-btn { display: none; } }
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
        <a href="<?php echo home_url('/my-budget/'); ?>" class="back-btn">🔙 Повернутись в кабінет</a>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// 9. АДМІН-ПАНЕЛЬ
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
    add_submenu_page(
        'family-budget',
        'Реклама',
        '📣 Реклама',
        'manage_options',
        'fbm-ads',
        'fbm_ads_settings_page'
    );
    add_submenu_page(
        'family-budget',
        'Portmone',
        '💳 Portmone',
        'manage_options',
        'fbm-portmone',
        'fbm_portmone_settings_page'
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
        <h1>📊 Сімейний бюджет <span style="font-size:13px;background:#2271b1;color:#fff;padding:4px 8px;border-radius:12px;vertical-align:middle;">v<?php echo esc_html( FBM_VERSION ); ?></span></h1>

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
        <h1>⚙️ Налаштування адміністратора <span style="font-size:13px;background:#2271b1;color:#fff;padding:4px 8px;border-radius:12px;vertical-align:middle;">v<?php echo esc_html( FBM_VERSION ); ?></span></h1>

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
// 10. GOOGLE LOGIN
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
        <p style="color:#666;">Швидкий вхід через Google</p>
        <a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#4285f4;color:#fff;padding:12px 30px;border-radius:4px;text-decoration:none;font-weight:500;font-size:16px;">
            🔑 Увійти через Google
        </a>
        <p style="margin-top:15px;font-size:13px;color:#999;">
            <a href="<?php echo home_url( '/login/' ); ?>">Авторизуватись</a> · <a href="<?php echo home_url( '/register/' ); ?>">Зареєструватись</a> · <a href="<?php echo home_url( '/forgot-password/' ); ?>">Забули пароль?</a>
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
// 11. ФРОНТЕНД - НАЛАШТУВАННЯ
// ============================================
add_shortcode( 'fbm_settings', 'fbm_frontend_settings' );
function fbm_frontend_settings() {
    if ( ! is_user_logged_in() ) {
        return '<p>🔒 <a href="' . wp_login_url() . '">Увійдіть</a></p>';
    }

    global $wpdb;
    $real_user_id = get_current_user_id();
    $user_id = fbm_get_budget_owner_id( $real_user_id );

    if ( isset( $_POST['fbm_add_category'] ) && check_admin_referer( 'fbm_settings' ) ) {
        $name = sanitize_text_field( $_POST['category_name'] );
        $type = sanitize_text_field( $_POST['category_type'] );
        if ( ! empty( $name ) && in_array( $type, array( 'income', 'expense' ), true ) ) {
            $wpdb->insert( $wpdb->prefix . 'fbm_categories', array(
                'user_id' => $user_id,
                'name' => $name,
                'type' => $type,
                'is_default' => 0
            ) );
            echo '<div style="background:#c6f6d5;padding:10px;border-radius:4px;margin:10px 0;">✅ Категорію додано!</div>';
        }
    }

    if ( isset( $_POST['fbm_update_category'] ) && check_admin_referer( 'fbm_settings' ) ) {
        $cat_id   = intval( $_POST['category_id'] ?? 0 );
        $new_name = sanitize_text_field( $_POST['category_new_name'] ?? '' );
        $new_type = sanitize_text_field( $_POST['category_new_type'] ?? '' );
        if ( $cat_id > 0 && $new_name !== '' && in_array( $new_type, array( 'income', 'expense' ), true ) ) {
            $updated = $wpdb->update(
                $wpdb->prefix . 'fbm_categories',
                array( 'name' => $new_name, 'type' => $new_type ),
                array( 'id' => $cat_id, 'user_id' => $user_id, 'is_default' => 0 )
            );
            if ( false !== $updated ) {
                echo '<div style="background:#c6f6d5;padding:10px;border-radius:4px;margin:10px 0;">✅ Категорію оновлено!</div>';
            }
        }
    }

    if ( isset( $_GET['delete_cat'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'fbm_delete_cat_' . intval( $_GET['delete_cat'] ) ) ) {
        $wpdb->delete( $wpdb->prefix . 'fbm_categories', array(
            'id' => intval( $_GET['delete_cat'] ),
            'user_id' => $user_id
        ) );
        echo '<div style="background:#c6f6d5;padding:10px;border-radius:4px;margin:10px 0;">✅ Категорію видалено!</div>';
    }

    if ( isset( $_POST['fbm_save_accounts'] ) && check_admin_referer( 'fbm_settings' ) ) {
        $services = array( 'Електроенергія', 'Водопостачання', 'Газ', 'Інтернет' );
        foreach ( $services as $s ) {
            $account = sanitize_text_field( $_POST['account_' . $s] );
            $tariff = floatval( str_replace( ',', '.', $_POST['tariff_' . $s] ) );
            $provider = $s === 'Інтернет' ? sanitize_text_field( $_POST['provider_' . $s] ) : '';
            if ( ! empty( $account ) ) {
                $wpdb->replace( $wpdb->prefix . 'fbm_accounts', array(
                    'user_id' => $user_id,
                    'service_name' => $s,
                    'provider' => $provider,
                    'personal_account' => $account,
                    'tariff' => $tariff
                ) );
                update_user_meta( $user_id, 'fbm_default_account_' . sanitize_key( $s ), array(
                    'personal_account' => $account,
                    'tariff' => $tariff,
                    'provider' => $provider,
                ) );
            } else {
                delete_user_meta( $user_id, 'fbm_default_account_' . sanitize_key( $s ) );
            }
        }
        echo '<div style="background:#c6f6d5;padding:10px;border-radius:4px;margin:10px 0;">✅ Налаштування збережено!</div>';
    }

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
            if ( empty( $operator ) ) {
                $operator = fbm_detect_operator( $phone_clean );
                if ( empty( $operator ) ) {
                    $operator = 'Київстар';
                }
            }
            $label = sanitize_text_field( $_POST['phone_label'] );
            $tariff = floatval( str_replace( ',', '.', $_POST['phone_tariff'] ) );

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
            if ( ! empty( $data['ok'] ) ) {
                $bot_username = $data['result']['username'];
            }
        }
    }

    $categories = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fbm_categories WHERE user_id = 0 OR user_id = %d ORDER BY type, name",
        $user_id
    ) );
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
        .fbm-cat-tag { display:inline-block; padding:2px 8px; margin:2px; border-radius:3px; font-size:12px; }
        .fbm-cat-tag-income { background:#e8f5e9; }
        .fbm-cat-tag-expense { background:#ffebee; }
        .fbm-history-filter label { display:block;font-size:12px;color:#555;margin-bottom:3px; } .fbm-history-filter select,.fbm-history-filter input { width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box; }
        @media (max-width:768px) {
            .fbm-grid { grid-template-columns:1fr; }
            .fbm-history-filter { grid-template-columns:1fr !important; }
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
                <p style="font-size:13px;color:#666;">Тарифи вводьте з крапкою (наприклад, 4.50) або комою (4,50)</p>
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
                                value="<?php echo isset( $saved_accounts[$s] ) ? esc_attr( $saved_accounts[$s]->personal_account ) : ( get_user_meta( $user_id, 'fbm_default_account_' . sanitize_key($s), true )['personal_account'] ?? '' ); ?>" style="margin:5px 0;width:100%;">
                            <input type="text" step="0.01" name="tariff_<?php echo $s; ?>" placeholder="Тариф (за замовчуванням)"
                                value="<?php echo isset( $saved_accounts[$s] ) && $saved_accounts[$s]->tariff ? esc_attr( $saved_accounts[$s]->tariff ) : ( get_user_meta( $user_id, 'fbm_default_account_' . sanitize_key($s), true )['tariff'] ?? '' ); ?>" style="margin:5px 0;width:100%;">
                        </div>
                    <?php endforeach; ?>

                    <p><input type="submit" name="fbm_save_accounts" class="btn btn-primary" value="💾 Зберегти"></p>
                </form>
            </div>

            <div>
                <div class="fbm-card">
                    <h3>📱 Номери телефонів</h3>
                    <p style="font-size:13px;color:#666;">Оператор визначиться автоматично, якщо номер починається з 050, 067, 095, 099, 063, 073, 093, 096, 097, 098, 066</p>
                    <?php if ( $saved_phones ) : foreach ( $saved_phones as $p ) : ?>
                        <div class="fbm-phone-item">
                            <span class="label"><?php echo esc_html( $p->label ?: 'Телефон' ); ?></span>
                            <span class="phone"><?php echo esc_html( $p->phone ); ?></span>
                            <span class="operator">(<?php echo esc_html( $p->operator ); ?>)</span>
                            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'delete_phone', $p->id ), 'fbm_delete_phone_' . $p->id ) ); ?>" onclick="return confirm('Видалити номер?')" style="color:#f44336;margin-left:auto;">🗑️</a>
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
                            <option value="">Автовизначення</option>
                            <option value="Київстар">Київстар</option>
                            <option value="Vodafone">Vodafone</option>
                            <option value="Lifecell">Lifecell</option>
                        </select>
                        <input type="text" name="phone_label" placeholder="Підпис (наприклад, Мій, Дружина)" style="margin-bottom:5px;width:100%;">
                        <input type="text" step="0.01" name="phone_tariff" placeholder="Тариф (сума за замовчуванням)" style="margin-bottom:5px;width:100%;">
                        <input type="submit" name="fbm_save_phone" class="btn btn-secondary" value="➕ Додати номер">
                    </form>
                </div>

                <div class="fbm-card">
                    <h3>🏷️ Категорії</h3>
                    <form method="post" style="margin-bottom:10px;">
                        <?php wp_nonce_field( 'fbm_settings' ); ?>
                        <div style="display:flex;gap:5px;flex-wrap:wrap;">
                            <input type="text" name="category_name" placeholder="Назва категорії" required style="flex:1;min-width:100px;">
                            <select name="category_type" style="min-width:80px;">
                                <option value="income">💚 Дохід</option>
                                <option value="expense">🔴 Витрата</option>
                            </select>
                            <input type="submit" name="fbm_add_category" class="btn btn-secondary" value="➕ Додати">
                        </div>
                    </form>
                    <div>
                        <?php 
                        $cats_income = array_filter( $categories, function( $c ) { return $c->type == 'income'; } );
                        $cats_expense = array_filter( $categories, function( $c ) { return $c->type == 'expense'; } );
                        $render_category_list = function( $items, $type_label, $tag_class ) {
                            echo '<div style="margin-bottom:12px;"><small><strong>' . esc_html( $type_label ) . '</strong></small><br>';
                            foreach ( $items as $cat ) {
                                if ( (int) $cat->user_id === 0 ) {
                                    echo '<span class="fbm-cat-tag ' . esc_attr( $tag_class ) . '">' . esc_html( $cat->name ) . ' <small>(системна)</small></span> ';
                                } else {
                                    echo '<form method="post" style="display:flex;gap:5px;align-items:center;margin:5px 0;flex-wrap:wrap;">';
                                    wp_nonce_field( 'fbm_settings' );
                                    echo '<input type="hidden" name="category_id" value="' . esc_attr( $cat->id ) . '">';
                                    echo '<input type="text" name="category_new_name" value="' . esc_attr( $cat->name ) . '" style="max-width:180px;">';
                                    echo '<select name="category_new_type" style="max-width:130px;">';
                                    echo '<option value="income" ' . selected( $cat->type, 'income', false ) . '>💚 Дохід</option>';
                                    echo '<option value="expense" ' . selected( $cat->type, 'expense', false ) . '>🔴 Витрата</option>';
                                    echo '</select>';
                                    echo '<input type="submit" name="fbm_update_category" class="btn btn-secondary" value="💾" style="padding:8px 10px;">';
                                    echo '<a href="' . esc_url( wp_nonce_url( add_query_arg( 'delete_cat', $cat->id ), 'fbm_delete_cat_' . $cat->id ) ) . '" onclick="return confirm(&quot;Видалити категорію?&quot;)" class="btn btn-danger" style="padding:8px 10px;">🗑️</a>';
                                    echo '</form>';
                                }
                            }
                            echo '</div>';
                        };
                        $render_category_list( $cats_income, '💚 Доходи', 'fbm-cat-tag-income' );
                        $render_category_list( $cats_expense, '🔴 Витрати', 'fbm-cat-tag-expense' );
                        ?>
                    </div>
                    <small style="color:#666;">🔹 Системні категорії не редагуються. Власні категорії можна перейменувати, перенести між доходами/витратами або видалити.</small>
                </div>


                <div class="fbm-card">
                    <h3>📲 Встановити як додаток</h3>
                    <p style="font-size:13px;color:#666;">Додайте Family Budget на головний екран смартфона або робочий стіл компʼютера. Кабінет відкриватиметься як звичайний застосунок.</p>
                    <?php echo do_shortcode( '[fbm_install_button]' ); ?>
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
// 11.1. ІСТОРІЯ: ФІЛЬТРИ ТА ДОПОМІЖНІ ФУНКЦІЇ
// ============================================
function fbm_get_history_months( $user_id ) {
    global $wpdb;
    return $wpdb->get_results( $wpdb->prepare(
        "SELECT DISTINCT DATE_FORMAT(created_at, '%%Y-%%m') AS ym FROM {$wpdb->prefix}fbm_transactions WHERE user_id = %d ORDER BY ym DESC",
        $user_id
    ) );
}

function fbm_get_history_filter_options( $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'fbm_transactions';

    return array(
        'types' => $wpdb->get_results( $wpdb->prepare(
            "SELECT type, COUNT(*) AS total FROM $table WHERE user_id = %d GROUP BY type ORDER BY type",
            $user_id
        ) ),
        'categories' => $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT category FROM $table WHERE user_id = %d AND category <> '' ORDER BY category ASC",
            $user_id
        ) ),
        'days' => $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT DATE(created_at) AS d FROM $table WHERE user_id = %d ORDER BY d DESC",
            $user_id
        ) ),
        'months' => $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT DATE_FORMAT(created_at, '%%Y-%%m') AS ym FROM $table WHERE user_id = %d ORDER BY ym DESC",
            $user_id
        ) ),
        'years' => $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT YEAR(created_at) AS y FROM $table WHERE user_id = %d ORDER BY y DESC",
            $user_id
        ) ),
    );
}

function fbm_get_filtered_transactions( $user_id ) {
    global $wpdb;
    $where = array( 'user_id = %d' );
    $args  = array( $user_id );

    $type = isset( $_GET['fbm_type'] ) ? sanitize_text_field( $_GET['fbm_type'] ) : 'all';
    if ( in_array( $type, array( 'income', 'expense' ), true ) ) {
        $where[] = 'type = %s';
        $args[]  = $type;
    }

    $category = isset( $_GET['fbm_category'] ) ? sanitize_text_field( $_GET['fbm_category'] ) : '';
    if ( $category !== '' ) {
        $where[] = 'category = %s';
        $args[]  = $category;
    }

    $period = isset( $_GET['fbm_period'] ) ? sanitize_text_field( $_GET['fbm_period'] ) : 'month';
    if ( $period === 'day' && ! empty( $_GET['fbm_day'] ) ) {
        $day = sanitize_text_field( $_GET['fbm_day'] );
        $where[] = 'DATE(created_at) = %s';
        $args[]  = $day;
    } elseif ( $period === 'year' && ! empty( $_GET['fbm_year'] ) ) {
        $year = absint( $_GET['fbm_year'] );
        $where[] = 'YEAR(created_at) = %d';
        $args[]  = $year;
    } elseif ( $period === 'all' || isset( $_GET['fbm_expand_history'] ) ) {
        // без обмеження по даті
    } else {
        $month = ! empty( $_GET['fbm_month'] ) ? sanitize_text_field( $_GET['fbm_month'] ) : current_time( 'Y-m' );
        $where[] = "DATE_FORMAT(created_at, '%%Y-%%m') = %s";
        $args[]  = $month;
    }

    $sql = "SELECT * FROM {$wpdb->prefix}fbm_transactions WHERE " . implode( ' AND ', $where ) . " ORDER BY created_at DESC";
    if ( ! isset( $_GET['fbm_expand_history'] ) && $period !== 'all' ) {
        $sql .= ' LIMIT 50';
    }
    return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
}

// ============================================
// 12. ФРОНТЕНД - КАБІНЕТ
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
    $real_user_id = get_current_user_id();
    $user_id = fbm_get_budget_owner_id( $real_user_id );
    $user = get_userdata( $real_user_id );

    if ( isset( $_POST['fbm_clear_history'] ) && check_admin_referer( 'fbm_clear_history' ) ) {
        $wpdb->delete( $wpdb->prefix . 'fbm_transactions', array( 'user_id' => $user_id ) );
        $wpdb->delete( $wpdb->prefix . 'fbm_utilities', array( 'user_id' => $user_id ) );
        echo '<div style="background:#c6f6d5;padding:15px;border-radius:8px;margin:10px 0;">✅ Історію очищено!</div>';
    }

    $transactions = fbm_get_filtered_transactions( $user_id );
    $history_months = fbm_get_history_months( $user_id );
    $history_filter_options = fbm_get_history_filter_options( $user_id );

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

    $services = array( 'Електроенергія', 'Водопостачання', 'Газ', 'Інтернет' );
    foreach ( $services as $s ) {
        if ( empty( $saved_accounts[ $s ] ) ) {
            $meta = get_user_meta( $user_id, 'fbm_default_account_' . sanitize_key( $s ), true );
            if ( ! empty( $meta ) ) {
                $obj = new stdClass();
                $obj->service_name = $s;
                $obj->provider = $meta['provider'] ?? '';
                $obj->personal_account = $meta['personal_account'] ?? '';
                $obj->tariff = $meta['tariff'] ?? 0;
                $saved_accounts[ $s ] = $obj;
            }
        }
    }

    $saved_phones = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fbm_phones WHERE user_id = %d ORDER BY id",
        $user_id
    ) );

    $last_meters = array();
    $services_util = array( 'Електроенергія', 'Водопостачання', 'Газ' );
    foreach ( $services_util as $s ) {
        $last = $wpdb->get_row( $wpdb->prepare(
            "SELECT meter_current FROM {$wpdb->prefix}fbm_utilities WHERE user_id = %d AND service_name = %s ORDER BY created_at DESC LIMIT 1",
            $user_id, $s
        ) );
        if ( $last ) {
            $last_meters[ $s ] = $last;
        }
    }

    $categories = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}fbm_categories WHERE user_id = 0 OR user_id = %d ORDER BY type, name",
        $user_id
    ) );

    // ============================================
    // ОБРОБКА ПЛАТЕЖУ
    // ============================================
    if ( isset( $_POST['fbm_pay_all'] ) ) {
        $payment_items = array();
        $total_amount = 0;
        $payment_descriptions = array();
        
        // Електроенергія
        if ( ! empty( $_POST['pay_elec_meter'] ) && ! empty( $_POST['pay_elec_amount'] ) ) {
            $amount = floatval( str_replace( ',', '.', $_POST['pay_elec_amount'] ) );
            if ( $amount > 0 && isset( $saved_accounts['Електроенергія'] ) && ! empty( $saved_accounts['Електроенергія']->personal_account ) ) {
                $payment_items[] = array(
                    'service' => 'Електроенергія',
                    'account' => $saved_accounts['Електроенергія']->personal_account,
                    'amount' => $amount,
                    'meter' => sanitize_text_field( $_POST['pay_elec_meter'] ),
                    'provider' => ''
                );
                $total_amount += $amount;
                $payment_descriptions[] = '⚡ Електроенергія: ' . number_format( $amount, 2 ) . ' грн';
            }
        }
        
        // Водопостачання
        if ( ! empty( $_POST['pay_water_meter'] ) && ! empty( $_POST['pay_water_amount'] ) ) {
            $amount = floatval( str_replace( ',', '.', $_POST['pay_water_amount'] ) );
            if ( $amount > 0 && isset( $saved_accounts['Водопостачання'] ) && ! empty( $saved_accounts['Водопостачання']->personal_account ) ) {
                $payment_items[] = array(
                    'service' => 'Водопостачання',
                    'account' => $saved_accounts['Водопостачання']->personal_account,
                    'amount' => $amount,
                    'meter' => sanitize_text_field( $_POST['pay_water_meter'] ),
                    'provider' => ''
                );
                $total_amount += $amount;
                $payment_descriptions[] = '💧 Водопостачання: ' . number_format( $amount, 2 ) . ' грн';
            }
        }
        
        // Газ
        if ( ! empty( $_POST['pay_gas_meter'] ) && ! empty( $_POST['pay_gas_amount'] ) ) {
            $amount = floatval( str_replace( ',', '.', $_POST['pay_gas_amount'] ) );
            if ( $amount > 0 && isset( $saved_accounts['Газ'] ) && ! empty( $saved_accounts['Газ']->personal_account ) ) {
                $payment_items[] = array(
                    'service' => 'Газ',
                    'account' => $saved_accounts['Газ']->personal_account,
                    'amount' => $amount,
                    'meter' => sanitize_text_field( $_POST['pay_gas_meter'] ),
                    'provider' => ''
                );
                $total_amount += $amount;
                $payment_descriptions[] = '🔥 Газ: ' . number_format( $amount, 2 ) . ' грн';
            }
        }
        
        // Інтернет
        if ( ! empty( $_POST['pay_internet_amount'] ) ) {
            $amount = floatval( str_replace( ',', '.', $_POST['pay_internet_amount'] ) );
            if ( $amount > 0 && isset( $saved_accounts['Інтернет'] ) && ! empty( $saved_accounts['Інтернет']->personal_account ) ) {
                $payment_items[] = array(
                    'service' => 'Інтернет',
                    'account' => $saved_accounts['Інтернет']->personal_account,
                    'amount' => $amount,
                    'provider' => $saved_accounts['Інтернет']->provider ?? '',
                    'meter' => ''
                );
                $total_amount += $amount;
                $payment_descriptions[] = '🌐 Інтернет: ' . number_format( $amount, 2 ) . ' грн';
            }
        }
        
        // Мобільний (ВИПРАВЛЕНО - правильне форматування номера)
        if ( isset( $_POST['mobile_phones'] ) && is_array( $_POST['mobile_phones'] ) ) {
            foreach ( $_POST['mobile_phones'] as $key => $phone_data ) {
                if ( ! empty( $phone_data['phone'] ) ) {
                    $amount = floatval( str_replace( ',', '.', $phone_data['amount'] ) );
                    if ( $amount > 0 ) {
                        // ПРАВИЛЬНЕ ФОРМАТУВАННЯ НОМЕРА ДЛЯ iPAY
                        $phone = preg_replace('/[^0-9]/', '', $phone_data['phone']);
                        // Якщо номер починається з 0, додаємо 38
                        if ( strpos( $phone, '0' ) === 0 ) {
                            $phone = '38' . $phone;
                        }
                        // Якщо номер починається з +38, прибираємо +
                        if ( strpos( $phone, '+38' ) === 0 ) {
                            $phone = substr( $phone, 1 );
                        }
                        // Якщо номер має 10 цифр і не починається з 380, додаємо 38
                        if ( strpos( $phone, '380' ) !== 0 && strlen( $phone ) === 10 ) {
                            $phone = '38' . $phone;
                        }
                        // Якщо номер має 12 цифр і починається з 380 - залишаємо
                        
                        $operator = sanitize_text_field( $phone_data['operator'] ?? '' );
                        if ( empty( $operator ) ) {
                            $operator = fbm_detect_operator( $phone_data['phone'] );
                        }
                        $payment_items[] = array(
                            'service' => 'Мобільний',
                            'account' => $phone,
                            'amount' => $amount,
                            'provider' => $operator,
                            'meter' => ''
                        );
                        $total_amount += $amount;
                        $payment_descriptions[] = '📱 Мобільний (' . $phone_data['phone'] . '): ' . number_format( $amount, 2 ) . ' грн';
                    }
                }
            }
        }
        
        if ( $total_amount > 0 ) {
            $utility_ids = array();
            foreach ( $payment_items as $item ) {
                $wpdb->insert( $wpdb->prefix . 'fbm_utilities', array(
                    'user_id' => $user_id,
                    'service_name' => $item['service'],
                    'provider' => $item['provider'] ?? '',
                    'personal_account' => $item['account'],
                    'meter_current' => $item['meter'] ?? '',
                    'amount' => $item['amount'],
                    'status' => 'pending'
                ) );
                $utility_ids[] = $wpdb->insert_id;
            }
            
            $return_url = add_query_arg( 'confirm_payment_all', implode( ',', $utility_ids ), home_url( '/my-budget/' ) );
            $first_item = $payment_items[0];
            $link = fbm_generate_ipay_link( $first_item['service'], $first_item['provider'] ?? '', $first_item['account'], $total_amount, $return_url );
            
            echo '<div style="max-width:800px;margin:30px auto;padding:20px;background:#f0f8ff;border-radius:8px;border:2px solid #46b450;">';
            echo '<h3>💳 Платіж сформовано!</h3>';
            echo '<ul style="list-style:none;padding:0;">';
            foreach ( $payment_descriptions as $desc ) {
                echo '<li>• ' . $desc . '</li>';
            }
            echo '</ul>';
            echo '<p style="font-size:28px;font-weight:bold;color:#d63638;">Загальна сума: ' . number_format( $total_amount, 2 ) . ' грн</p>';
            echo '<p><a href="' . esc_url( $link ) . '" target="_blank" class="btn btn-success" style="display:inline-block;padding:14px 40px;background:#46b450;color:#fff;text-decoration:none;border-radius:4px;font-size:18px;font-weight:bold;">💳 Перейти до оплати</a></p>';
            echo '<p><a href="' . home_url( '/my-budget/' ) . '" class="btn btn-secondary" style="display:inline-block;padding:8px 20px;background:#f1f1f1;color:#333;text-decoration:none;border-radius:4px;">🔙 Повернутись</a></p>';
            echo '</div>';
            return ob_get_clean();
        } else {
            echo '<div style="max-width:800px;margin:30px auto;padding:20px;background:#ffebee;border-radius:8px;border:2px solid #f44336;">
                <p>❌ Не вибрано жодного платежу або сума = 0. Будь ласка, заповніть поля для оплати.</p>
                <p><a href="' . home_url( '/my-budget/' ) . '" class="btn btn-secondary" style="display:inline-block;padding:8px 20px;background:#f1f1f1;color:#333;text-decoration:none;border-radius:4px;">🔙 Повернутись</a></p>
            </div>';
            return ob_get_clean();
        }
    }
    
    // Підтвердження загального платежу
    if ( isset( $_GET['confirm_payment_all'] ) ) {
        $ids = explode( ',', $_GET['confirm_payment_all'] );
        foreach ( $ids as $id ) {
            $util = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fbm_utilities WHERE id = %d AND user_id = %d AND status = 'pending'",
                $id, $user_id
            ) );
            if ( $util ) {
                $wpdb->update(
                    $wpdb->prefix . 'fbm_utilities',
                    array( 'status' => 'paid', 'paid_at' => current_time( 'mysql' ) ),
                    array( 'id' => $id )
                );
                $wpdb->insert( $wpdb->prefix . 'fbm_transactions', array(
                    'user_id' => $user_id,
                    'type' => 'expense',
                    'amount' => $util->amount,
                    'category' => $util->service_name . ( $util->provider ? ' (' . $util->provider . ')' : '' ),
                    'description' => 'Оплата через агрегатор',
                    'status' => 'completed'
                ) );
                fbm_send_telegram( $user_id, '✅ Платіж сплачено: ' . $util->service_name . ' - ' . $util->amount . ' грн' );
            }
        }
        echo '<div style="background:#c6f6d5;padding:15px;border-radius:8px;border:2px solid #46b450;margin:15px 0;">✅ Всі платежі підтверджено як сплачено!</div>';
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
        .fbm-calc { background:#f0f8ff; padding:15px; border-radius:8px; margin:10px 0; text-align:center; border:1px solid #b8d4e8; }
        .fbm-calc .diff { font-size:18px; font-weight:bold; color:#2271b1; }
        .fbm-calc .amount { font-size:28px; font-weight:bold; color:#d63638; }
        .fbm-nav { display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap; }
        .fbm-nav a { padding:8px 16px; background:#f1f1f1; border-radius:4px; text-decoration:none; color:#333; }
        .fbm-nav a.active { background:#2271b1; color:#fff; }
        .fbm-info-box { background:#f5f5f5; padding:8px 12px; border-radius:4px; margin-bottom:10px; font-size:13px; color:#333; }
        .fbm-info-box strong { color:#2271b1; }
        .fbm-prev-box { background:#e8f5e9; padding:10px; border-radius:4px; margin-bottom:10px; border-left:3px solid #4caf50; }
        .fbm-prev-box .prev-value { font-size:18px; font-weight:bold; color:#2271b1; }
        .fbm-prev-box small { color:#999; }
        .fbm-phone-entry { display:flex; gap:10px; align-items:center; margin-bottom:8px; flex-wrap:wrap; background:#f9f9f9; padding:8px; border-radius:4px; }
        .fbm-phone-entry select, .fbm-phone-entry input { flex:1; min-width:120px; }
        .fbm-phone-entry .btn-remove-phone { background:#ffebee; border:1px solid #f44336; color:#f44336; padding:4px 8px; border-radius:4px; cursor:pointer; }
        .fbm-message { padding:10px; border-radius:4px; margin:10px 0; border-left:4px solid #4caf50; background:#c6f6d5; color:#1b5e20; }
        @media (max-width:768px) {
            .fbm-stats { grid-template-columns:1fr; }
            .fbm-grid { grid-template-columns:1fr; }
            .fbm-table { font-size:12px; }
            .fbm-table th, .fbm-table td { padding:6px; }
            .fbm-phone-entry { flex-direction:column; }
            .fbm-phone-entry select, .fbm-phone-entry input { width:100%; min-width:auto; }
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
            <form method="post" style="display:inline;" onsubmit="return confirm('⚠️ Ви впевнені, що хочете видалити ВСЮ історію? Це НЕ МОЖНА буде відновити.');">
                <?php wp_nonce_field( 'fbm_clear_history' ); ?>
                <input type="submit" name="fbm_clear_history" class="btn btn-danger" value="🗑️ Очистити історію">
            </form>
        </div>

        <div class="fbm-stats">
            <div class="fbm-stat">
                <div class="label">💵 Баланс</div>
                <div class="num" id="fbm_balance" style="color:<?php echo ($income - $expense) >= 0 ? '#4caf50' : '#f44336'; ?>;">
                    <?php echo number_format( $income - $expense, 2 ); ?> грн
                </div>
            </div>
            <div class="fbm-stat">
                <div class="label">📈 Доходи</div>
                <div class="num" id="fbm_income">+<?php echo number_format( $income, 2 ); ?> грн</div>
            </div>
            <div class="fbm-stat">
                <div class="label">📉 Витрати</div>
                <div class="num" id="fbm_expense">-<?php echo number_format( $expense, 2 ); ?> грн</div>
            </div>
        </div>

        <!-- РУЧНЕ ДОДАВАННЯ -->
        <div class="fbm-card" id="fbm_manual_add_wrapper">
            <h3>➕ Додати транзакцію вручну</h3>
            <form method="post" id="fbm_manual_add_form">
                <?php wp_nonce_field( 'fbm_fe_add', 'fbm_nonce' ); ?>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <select name="type" id="fbm_transaction_type" required style="padding:10px;border:1px solid #ddd;border-radius:4px;">
                        <option value="income">💰 Дохід</option>
                        <option value="expense">🛒 Витрата</option>
                    </select>
                    <input type="number" step="0.01" name="amount" placeholder="Сума" required style="padding:10px;border:1px solid #ddd;border-radius:4px;">
                    <select name="category" id="fbm_transaction_category" required style="padding:10px;border:1px solid #ddd;border-radius:4px;">
                        <option value="">Оберіть категорію</option>
                        <?php 
                        $current_type = '';
                        foreach ( $categories as $cat ) {
                            if ( $current_type !== $cat->type ) {
                                if ( $current_type !== '' ) { echo '</optgroup>'; }
                                $current_type = $cat->type;
                                echo '<optgroup data-type="' . esc_attr( $current_type ) . '" label="' . ( $current_type == 'income' ? '💚 Доходи' : '🔴 Витрати' ) . '">';
                            }
                            echo '<option data-type="' . esc_attr( $cat->type ) . '" value="' . esc_attr( $cat->name ) . '">' . esc_html( $cat->name ) . ( $cat->user_id == 0 ? ' (за замовч.)' : '' ) . '</option>';
                        }
                        if ( $current_type !== '' ) { echo '</optgroup>'; }
                        ?>
                    </select>
                    <textarea name="description" placeholder="Опис (необов'язково)" style="padding:10px;border:1px solid #ddd;border-radius:4px;"></textarea>
                </div>
                <input type="submit" name="fbm_fe_add" class="btn btn-primary" value="💾 Зберегти" style="margin-top:10px;padding:10px 20px;border:none;border-radius:4px;cursor:pointer;background:#2271b1;color:#fff;font-size:14px;">
            </form>
        </div>

        <!-- ФОРМА ПЛАТЕЖІВ -->
        <form method="post" id="fbm_payment_form">
            <div class="fbm-grid">
                <!-- ЕЛЕКТРОЕНЕРГІЯ -->
                <div class="fbm-card">
                    <h3>⚡ Електроенергія</h3>
                    <div class="fbm-info-box">
                        <strong>Особовий рахунок:</strong>
                        <?php if ( isset( $saved_accounts['Електроенергія'] ) && ! empty( $saved_accounts['Електроенергія']->personal_account ) ) : ?>
                            <?php echo esc_html( $saved_accounts['Електроенергія']->personal_account ); ?>
                            <br><strong>Тариф:</strong> <?php echo number_format( $saved_accounts['Електроенергія']->tariff, 2 ); ?> грн
                        <?php else : ?>
                            <span style="color:#f44336;">не вказано → <a href="/my-settings/">налаштувати</a></span>
                        <?php endif; ?>
                    </div>

                    <div class="fbm-prev-box">
                        <label style="font-size:13px;color:#555;font-weight:bold;">📊 Попередній показник:</label>
                        <div class="prev-value" id="fbm_prev_meter_elec">
                            <?php echo isset( $last_meters['Електроенергія'] ) ? esc_html( $last_meters['Електроенергія']->meter_current ) : '0'; ?>
                        </div>
                        <small>з останньої оплати</small>
                    </div>

                    <input type="hidden" id="fbm_tariff_elec" value="<?php echo isset( $saved_accounts['Електроенергія'] ) ? esc_attr( $saved_accounts['Електроенергія']->tariff ) : 0; ?>">
                    
                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">📊 Поточний показник</label>
                    <input type="text" name="pay_elec_meter" placeholder="Введіть поточний показник" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;margin-bottom:10px;box-sizing:border-box;" id="fbm_meter_elec" class="fbm_meter_input">
                    
                    <div class="fbm-calc">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div>
                                <small style="color:#666;">📉 Різниця</small>
                                <div class="diff" id="fbm_diff_elec">0.00</div>
                            </div>
                            <div>
                                <small style="color:#666;">💵 Сума</small>
                                <div class="amount" id="fbm_amount_elec">0.00 грн</div>
                            </div>
                        </div>
                    </div>

                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">💵 Сума до сплати (можна змінити)</label>
                    <input type="text" step="0.01" name="pay_elec_amount" placeholder="Сума" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;" id="fbm_amount_input_elec">
                </div>

                <!-- ВОДОПОСТАЧАННЯ -->
                <div class="fbm-card">
                    <h3>💧 Водопостачання</h3>
                    <div class="fbm-info-box">
                        <strong>Особовий рахунок:</strong>
                        <?php if ( isset( $saved_accounts['Водопостачання'] ) && ! empty( $saved_accounts['Водопостачання']->personal_account ) ) : ?>
                            <?php echo esc_html( $saved_accounts['Водопостачання']->personal_account ); ?>
                            <br><strong>Тариф:</strong> <?php echo number_format( $saved_accounts['Водопостачання']->tariff, 2 ); ?> грн
                        <?php else : ?>
                            <span style="color:#f44336;">не вказано → <a href="/my-settings/">налаштувати</a></span>
                        <?php endif; ?>
                    </div>

                    <div class="fbm-prev-box">
                        <label style="font-size:13px;color:#555;font-weight:bold;">📊 Попередній показник:</label>
                        <div class="prev-value" id="fbm_prev_meter_water">
                            <?php echo isset( $last_meters['Водопостачання'] ) ? esc_html( $last_meters['Водопостачання']->meter_current ) : '0'; ?>
                        </div>
                        <small>з останньої оплати</small>
                    </div>

                    <input type="hidden" id="fbm_tariff_water" value="<?php echo isset( $saved_accounts['Водопостачання'] ) ? esc_attr( $saved_accounts['Водопостачання']->tariff ) : 0; ?>">
                    
                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">📊 Поточний показник</label>
                    <input type="text" name="pay_water_meter" placeholder="Введіть поточний показник" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;margin-bottom:10px;box-sizing:border-box;" id="fbm_meter_water" class="fbm_meter_input">
                    
                    <div class="fbm-calc">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div>
                                <small style="color:#666;">📉 Різниця</small>
                                <div class="diff" id="fbm_diff_water">0.00</div>
                            </div>
                            <div>
                                <small style="color:#666;">💵 Сума</small>
                                <div class="amount" id="fbm_amount_water">0.00 грн</div>
                            </div>
                        </div>
                    </div>

                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">💵 Сума до сплати (можна змінити)</label>
                    <input type="text" step="0.01" name="pay_water_amount" placeholder="Сума" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;" id="fbm_amount_input_water">
                </div>

                <!-- ГАЗ -->
                <div class="fbm-card">
                    <h3>🔥 Газ</h3>
                    <div class="fbm-info-box">
                        <strong>Особовий рахунок:</strong>
                        <?php if ( isset( $saved_accounts['Газ'] ) && ! empty( $saved_accounts['Газ']->personal_account ) ) : ?>
                            <?php echo esc_html( $saved_accounts['Газ']->personal_account ); ?>
                            <br><strong>Тариф:</strong> <?php echo number_format( $saved_accounts['Газ']->tariff, 2 ); ?> грн
                        <?php else : ?>
                            <span style="color:#f44336;">не вказано → <a href="/my-settings/">налаштувати</a></span>
                        <?php endif; ?>
                    </div>

                    <div class="fbm-prev-box">
                        <label style="font-size:13px;color:#555;font-weight:bold;">📊 Попередній показник:</label>
                        <div class="prev-value" id="fbm_prev_meter_gas">
                            <?php echo isset( $last_meters['Газ'] ) ? esc_html( $last_meters['Газ']->meter_current ) : '0'; ?>
                        </div>
                        <small>з останньої оплати</small>
                    </div>

                    <input type="hidden" id="fbm_tariff_gas" value="<?php echo isset( $saved_accounts['Газ'] ) ? esc_attr( $saved_accounts['Газ']->tariff ) : 0; ?>">
                    
                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">📊 Поточний показник</label>
                    <input type="text" name="pay_gas_meter" placeholder="Введіть поточний показник" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;margin-bottom:10px;box-sizing:border-box;" id="fbm_meter_gas" class="fbm_meter_input">
                    
                    <div class="fbm-calc">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div>
                                <small style="color:#666;">📉 Різниця</small>
                                <div class="diff" id="fbm_diff_gas">0.00</div>
                            </div>
                            <div>
                                <small style="color:#666;">💵 Сума</small>
                                <div class="amount" id="fbm_amount_gas">0.00 грн</div>
                            </div>
                        </div>
                    </div>

                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">💵 Сума до сплати (можна змінити)</label>
                    <input type="text" step="0.01" name="pay_gas_amount" placeholder="Сума" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;" id="fbm_amount_input_gas">
                </div>

                <!-- ІНТЕРНЕТ -->
                <div class="fbm-card">
                    <h3>🌐 Інтернет</h3>
                    <div class="fbm-info-box">
                        <?php if ( isset( $saved_accounts['Інтернет'] ) && ! empty( $saved_accounts['Інтернет']->personal_account ) ) : ?>
                            <strong>Провайдер:</strong> <?php echo esc_html( $saved_accounts['Інтернет']->provider ?: 'не вказано' ); ?><br>
                            <strong>Особовий рахунок:</strong> <?php echo esc_html( $saved_accounts['Інтернет']->personal_account ); ?><br>
                            <strong>Тариф:</strong> <?php echo number_format( $saved_accounts['Інтернет']->tariff, 2 ); ?> грн
                        <?php else : ?>
                            <span style="color:#f44336;">не вказано → <a href="/my-settings/">налаштувати</a></span>
                        <?php endif; ?>
                    </div>

                    <label style="font-size:13px;color:#555;display:block;margin-bottom:4px;">💵 Сума до сплати</label>
                    <input type="text" step="0.01" name="pay_internet_amount" placeholder="Сума до сплати" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;box-sizing:border-box;"
                        value="<?php echo isset( $saved_accounts['Інтернет'] ) && $saved_accounts['Інтернет']->tariff ? esc_attr( $saved_accounts['Інтернет']->tariff ) : ''; ?>">
                </div>
            </div>

            <!-- МОБІЛЬНИЙ -->
            <div class="fbm-card">
                <h3>📱 Мобільний</h3>
                <div id="fbm_mobile_container">
                    <?php if ( $saved_phones ) : ?>
                        <?php foreach ( $saved_phones as $p ) : ?>
                            <div class="fbm-phone-entry">
                                <span style="font-weight:bold;min-width:80px;"><?php echo esc_html( $p->label ?: 'Телефон' ); ?></span>
                                <span><?php echo esc_html( $p->phone ); ?></span>
                                <span style="color:#666;font-size:12px;">(<?php echo esc_html( $p->operator ); ?>)</span>
                                <input type="hidden" name="mobile_phones[<?php echo $p->id; ?>][phone]" value="<?php echo esc_attr( $p->phone ); ?>">
                                <input type="hidden" name="mobile_phones[<?php echo $p->id; ?>][operator]" value="<?php echo esc_attr( $p->operator ); ?>">
                                <input type="text" step="0.01" name="mobile_phones[<?php echo $p->id; ?>][amount]" placeholder="Сума" value="<?php echo $p->tariff > 0 ? esc_attr( $p->tariff ) : ''; ?>" style="flex:1;min-width:100px;padding:8px;border:1px solid #ddd;border-radius:4px;">
                                <button type="button" class="btn-remove-phone" onclick="this.parentElement.remove()" style="background:#ffebee;border:1px solid #f44336;color:#f44336;padding:4px 8px;border-radius:4px;cursor:pointer;">✕</button>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p style="color:#999;font-size:13px;">Немає збережених номерів → <a href="/my-settings/">додати</a></p>
                    <?php endif; ?>
                </div>
                
                <button type="button" onclick="addMobilePhone()" style="margin-top:10px;padding:6px 12px;border:1px solid #4caf50;background:#e8f5e9;border-radius:4px;cursor:pointer;">➕ Додати ще телефон</button>
            </div>

            <!-- ЗАГАЛЬНА КНОПКА -->
            <div style="text-align:center;padding:20px;background:#f0f8ff;border-radius:8px;margin-top:20px;">
                <input type="submit" name="fbm_pay_all" class="btn btn-success" value="💳 Сформувати платіж" style="font-size:18px;padding:15px 40px;border:none;border-radius:4px;cursor:pointer;background:#46b450;color:#fff;">
            </div>
        </form>

        <!-- Історія операцій -->
        <div class="fbm-card">
            <h3>📋 Історія операцій</h3>
            <form method="get" class="fbm-history-filter" style="display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:8px;margin:10px 0 15px;align-items:end;">
                <input type="hidden" name="page_id" value="<?php echo esc_attr( get_queried_object_id() ); ?>">
                <div><label>Тип</label><select name="fbm_type"><option value="all">Все наявне</option><?php foreach ( $history_filter_options['types'] as $ht ) : if ( ! in_array( $ht->type, array( 'income', 'expense' ), true ) ) continue; ?><option value="<?php echo esc_attr( $ht->type ); ?>" <?php selected( $_GET['fbm_type'] ?? '', $ht->type ); ?>><?php echo $ht->type === 'income' ? 'Доходи' : 'Витрати'; ?> (<?php echo intval( $ht->total ); ?>)</option><?php endforeach; ?></select></div>
                <div><label>Категорія</label><select name="fbm_category"><option value="">Усі наявні</option><?php foreach ( $history_filter_options['categories'] as $cat ) : ?><option value="<?php echo esc_attr( $cat->category ); ?>" <?php selected( $_GET['fbm_category'] ?? '', $cat->category ); ?>><?php echo esc_html( $cat->category ); ?></option><?php endforeach; ?></select></div>
                <div><label>Період</label><select name="fbm_period" id="fbm_period_select"><option value="month" <?php selected( $_GET['fbm_period'] ?? 'month', 'month' ); ?>>Місяць</option><?php if ( ! empty( $history_filter_options['days'] ) ) : ?><option value="day" <?php selected( $_GET['fbm_period'] ?? '', 'day' ); ?>>День</option><?php endif; ?><?php if ( ! empty( $history_filter_options['years'] ) ) : ?><option value="year" <?php selected( $_GET['fbm_period'] ?? '', 'year' ); ?>>Рік</option><?php endif; ?><option value="all" <?php selected( $_GET['fbm_period'] ?? '', 'all' ); ?>>Вся історія</option></select></div>
                <div class="fbm-filter-date"><label>Дата/місяць/рік</label><select name="fbm_day"><?php foreach ( $history_filter_options['days'] as $d ) : ?><option value="<?php echo esc_attr( $d->d ); ?>" <?php selected( $_GET['fbm_day'] ?? '', $d->d ); ?>><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $d->d ) ) ); ?></option><?php endforeach; ?></select><select name="fbm_month"><?php foreach ( $history_filter_options['months'] as $m ) : ?><option value="<?php echo esc_attr( $m->ym ); ?>" <?php selected( $_GET['fbm_month'] ?? ( $history_filter_options['months'][0]->ym ?? current_time( 'Y-m' ) ), $m->ym ); ?>><?php echo esc_html( date_i18n( 'm/Y', strtotime( $m->ym . '-01' ) ) ); ?></option><?php endforeach; ?></select><select name="fbm_year"><?php foreach ( $history_filter_options['years'] as $y ) : ?><option value="<?php echo esc_attr( $y->y ); ?>" <?php selected( $_GET['fbm_year'] ?? ( $history_filter_options['years'][0]->y ?? current_time( 'Y' ) ), $y->y ); ?>><?php echo esc_html( $y->y ); ?></option><?php endforeach; ?></select></div>
                <div><button class="btn btn-primary" type="submit" style="width:100%;">🔎 Фільтр</button></div>
            </form>
            <div style="margin-bottom:10px;"><a class="btn btn-secondary" href="<?php echo esc_url( add_query_arg( 'fbm_expand_history', '1' ) ); ?>">↕️ Розгорнути всю історію</a></div>
            <table class="fbm-table" id="fbm_history_table">
                <thead><tr><th>Дата</th><th>Сума</th><th>Категорія</th><th>Статус</th></tr></thead>
                <tbody>
                    <?php if ( $transactions ) : foreach ( $transactions as $t ) : ?>
                    <tr>
                        <td><?php echo date( 'd.m.Y', strtotime( $t->created_at ) ); ?></td>
                        <td style="color:<?php echo $t->type == 'income' ? '#4caf50' : '#f44336'; ?>;font-weight:bold;">
                            <?php echo $t->type == 'income' ? '+' : '-'; ?> <?php echo number_format( $t->amount, 2 ); ?> грн
                        </td>
                        <td><?php echo esc_html( $t->category ); ?></td>
                        <td><?php echo $t->status == 'completed' ? '✅ Сплачено' : '⏳ Очікує'; ?></td>
                    </tr>
                    <?php endforeach; else : ?>
                    <tr><td colspan="4" style="text-align:center;color:#a0aec0;">📭 Немає даних</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Несплачені платежі -->
        <div class="fbm-card">
            <h3>📋 Несплачені платежі</h3>
            <?php 
            $pending_utilities = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fbm_utilities WHERE user_id = %d AND status = 'pending' ORDER BY created_at DESC",
                $user_id
            ) );
            ?>
            <?php if ( $pending_utilities ) : ?>
            <table class="fbm-table">
                <thead><tr><th>Дата</th><th>Послуга</th><th>Сума</th><th>Дія</th></tr></thead>
                <tbody>
                    <?php foreach ( $pending_utilities as $u ) : ?>
                    <tr>
                        <td><?php echo date( 'd.m.Y', strtotime( $u->created_at ) ); ?></td>
                        <td><?php echo esc_html( $u->service_name ) . ( $u->provider ? ' (' . esc_html( $u->provider ) . ')' : '' ); ?></td>
                        <td style="font-weight:bold;"><?php echo number_format( $u->amount, 2 ); ?> грн</td>
                        <td>
                            <?php
                            $return = add_query_arg( 'confirm_payment', $u->id, home_url( '/my-budget/' ) );
                            $link = fbm_generate_ipay_link( $u->service_name, $u->provider, $u->personal_account, $u->amount, $return );
                            ?>
                            <a href="<?php echo esc_url( $link ); ?>" target="_blank" class="btn btn-success" style="padding:4px 8px;font-size:12px;display:inline-block;background:#46b450;color:#fff;text-decoration:none;border-radius:3px;">💳 Сплатити</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p style="text-align:center;color:#a0aec0;">📭 Немає несплачених платежів</p>
            <?php endif; ?>
        </div>
</div>

    <!-- ============================================ -->
    <!-- ВИПРАВЛЕНИЙ JAVASCRIPT -->
    <!-- ============================================ -->
    <script>
    jQuery(document).ready(function($) {
        // --- 1. ВИДАЛЕННЯ ТЕЛЕФОНУ ---
        $(document).on('click', '.btn-remove-phone', function(e) {
            e.preventDefault();
            $(this).closest('.fbm-phone-entry').remove();
            return false;
        });
        
        // --- 2. АВТОВИЗНАЧЕННЯ ОПЕРАТОРА ---
        function detectOperator(phone) {
            var clean = phone.replace(/[^0-9]/g, '');
            if (clean.length < 3) return '';
            var prefix = clean.substring(0, 3);
            var operators = {
                'Київстар': ['067', '068', '096', '097', '098'],
                'Vodafone': ['050', '066', '095', '099'],
                'Lifecell': ['063', '073', '093']
            };
            for (var name in operators) {
                if (operators[name].indexOf(prefix) !== -1) {
                    return name;
                }
            }
            return '';
        }
        
        // --- 3. ДОДАВАННЯ НОВОГО ТЕЛЕФОНУ ---
        window.addMobilePhone = function() {
            var container = $('#fbm_mobile_container');
            var index = Date.now();
            var div = $('<div class="fbm-phone-entry">' +
                '<input type="text" placeholder="Номер (наприклад, 0985555555)" name="mobile_phones[' + index + '][phone]" class="fbm-phone-input" style="flex:1;min-width:120px;padding:8px;border:1px solid #ddd;border-radius:4px;">' +
                '<select name="mobile_phones[' + index + '][operator]" class="fbm-operator-select" style="padding:8px;border:1px solid #ddd;border-radius:4px;">' +
                '<option value="">Авто</option>' +
                '<option value="Київстар">Київстар</option>' +
                '<option value="Vodafone">Vodafone</option>' +
                '<option value="Lifecell">Lifecell</option>' +
                '</select>' +
                '<input type="text" step="0.01" name="mobile_phones[' + index + '][amount]" placeholder="Сума" style="flex:1;min-width:100px;padding:8px;border:1px solid #ddd;border-radius:4px;">' +
                '<button type="button" class="btn-remove-phone" style="background:#ffebee;border:1px solid #f44336;color:#f44336;padding:4px 8px;border-radius:4px;cursor:pointer;font-size:14px;">✕</button>' +
            '</div>');
            container.append(div);
            
            div.find('.fbm-phone-input').on('input', function() {
                var phone = $(this).val();
                var operator = detectOperator(phone);
                if (operator) {
                    $(this).closest('.fbm-phone-entry').find('.fbm-operator-select').val(operator);
                }
            });
        };
        
        // --- 4. АВТОВИЗНАЧЕННЯ ДЛЯ ВЖЕ ІСНУЮЧИХ ---
        $('.fbm-phone-entry').each(function() {
            var entry = $(this);
            var phoneInput = entry.find('input[name*="[phone]"]');
            var operatorSelect = entry.find('select[name*="[operator]"]');
            
            if (phoneInput.length > 0 && operatorSelect.length > 0) {
                if (!operatorSelect.val()) {
                    var phone = phoneInput.val();
                    var operator = detectOperator(phone);
                    if (operator) {
                        operatorSelect.val(operator);
                    }
                }
                phoneInput.on('input', function() {
                    var phone = $(this).val();
                    var operator = detectOperator(phone);
                    if (operator) {
                        $(this).closest('.fbm-phone-entry').find('.fbm-operator-select').val(operator);
                    }
                });
            }
        });
        
        // --- 5. РУЧНЕ ДОДАВАННЯ ТРАНЗАКЦІЙ ---
        $('#fbm_manual_add_form').on('submit', function(e) {
            e.preventDefault();
            var form = $(this);
            var submitBtn = form.find('input[type="submit"]');
            var originalText = submitBtn.val();
            submitBtn.prop('disabled', true).val('⏳ Обробка...');
            
            var data = {
                action: 'fbm_manual_add',
                nonce: $('input[name="fbm_nonce"]').val(),
                type: form.find('select[name="type"]').val(),
                amount: form.find('input[name="amount"]').val(),
                category: form.find('select[name="category"]').val(),
                description: form.find('textarea[name="description"]').val()
            };
            
            $.post('<?php echo admin_url("admin-ajax.php"); ?>', data, function(response) {
                submitBtn.prop('disabled', false).val(originalText);
                if (response.success) {
                    $('#fbm_balance').text(response.data.balance + ' грн').css('color', response.data.balance_color);
                    $('#fbm_income').text('+' + response.data.income + ' грн');
                    $('#fbm_expense').text('-' + response.data.expense + ' грн');
                    $('#fbm_history_table tbody').html(response.data.history_html);
                    form.find('input[type="number"]').val('');
                    form.find('select[name="category"]').val('');
                    form.find('textarea').val('');
                    var msg = $('<div class="fbm-message">✅ Транзакцію додано!</div>');
                    $('.fbm-message').remove();
                    $('#fbm_manual_add_wrapper').before(msg);
                    setTimeout(function() { msg.fadeOut('slow', function() { $(this).remove(); }); }, 5000);
                } else {
                    var msg = $('<div style="padding:10px;border-radius:4px;margin:10px 0;border-left:4px solid #f44336;background:#ffebee;color:#c62828;">❌ Помилка: ' + (response.data || 'невідома помилка') + '</div>');
                    $('.fbm-message').remove();
                    $('#fbm_manual_add_wrapper').before(msg);
                }
            }).fail(function() {
                submitBtn.prop('disabled', false).val(originalText);
                var msg = $('<div style="padding:10px;border-radius:4px;margin:10px 0;border-left:4px solid #f44336;background:#ffebee;color:#c62828;">❌ Помилка з\'єднання з сервером</div>');
                $('.fbm-message').remove();
                $('#fbm_manual_add_wrapper').before(msg);
            });
        });
        
        // --- 6. РОЗРАХУНОК КОМУНАЛКИ ---
        function calculateUtility(field) {
            var prevVal = $('#fbm_prev_meter_' + field).text().trim() || '0';
            var prev = parseFloat(prevVal) || 0;
            var current = parseFloat($('#fbm_meter_' + field).val()) || 0;
            var tariff = parseFloat($('#fbm_tariff_' + field).val()) || 0;
            
            var diff = 0;
            var sum = 0;
            if (current > 0) {
                diff = current - prev;
                if (diff < 0) diff = 0;
                sum = diff * tariff;
            }
            
            $('#fbm_diff_' + field).text(diff.toFixed(2));
            $('#fbm_amount_' + field).text(sum.toFixed(2) + ' грн');
            if (sum > 0) {
                $('#fbm_amount_' + field).css('color', '#d63638');
            } else {
                $('#fbm_amount_' + field).css('color', '#999');
            }
            $('#fbm_amount_input_' + field).val(sum.toFixed(2));
        }
        
        ['elec', 'water', 'gas'].forEach(function(field) {
            var meter = $('#fbm_meter_' + field);
            if (meter.length > 0) {
                meter.on('input', function() {
                    calculateUtility(field);
                });
                calculateUtility(field);
            }
        });
        
        // --- 6.5. Категорії: показуємо тільки категорії вибраного типу ---
        (function(){
            var typeSelect = document.getElementById('fbm_transaction_type');
            var categorySelect = document.getElementById('fbm_transaction_category');
            if (!typeSelect || !categorySelect) return;
            function filterCategories(){
                var active = typeSelect.value || 'income';
                Array.prototype.forEach.call(categorySelect.options, function(opt){
                    if (!opt.value) { opt.hidden = false; return; }
                    opt.hidden = opt.getAttribute('data-type') !== active;
                });
                Array.prototype.forEach.call(categorySelect.querySelectorAll('optgroup'), function(group){
                    group.hidden = group.getAttribute('data-type') !== active;
                });
                if (categorySelect.selectedOptions[0] && categorySelect.selectedOptions[0].hidden) {
                    categorySelect.value = '';
                }
            }
            typeSelect.addEventListener('change', filterCategories);
            filterCategories();
        })();

        // --- 7. PWA ---
        var deferredPrompt;
        var installBtn = document.getElementById('fbm_install_app');
        var hint = document.getElementById('fbm_install_hint');
        if (installBtn) {
            installBtn.style.display = 'inline-block';
        }
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('<?php echo esc_js( home_url( '/?fbm_sw=1' ) ); ?>').catch(function(err){
                console.warn('FBM service worker registration failed', err);
            });
        }
        window.addEventListener('beforeinstallprompt', function(e) {
            e.preventDefault();
            deferredPrompt = e;
            if (hint) { hint.textContent = 'Натисніть кнопку, щоб встановити кабінет як додаток.'; }
        });
        installBtn?.addEventListener('click', function() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.finally(function() {
                    deferredPrompt = null;
                });
                return;
            }
            var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
            if (hint) {
                hint.innerHTML = isIOS
                    ? 'На iPhone/iPad: натисніть <strong>Поділитися</strong> → <strong>На екран “Додому”</strong>.'
                    : 'Якщо вікно встановлення не з\'явилось: відкрийте меню браузера ⋮ → <strong>Додати на головний екран</strong> або <strong>Встановити додаток</strong>.';
            }
        });
    });
    </script>
    <?php
}

// ============================================
// 13. AJAX ОБРОБКА
// ============================================
add_action( 'wp_ajax_fbm_manual_add', 'fbm_ajax_manual_add' );
function fbm_ajax_manual_add() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Неавторизований' );
    }

    if ( ! wp_verify_nonce( $_POST['nonce'], 'fbm_fe_add' ) ) {
        wp_send_json_error( 'Помилка безпеки' );
    }

    global $wpdb;
    $user_id = fbm_get_budget_owner_id( get_current_user_id() );

    $type = sanitize_text_field( $_POST['type'] );
    $amount = floatval( str_replace( ',', '.', $_POST['amount'] ) );
    $category = sanitize_text_field( $_POST['category'] );
    $description = sanitize_textarea_field( $_POST['description'] );

    $wpdb->insert(
        $wpdb->prefix . 'fbm_transactions',
        array(
            'user_id' => $user_id,
            'type' => $type,
            'amount' => $amount,
            'category' => $category,
            'description' => $description,
            'status' => 'completed',
            'created_at' => current_time( 'mysql' )
        )
    );

    $table = $wpdb->prefix . 'fbm_transactions';
    $income = $wpdb->get_var( $wpdb->prepare(
        "SELECT SUM(amount) FROM $table WHERE user_id = %d AND type = 'income' AND status = 'completed'",
        $user_id
    ) ) ?: 0;

    $expense = $wpdb->get_var( $wpdb->prepare(
        "SELECT SUM(amount) FROM $table WHERE user_id = %d AND type = 'expense' AND status = 'completed'",
        $user_id
    ) ) ?: 0;

    $balance = $income - $expense;

    $transactions = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $table WHERE user_id = %d ORDER BY created_at DESC LIMIT 20",
        $user_id
    ) );

    $history_html = '';
    if ( $transactions ) {
        foreach ( $transactions as $t ) {
            $history_html .= '<tr>
                <td>' . date( 'd.m.Y', strtotime( $t->created_at ) ) . '</td>
                <td style="color:' . ( $t->type == 'income' ? '#4caf50' : '#f44336' ) . ';font-weight:bold;">
                    ' . ( $t->type == 'income' ? '+' : '-' ) . ' ' . number_format( $t->amount, 2 ) . ' грн
                </td>
                <td>' . esc_html( $t->category ) . '</td>
                <td>' . ( $t->status == 'completed' ? '✅ Сплачено' : '⏳ Очікує' ) . '</td>
            </tr>';
        }
    } else {
        $history_html = '<tr><td colspan="4" style="text-align:center;color:#a0aec0;">📭 Немає даних</td></tr>';
    }

    wp_send_json_success( array(
        'income' => number_format( $income, 2 ),
        'expense' => number_format( $expense, 2 ),
        'balance' => number_format( $balance, 2 ),
        'balance_color' => $balance >= 0 ? '#4caf50' : '#f44336',
        'history_html' => $history_html,
        'message' => '✅ Транзакцію додано!'
    ) );
}

// ============================================
// 14. СТВОРЕННЯ СТОРІНОК
// ============================================
register_activation_hook( __FILE__, 'fbm_create_all_pages' );
function fbm_create_all_pages() {
    fbm_activate();
    $pages = array(
        array( 'title' => 'Мій бюджет',        'content' => '[fbm_dashboard]',        'slug' => 'my-budget' ),
        array( 'title' => 'Налаштування',       'content' => '[fbm_settings]',         'slug' => 'my-settings' ),
        array( 'title' => 'Вхід',               'content' => '[fbm_login]',            'slug' => 'login' ),
        array( 'title' => 'Реєстрація',         'content' => '[fbm_register]',         'slug' => 'register' ),
        array( 'title' => 'Забули пароль',      'content' => '[fbm_forgot_password]',  'slug' => 'forgot-password' ),
        array( 'title' => 'Мій профіль',        'content' => '[fbm_profile]',          'slug' => 'my-profile' )
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
// 14.05. PORTMONE DIRECT / PAYMENT GATEWAY SETTINGS
// ============================================
function fbm_portmone_settings_page() {
    if ( isset( $_POST['fbm_save_portmone'] ) && check_admin_referer( 'fbm_portmone_settings' ) ) {
        update_option( 'fbm_portmone_mode', sanitize_text_field( $_POST['mode'] ?? 'sandbox' ) );
        update_option( 'fbm_portmone_direct_login', sanitize_text_field( $_POST['direct_login'] ?? '' ) );
        update_option( 'fbm_portmone_direct_password', sanitize_text_field( $_POST['direct_password'] ?? '' ) );
        update_option( 'fbm_portmone_gateway_login', sanitize_text_field( $_POST['gateway_login'] ?? '' ) );
        update_option( 'fbm_portmone_gateway_password', sanitize_text_field( $_POST['gateway_password'] ?? '' ) );
        update_option( 'fbm_portmone_gateway_token', sanitize_text_field( $_POST['gateway_token'] ?? '' ) );
        echo '<div class="updated"><p>✅ Portmone налаштування збережено.</p></div>';
    }
    if ( ! get_option( 'fbm_portmone_direct_login' ) ) update_option( 'fbm_portmone_direct_login', 'PortmoneDirectTest' );
    if ( ! get_option( 'fbm_portmone_direct_password' ) ) update_option( 'fbm_portmone_direct_password', 'PortmoneDirect' );
    if ( ! get_option( 'fbm_portmone_gateway_login' ) ) update_option( 'fbm_portmone_gateway_login', 'NEWGORIN' );
    if ( ! get_option( 'fbm_portmone_gateway_password' ) ) update_option( 'fbm_portmone_gateway_password', 'g123456' );
    ?>
    <div class="wrap"><h1>💳 Portmone <span style="font-size:13px;background:#2271b1;color:#fff;padding:4px 8px;border-radius:12px;">v<?php echo esc_html( FBM_VERSION ); ?></span></h1>
        <div class="notice notice-warning"><p><strong>Тестовий режим:</strong> зараз модуль готовий під PortmoneDirect/PaymentGateway, але реальні платежі не запускаємо до підписання договору й production-доступів.</p></div>
        <form method="post" class="card" style="padding:20px;max-width:900px;">
            <?php wp_nonce_field( 'fbm_portmone_settings' ); ?>
            <table class="form-table">
                <tr><th>Режим</th><td><select name="mode"><option value="sandbox" <?php selected( get_option('fbm_portmone_mode','sandbox'), 'sandbox' ); ?>>Sandbox / тест</option><option value="production" <?php selected( get_option('fbm_portmone_mode'), 'production' ); ?>>Production</option></select></td></tr>
                <tr><th>PortmoneDirect login</th><td><input class="regular-text" name="direct_login" value="<?php echo esc_attr( get_option('fbm_portmone_direct_login') ); ?>"></td></tr>
                <tr><th>PortmoneDirect password</th><td><input class="regular-text" type="password" name="direct_password" value="<?php echo esc_attr( get_option('fbm_portmone_direct_password') ); ?>"></td></tr>
                <tr><th>Gateway login</th><td><input class="regular-text" name="gateway_login" value="<?php echo esc_attr( get_option('fbm_portmone_gateway_login') ); ?>"></td></tr>
                <tr><th>Gateway password</th><td><input class="regular-text" type="password" name="gateway_password" value="<?php echo esc_attr( get_option('fbm_portmone_gateway_password') ); ?>"></td></tr>
                <tr><th>Gateway token</th><td><input class="regular-text" name="gateway_token" value="<?php echo esc_attr( get_option('fbm_portmone_gateway_token') ); ?>"><p class="description">Якщо Portmone надасть окремий token для масової оплати billId.</p></td></tr>
            </table>
            <p><button class="button button-primary" name="fbm_save_portmone">💾 Зберегти</button></p>
        </form>
        <h2>Поточна схема інтеграції</h2>
        <ol>
            <li>Користувач додає адресу/особові рахунки в налаштуваннях.</li>
            <li>PortmoneDirect шукає та створює billId.</li>
            <li>Family Budget зберігає billId як очікує оплату.</li>
            <li>PaymentGateway відкриває оплату карткою / Google Pay / Apple Pay.</li>
            <li>Після callback/webhook статус змінюється на «сплачено».</li>
        </ol>
    </div>
    <?php
}

function fbm_portmone_direct_request( $method, $params = array() ) {
    $body = array_merge( array(
        'method'   => $method,
        'login'    => get_option( 'fbm_portmone_direct_login', 'PortmoneDirectTest' ),
        'password' => get_option( 'fbm_portmone_direct_password', 'PortmoneDirect' ),
        'version'  => '2',
        'lang'     => 'uk',
    ), $params );
    $response = wp_remote_post( 'https://direct.portmone.com.ua/api/directcash/', array( 'timeout' => 30, 'body' => $body ) );
    if ( is_wp_error( $response ) ) return $response;
    $xml = simplexml_load_string( wp_remote_retrieve_body( $response ) );
    if ( ! $xml ) return new WP_Error( 'portmone_bad_xml', 'Portmone повернув некоректну XML-відповідь.' );
    return $xml;
}

// ============================================
// 14.1. РЕКЛАМНИЙ БЛОК ТА СТАТИСТИКА
// ============================================
function fbm_ads_settings_page() {
    if ( isset( $_POST['fbm_save_ads'] ) && check_admin_referer( 'fbm_ads_settings' ) ) {
        update_option( 'fbm_ad_enabled', isset( $_POST['ad_enabled'] ) ? 1 : 0 );
        update_option( 'fbm_ad_title', sanitize_text_field( $_POST['ad_title'] ?? '' ) );
        update_option( 'fbm_ad_text', sanitize_textarea_field( $_POST['ad_text'] ?? '' ) );
        update_option( 'fbm_ad_url', esc_url_raw( $_POST['ad_url'] ?? '' ) );
        update_option( 'fbm_ad_image', esc_url_raw( $_POST['ad_image'] ?? '' ) );
        echo '<div class="updated"><p>✅ Рекламний блок збережено.</p></div>';
    }
    if ( isset( $_POST['fbm_reset_ad_stats'] ) && check_admin_referer( 'fbm_ads_settings' ) ) {
        update_option( 'fbm_ad_views', 0 ); update_option( 'fbm_ad_clicks', 0 );
        echo '<div class="updated"><p>✅ Статистику очищено.</p></div>';
    }
    $views=(int)get_option('fbm_ad_views',0); $clicks=(int)get_option('fbm_ad_clicks',0); $users=count_users(); $total_users=(int)($users['total_users'] ?? 0); $ctr=$views>0?round($clicks/$views*100,2):0;
    ?>
    <div class="wrap"><h1>📣 Реклама <span style="font-size:13px;background:#2271b1;color:#fff;padding:4px 8px;border-radius:12px;">v<?php echo esc_html(FBM_VERSION); ?></span></h1>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:15px 0;"><div class="card"><h3>👥 Користувачів</h3><strong><?php echo $total_users; ?></strong></div><div class="card"><h3>👁 Показів</h3><strong><?php echo $views; ?></strong></div><div class="card"><h3>🖱 Кліків</h3><strong><?php echo $clicks; ?></strong></div><div class="card"><h3>📈 CTR</h3><strong><?php echo $ctr; ?>%</strong></div></div>
        <form method="post" class="card" style="padding:20px;max-width:900px;"> <?php wp_nonce_field('fbm_ads_settings'); ?>
            <p><label><input type="checkbox" name="ad_enabled" value="1" <?php checked(get_option('fbm_ad_enabled'),1); ?>> Увімкнути рекламний блок у кабінеті</label></p>
            <table class="form-table"><tr><th>Заголовок</th><td><input class="regular-text" name="ad_title" value="<?php echo esc_attr(get_option('fbm_ad_title','')); ?>"></td></tr>
            <tr><th>Текст</th><td><textarea class="large-text" rows="3" name="ad_text"><?php echo esc_textarea(get_option('fbm_ad_text','')); ?></textarea></td></tr>
            <tr><th>Посилання</th><td><input class="regular-text" name="ad_url" value="<?php echo esc_attr(get_option('fbm_ad_url','')); ?>"></td></tr>
            <tr><th>URL картинки</th><td><input class="regular-text" name="ad_image" value="<?php echo esc_attr(get_option('fbm_ad_image','')); ?>"></td></tr></table>
            <p><button class="button button-primary" name="fbm_save_ads">💾 Зберегти</button> <button class="button" name="fbm_reset_ad_stats" onclick="return confirm('Очистити статистику?')">🧹 Очистити статистику</button></p>
            <p><strong>Шорткод звіту для рекламодавця:</strong> <code>[fbm_ad_report]</code></p>
        </form>
    </div><?php
}

function fbm_ad_block_shortcode() {
    if ( ! get_option( 'fbm_ad_enabled' ) ) return '';
    update_option( 'fbm_ad_views', (int)get_option('fbm_ad_views',0)+1 );
    $title=get_option('fbm_ad_title','Корисна пропозиція'); $text=get_option('fbm_ad_text',''); $url=get_option('fbm_ad_url','#'); $img=get_option('fbm_ad_image','');
    $click=add_query_arg('fbm_ad_click','1',home_url('/'));
    ob_start(); ?><div class="fbm-card fbm-ad" style="border:1px solid #ffd38a;background:#fffaf0;"><a href="<?php echo esc_url($click); ?>" style="display:flex;gap:12px;align-items:center;text-decoration:none;color:inherit;" target="_blank"><?php if($img): ?><img src="<?php echo esc_url($img); ?>" style="width:72px;height:72px;object-fit:cover;border-radius:10px;" alt=""><?php endif; ?><span><strong style="display:block;font-size:16px;">📣 <?php echo esc_html($title); ?></strong><small><?php echo esc_html($text); ?></small></span></a></div><?php return ob_get_clean();
}
add_shortcode('fbm_ad_block','fbm_ad_block_shortcode');

function fbm_ad_report_shortcode(){ if(!is_user_logged_in()) return '<p>Увійдіть, щоб переглянути звіт.</p>'; $views=(int)get_option('fbm_ad_views',0); $clicks=(int)get_option('fbm_ad_clicks',0); $users=count_users(); $total=(int)($users['total_users']??0); $ctr=$views>0?round($clicks/$views*100,2):0; return '<div class="fbm-card"><h3>📊 Звіт реклами</h3><p>Користувачів: <strong>'.$total.'</strong></p><p>Показів: <strong>'.$views.'</strong></p><p>Кліків: <strong>'.$clicks.'</strong></p><p>CTR: <strong>'.$ctr.'%</strong></p></div>'; }
add_shortcode('fbm_ad_report','fbm_ad_report_shortcode');

add_action('init',function(){ if(isset($_GET['fbm_ad_click'])){ update_option('fbm_ad_clicks',(int)get_option('fbm_ad_clicks',0)+1); $url=get_option('fbm_ad_url',home_url('/')); wp_redirect($url?:home_url('/')); exit; } });

add_action('wp_head',function(){ echo '<style>.site-info,.footer-bar .site-info{display:none!important;}</style>'; });

// ============================================
// 15. ШОРТКОДИ (ВХІД, РЕЄСТРАЦІЯ, ПРОФІЛЬ)
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
        <h2 style="text-align:center;margin-bottom:10px;">🔑 Вхід у Family Budget</h2><p style="text-align:center;color:#666;margin-top:0;">Увійдіть через Google або використайте звичайний пароль.</p>

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
                <input type="text" name="log_username" value="<?php echo esc_attr( $_POST['log_username'] ?? '' ); ?>" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
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

add_shortcode( 'fbm_register', 'fbm_register_shortcode' );
function fbm_register_shortcode() {
    if ( is_user_logged_in() ) {
        wp_redirect( home_url( '/my-budget/' ) );
        exit;
    }

    $error = '';

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
                <input type="text" name="reg_firstname" value="<?php echo esc_attr( $_POST['reg_firstname'] ?? '' ); ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Прізвище</label>
                <input type="text" name="reg_lastname" value="<?php echo esc_attr( $_POST['reg_lastname'] ?? '' ); ?>" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Логін *</label>
                <input type="text" name="reg_username" value="<?php echo esc_attr( $_POST['reg_username'] ?? '' ); ?>" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
            </div>
            <div style="margin-bottom:12px;">
                <label style="display:block;font-size:13px;margin-bottom:4px;color:#555;">Email *</label>
                <input type="email" name="reg_email" value="<?php echo esc_attr( $_POST['reg_email'] ?? '' ); ?>" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;">
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

add_shortcode( 'fbm_forgot_password', 'fbm_forgot_password_shortcode' );
function fbm_forgot_password_shortcode() {
    if ( is_user_logged_in() ) {
        wp_redirect( home_url( '/my-budget/' ) );
        exit;
    }

    $error   = '';
    $success = '';

    if ( isset( $_POST['fbm_do_forgot'] ) && check_admin_referer( 'fbm_forgot' ) ) {
        $email = sanitize_email( $_POST['forgot_email'] );
        if ( ! is_email( $email ) ) {
            $error = 'Введіть коректний email.';
        } else {
            $user = get_user_by( 'email', $email );
            if ( ! $user ) {
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
                        <input type="password" name="new_password2" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:6px;box-sizing:border-box;" placeholder="Повторіть новий пароль">
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
            <button type="submit" name="fbm_do_forgot" style="width:100%;padding:12px;background:#2271b1;color:#fff;border:none;border-radius:6px;font-size:15px;cursor:pointer;">📧 Надіслати інструкцію</button>
        </form>
        <?php endif; ?>

        <p style="text-align:center;margin-top:15px;font-size:13px;color:#999;">
            <a href="<?php echo home_url( '/login/' ); ?>">← Повернутися до входу</a>
        </p>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode( 'fbm_profile', 'fbm_profile_shortcode' );
function fbm_profile_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<div style="text-align:center;padding:30px;"><p>🔒 <a href="' . home_url( '/login/' ) . '">Увійдіть</a>, щоб переглянути кабінет.</p></div>';
    }

    $user_id = get_current_user_id();
    $user    = get_userdata( $user_id );
    $error   = '';
    $success = '';

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
    if ( isset( $_GET['fbm_payment_pending'] ) ) {
        echo '<div style="max-width:900px;margin:20px auto;padding:18px;background:#fff3cd;border:2px solid #ffc107;border-radius:10px;color:#533f03;">';
        echo '<h3 style="margin-top:0;">🚧 Онлайн-оплата тестується</h3>';
        echo '<p>Платежі збережено в історії зі статусом <strong>Очікує оплату</strong>. Після підключення платіжного шлюзу тут відкриватиметься реальна сторінка оплати.</p>';
        echo '<p style="margin-bottom:0;"><a href="' . esc_url( home_url( '/my-budget/' ) ) . '" style="font-weight:bold;">← Повернутися до кабінету</a></p>';
        echo '</div>';
    }
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
// 16. ПЕРЕНАПРАВЛЕННЯ
// ============================================
add_filter( 'login_redirect', 'fbm_login_redirect', 10, 3 );
function fbm_login_redirect( $redirect_to, $request, $user ) {
    if ( isset( $user->ID ) && ! is_wp_error( $user ) ) {
        return home_url( '/my-budget/' );
    }
    return $redirect_to;
}

add_action( 'login_init', 'fbm_redirect_login_page' );
function fbm_redirect_login_page() {
    $login_page  = home_url( '/login/' );
    $action      = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'login';
    $redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';

    // /wp-admin має залишатися доступним для адміністратора через стандартний WordPress login.
    if ( $redirect_to && false !== strpos( $redirect_to, '/wp-admin' ) ) {
        return;
    }
    if ( is_admin() || in_array( $action, array( 'logout', 'lostpassword', 'resetpass', 'rp', 'postpass', 'register' ), true ) ) {
        return;
    }
    if ( ! isset( $_POST['log'] ) ) {
        wp_redirect( $login_page );
        exit;
    }
}

add_action( 'wp_logout', 'fbm_logout_redirect' );
function fbm_logout_redirect() {
    wp_redirect( home_url( '/login/' ) );
    exit;
}

add_filter( 'lostpassword_url', 'fbm_lostpassword_url', 10, 2 );
function fbm_lostpassword_url( $lostpassword_url, $redirect ) {
    return home_url( '/forgot-password/' );
}

add_filter( 'register_url', 'fbm_register_url' );
function fbm_register_url( $url ) {
    return home_url( '/register/' );
}

add_filter( 'login_url', 'fbm_custom_login_url', 10, 3 );
function fbm_custom_login_url( $login_url, $redirect, $force_reauth ) {
    if ( $redirect && false !== strpos( $redirect, '/wp-admin' ) ) {
        return add_query_arg( 'redirect_to', rawurlencode( $redirect ), site_url( 'wp-login.php', 'login' ) );
    }
    return home_url( '/login/' );
}

// ============================================
// 17. РЕШТА ФУНКЦІЙ
// ============================================
add_filter( 'retrieve_password_message', 'fbm_custom_reset_password_message', 10, 4 );
function fbm_custom_reset_password_message( $message, $key, $user_login, $user_data ) {
    $reset_url = add_query_arg( array(
        'action' => 'rp',
        'key'    => $key,
        'login'  => rawurlencode( $user_login ),
    ), home_url( '/forgot-password/' ) );

    $message = sprintf(
        "Ви або хтось інший запросив скидання пароля для акаунту: %s\n\nЯкщо це були не ви — проігноруйте цей лист.\n\nЩоб скинути пароль, перейдіть за посиланням:\n\n%s\n\nЗ повагою,\n%s",
        $user_login,
        $reset_url,
        home_url()
    );
    return $message;
}

add_filter( 'retrieve_password_title', 'fbm_reset_password_title' );
function fbm_reset_password_title( $title ) {
    return 'Скидання пароля — ' . get_bloginfo( 'name' );
}

add_action( 'wp_head', 'fbm_pwa_head' );
function fbm_pwa_head() {
    ?>
    <link rel="manifest" href="<?php echo esc_url( home_url( '/?fbm_manifest=1' ) ); ?>">
    <link rel="apple-touch-icon" href="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/icons/apple-touch-icon.png' ); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/icons/favicon-32.png' ); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'assets/icons/favicon-16.png' ); ?>">
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
        nocache_headers();
        header( 'Content-Type: application/manifest+json; charset=utf-8' );
        echo wp_json_encode( array(
            'id' => home_url( '/my-budget/' ),
            'name' => 'Family Budget Network',
            'short_name' => 'Бюджет',
            'description' => 'Особистий та сімейний бюджет, платежі й фінансові цілі',
            'start_url' => home_url( '/my-budget/?source=pwa' ),
            'scope' => home_url( '/' ),
            'display' => 'standalone',
            'display_override' => array( 'standalone', 'minimal-ui', 'browser' ),
            'orientation' => 'portrait',
            'background_color' => '#ffffff',
            'theme_color' => '#2271b1',
            'icons' => array(
                array( 'src' => plugin_dir_url( __FILE__ ) . 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
                array( 'src' => plugin_dir_url( __FILE__ ) . 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
                array( 'src' => plugin_dir_url( __FILE__ ) . 'assets/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ),
            )
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }
    if ( isset( $_GET['fbm_desktop_shortcut'] ) ) {
        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="Family-Budget.url"' );
        echo "[InternetShortcut]
URL=" . home_url( '/my-budget/' ) . "
IconFile=" . FBM_PLUGIN_URL . "assets/icons/favicon-32.png
IconIndex=0
";
        exit;
    }
    if ( isset( $_GET['fbm_pwa_icon'] ) ) {
        nocache_headers();
        header( 'Content-Type: image/svg+xml; charset=utf-8' );
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512"><rect width="512" height="512" rx="96" fill="#2271b1"/><text x="50%" y="56%" dominant-baseline="middle" text-anchor="middle" font-size="250">💰</text></svg>';
        exit;
    }
    if ( isset( $_GET['fbm_sw'] ) ) {
        nocache_headers();
        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Service-Worker-Allowed: /' );
        $start = esc_js( home_url( '/my-budget/' ) );
        echo "const FBM_CACHE='fbm-v4-1-0';
";
        echo "self.addEventListener('install',e=>{self.skipWaiting();e.waitUntil(caches.open(FBM_CACHE).then(c=>c.addAll(['{$start}']).catch(()=>true)));});
";
        echo "self.addEventListener('activate',e=>{e.waitUntil(self.clients.claim());});
";
        echo "self.addEventListener('fetch',e=>{if(e.request.method!=='GET')return;e.respondWith(fetch(e.request).catch(()=>caches.match(e.request).then(r=>r||caches.match('{$start}'))));});
";
        exit;
    }
}

add_action( 'wp_footer', 'fbm_pwa_footer_capture', 5 );
function fbm_pwa_footer_capture() {
    ?>
    <script>
    window.fbmDeferredPrompt = window.fbmDeferredPrompt || null;
    window.addEventListener('beforeinstallprompt', function(e){
        e.preventDefault();
        window.fbmDeferredPrompt = e;
        document.dispatchEvent(new CustomEvent('fbm-pwa-ready'));
    });
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function(){
            navigator.serviceWorker.register('<?php echo esc_js( home_url( '/?fbm_sw=1' ) ); ?>', {scope: '<?php echo esc_js( home_url( '/' ) ); ?>'}).catch(function(err){
                console.warn('Family Budget PWA SW error:', err);
            });
        });
    }
    </script>
    <?php
}

add_shortcode( 'fbm_install_button', 'fbm_install_button_shortcode' );
function fbm_install_button_shortcode() {
    ob_start();
    ?>
    <div style="text-align:center;padding:20px;background:#f0f8ff;border-radius:10px;margin:20px 0;">
        <p><strong>📱 Відкривайте кабінет як додаток</strong></p>
        <button id="fbm_install_app" class="btn btn-primary" style="display:inline-block;font-size:16px;padding:12px 30px;background:#2271b1;color:#fff;border:none;border-radius:6px;cursor:pointer;">
            📲 Додати на смартфон / робочий стіл
        </button>
        <p style="color:#666;font-size:13px;margin-bottom:8px;" id="fbm_install_hint">Натисніть кнопку. Якщо браузер готовий — відкриється вікно встановлення. Якщо ні — зʼявиться інструкція для вашого пристрою.</p>
        <p style="margin:6px 0 0;"><a href="<?php echo esc_url( home_url( '/?fbm_desktop_shortcut=1' ) ); ?>" style="font-size:13px;">💻 Скачати ярлик для Windows</a></p>
    </div>
    <script>
    (function(){
        var btn=document.getElementById('fbm_install_app'), hint=document.getElementById('fbm_install_hint');
        function showReady(){ if(hint && window.fbmDeferredPrompt){ hint.innerHTML='✅ Браузер готовий встановити додаток. Натисніть кнопку.'; } }
        document.addEventListener('fbm-pwa-ready', showReady); showReady();
        if(btn) btn.addEventListener('click', function(){
            if(window.fbmDeferredPrompt){
                var promptEvent = window.fbmDeferredPrompt;
                promptEvent.prompt();
                promptEvent.userChoice.finally(function(){ window.fbmDeferredPrompt=null; });
                return;
            }
            var ua=navigator.userAgent.toLowerCase();
            var isIOS=/iphone|ipad|ipod/.test(ua);
            var isAndroid=/android/.test(ua);
            if(hint) hint.innerHTML = isIOS
                ? 'На iPhone/iPad: натисніть <strong>Поділитися</strong> → <strong>На екран «Додому»</strong>.'
                : (isAndroid ? 'Android Chrome: меню <strong>⋮</strong> → <strong>Додати на головний екран</strong>. Якщо пункту немає — відкрийте сайт у Chrome та оновіть сторінку.' : 'ПК Chrome/Edge: у правому верхньому куті адресного рядка натисніть іконку встановлення або меню <strong>⋮</strong> → <strong>Встановити Family Budget</strong>.');
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

add_action( 'init', 'fbm_handle_ipay_callback' );
function fbm_handle_ipay_callback() {
    if ( isset( $_REQUEST['fbm_ipay_callback'] ) && get_option( 'fbm_enable_legacy_ipay_callback' ) ) {
        global $wpdb;
        $util_id = intval( $_REQUEST['util_id'] ?? 0 );
        $status = sanitize_text_field( $_REQUEST['status'] ?? '' );
        
        if ( $util_id > 0 ) {
            $table = $wpdb->prefix . 'fbm_utilities';
            $util = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $util_id ) );
            if ( $util && $util->status !== 'paid' && $status === 'paid' ) {
                $wpdb->update( $table, array( 'status' => 'paid', 'paid_at' => current_time( 'mysql' ) ), array( 'id' => $util_id ) );
                $wpdb->insert( $wpdb->prefix . 'fbm_transactions', array(
                    'user_id' => $util->user_id,
                    'type' => 'expense',
                    'amount' => $util->amount,
                    'category' => $util->service_name . ( $util->provider ? ' (' . $util->provider . ')' : '' ),
                    'description' => 'Оплата через агрегатор (callback)',
                    'status' => 'completed'
                ) );
                wp_send_json_success( array( 'message' => 'OK' ) );
            } else {
                wp_send_json_error( array( 'message' => 'Невалідний запис або вже сплачено' ) );
            }
        } else {
            wp_send_json_error( array( 'message' => 'util_id required' ) );
        }
    }
}

// ============================================
// 20. АВТООНОВЛЕННЯ ЧЕРЕЗ GITHUB RELEASES
// ============================================
function fbm_github_latest_release() {
    $cached = get_transient( 'fbm_github_latest_release' );
    if ( false !== $cached ) {
        return $cached;
    }

    $url = 'https://api.github.com/repos/' . FBM_GITHUB_REPO . '/releases/latest';
    $response = wp_remote_get( $url, array(
        'timeout' => 15,
        'headers' => array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'FamilyBudgetNetwork/' . FBM_VERSION,
        ),
    ) );

    if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
        set_transient( 'fbm_github_latest_release', null, 15 * MINUTE_IN_SECONDS );
        return null;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
        set_transient( 'fbm_github_latest_release', null, 15 * MINUTE_IN_SECONDS );
        return null;
    }

    set_transient( 'fbm_github_latest_release', $data, 30 * MINUTE_IN_SECONDS );
    return $data;
}

function fbm_github_download_url( $release ) {
    if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
        foreach ( $release['assets'] as $asset ) {
            $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
            if ( $name === FBM_GITHUB_RELEASE_ASSET || preg_match( '/family-budget-network.*\.zip$/i', $name ) ) {
                return $asset['browser_download_url'] ?? '';
            }
        }
    }
    return $release['zipball_url'] ?? '';
}

add_filter( 'pre_set_site_transient_update_plugins', 'fbm_check_for_github_update' );
function fbm_check_for_github_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }

    $plugin_file = plugin_basename( __FILE__ );
    $release = fbm_github_latest_release();
    if ( empty( $release['tag_name'] ) ) {
        return $transient;
    }

    $remote_version = ltrim( (string) $release['tag_name'], 'vV' );
    if ( version_compare( $remote_version, FBM_VERSION, '<=' ) ) {
        return $transient;
    }

    $download_url = fbm_github_download_url( $release );
    if ( empty( $download_url ) ) {
        return $transient;
    }

    $obj = new stdClass();
    $obj->slug = dirname( $plugin_file );
    $obj->plugin = $plugin_file;
    $obj->new_version = $remote_version;
    $obj->url = $release['html_url'] ?? 'https://github.com/' . FBM_GITHUB_REPO;
    $obj->package = $download_url;
    $obj->tested = '6.6';
    $obj->requires = '5.8';
    $transient->response[ $plugin_file ] = $obj;
    return $transient;
}

add_filter( 'plugins_api', 'fbm_github_plugin_info', 20, 3 );
function fbm_github_plugin_info( $res, $action, $args ) {
    if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( __FILE__ ) ) !== $args->slug ) {
        return $res;
    }

    $release = fbm_github_latest_release();
    if ( empty( $release ) ) {
        return $res;
    }

    $obj = new stdClass();
    $obj->name = 'Family Budget Network PRO';
    $obj->slug = dirname( plugin_basename( __FILE__ ) );
    $obj->version = ltrim( (string) ( $release['tag_name'] ?? FBM_VERSION ), 'vV' );
    $obj->author = 'Portallcom UA';
    $obj->homepage = 'https://github.com/' . FBM_GITHUB_REPO;
    $obj->download_link = fbm_github_download_url( $release );
    $obj->sections = array(
        'description' => 'Family Budget Network: сімейний бюджет, PWA, Portmone-ready платежі.',
        'changelog'   => ! empty( $release['body'] ) ? wp_kses_post( nl2br( $release['body'] ) ) : 'Опис релізу буде доступний у GitHub Releases.',
    );
    return $obj;
}

add_action( 'upgrader_process_complete', 'fbm_clear_github_update_cache', 10, 2 );
function fbm_clear_github_update_cache( $upgrader, $options ) {
    delete_transient( 'fbm_github_latest_release' );
}
