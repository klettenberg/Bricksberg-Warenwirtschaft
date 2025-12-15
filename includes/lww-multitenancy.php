<?php
/**
 * Modul: Mandantenfähigkeit (v1.2)
 * 
 * Verwaltet Mandanten (Tenants) und isoliert Daten via Filter.
 */
if (!defined('ABSPATH')) exit;

class LWW_Multitenancy {

    public static function init() {
        add_action('init', [self::class, 'register_tenant_cpt']);
        add_action('init', [self::class, 'register_tenant_role']);
        add_action('admin_menu', [self::class, 'add_admin_menu']);
        add_action('admin_bar_menu', [self::class, 'add_tenant_switcher'], 100);

        // Core-Filter für Daten-Isolation
        add_action('pre_get_posts', [self::class, 'filter_posts_by_tenant']);

        // Cookie Handling für Switcher
        add_action('admin_init', [self::class, 'handle_tenant_switch']);

        // AJAX Handler für Tenant-Management
        add_action('wp_ajax_lww_save_tenant_config', [self::class, 'ajax_save_tenant_config']);
        add_action('wp_ajax_lww_assign_user_to_tenant', [self::class, 'ajax_assign_user_to_tenant']);
        add_action('wp_ajax_lww_remove_user_from_tenant', [self::class, 'ajax_remove_user_from_tenant']);
    }

    public static function register_tenant_cpt() {
        register_post_type('lww_tenant', [
            'labels' => ['name' => __('Mandanten', 'lego-wawi'), 'singular_name' => __('Mandant', 'lego-wawi')],
            'public' => false,
            'show_ui' => false,
            'supports' => ['title', 'custom-fields'],
            'capability_type' => 'post',
        ]);
    }

    public static function get_current_tenant_id() {
        if (isset($_COOKIE['lww_current_tenant'])) {
            return intval($_COOKIE['lww_current_tenant']);
        }
        return 0; // 0 = Global / Main Admin
    }

    public static function handle_tenant_switch() {
        if (isset($_GET['lww_switch_tenant'])) {
            $tid = intval($_GET['lww_switch_tenant']);
            setcookie('lww_current_tenant', $tid, time() + 3600 * 24 * 30, COOKIEPATH, COOKIE_DOMAIN);
            $_COOKIE['lww_current_tenant'] = $tid; // Sofort verfügbar machen
            
            $redirect = isset($_GET['redirect']) ? urldecode($_GET['redirect']) : admin_url('admin.php?page=bricksberg_wawi_dashboard');
            wp_redirect($redirect);
            exit;
        }
    }

    public static function filter_posts_by_tenant($query) {
        // Nur im Admin und nicht bei AJAX (außer explizit gewünscht) oder Cron anwenden
        if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        
        // Nur für WaWi Post Types anwenden (Inventar, Bestellungen, Jobs)
        $target_types = ['lww_inventory_item', 'lww_order', 'lww_job'];
        $q_type = $query->get('post_type');
        
        // Check if query targets our types
        $is_target = false;
        if (is_array($q_type)) {
            if (array_intersect($q_type, $target_types)) $is_target = true;
        } elseif (in_array($q_type, $target_types)) {
            $is_target = true;
        }

        if (!$is_target) return;

        $current_tenant = self::get_current_tenant_id();
        
        // Wenn Tenant = 0 (Global Admin), zeigen wir alles (oder filtern optional auf "keine Tenant ID")
        // Hier: Global Admin sieht alles. 
        if ($current_tenant === 0) return;

        // Tenant Filter anwenden
        $meta_query = $query->get('meta_query');
        if (!is_array($meta_query)) $meta_query = [];
        
        $meta_query[] = [
            'key' => '_lww_tenant_id',
            'value' => $current_tenant,
            'compare' => '='
        ];
        
        $query->set('meta_query', $meta_query);
    }

    public static function add_admin_menu() {
        add_submenu_page(
            'bricksberg_wawi_dashboard',
            __('Mandanten', 'lego-wawi'),
            __('Mandanten', 'lego-wawi'),
            'manage_options',
            'lww_tenants_ui',
            [self::class, 'render_ui']
        );
    }

    public static function render_ui() {
        if (!current_user_can('manage_options')) wp_die('Access Denied');
        
        if (isset($_POST['create_tenant']) && check_admin_referer('lww_create_tenant_nonce')) {
            $name = sanitize_text_field($_POST['tenant_name']);
            if ($name) {
                wp_insert_post(['post_type'=>'lww_tenant', 'post_title'=>$name, 'post_status'=>'publish']);
                echo '<div class="notice notice-success"><p>Mandant erstellt.</p></div>';
            }
        }

        $tenants = get_posts(['post_type'=>'lww_tenant', 'posts_per_page'=>-1]);
        ?>
        <div class="wrap lww-wrap">
            <h1><span class="dashicons dashicons-businessperson"></span> <?php _e('Mandantenverwaltung', 'lego-wawi'); ?></h1>
            <p><?php _e('Hier verwalten Sie separate Shops/Mandanten. Nutzen Sie den Switcher oben in der Leiste, um den Kontext zu wechseln.', 'lego-wawi'); ?></p>
            
            <div class="lww-card">
                <h2>Neuen Mandanten anlegen</h2>
                <form method="post" style="display:flex; gap:10px;">
                    <?php wp_nonce_field('lww_create_tenant_nonce'); ?>
                    <input type="text" name="tenant_name" placeholder="Name (z.B. Shop Berlin)" required class="regular-text">
                    <button type="submit" name="create_tenant" class="button button-primary">Anlegen</button>
                </form>
            </div>

            <div class="lww-card lww-mt-20">
                <table class="wp-list-table widefat striped">
                    <thead><tr><th>ID</th><th>Name</th><th>Aktion</th></tr></thead>
                    <tbody>
                        <?php foreach($tenants as $t): ?>
                        <tr>
                            <td><?php echo $t->ID; ?></td>
                            <td><strong><?php echo esc_html($t->post_title); ?></strong></td>
                            <td><a href="?lww_switch_tenant=<?php echo $t->ID; ?>" class="button button-small">Zu diesem Mandanten wechseln</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public static function add_tenant_switcher($wp_admin_bar) {
        if (!current_user_can('edit_posts')) return;
        $current = self::get_current_tenant_id();
        $label = ($current > 0) ? get_the_title($current) : 'Global (Alle)';

        $wp_admin_bar->add_node([
            'id' => 'lww_tenant_switch',
            'title' => '<span class="ab-icon dashicons dashicons-store"></span> ' . $label,
            'href' => '#',
            'meta' => ['title' => 'Mandant wechseln']
        ]);
        
        $redirect = urlencode(remove_query_arg('lww_switch_tenant'));

        $wp_admin_bar->add_node([
            'id' => 'lww_tenant_0',
            'parent' => 'lww_tenant_switch',
            'title' => 'Global (Alle anzeigen)',
            'href' => '?lww_switch_tenant=0&redirect=' . $redirect
        ]);

        $tenants = get_posts(['post_type'=>'lww_tenant', 'posts_per_page'=>20]);
        foreach($tenants as $t) {
            $wp_admin_bar->add_node([
                'id' => 'lww_tenant_' . $t->ID,
                'parent' => 'lww_tenant_switch',
                'title' => $t->post_title,
                'href' => '?lww_switch_tenant=' . $t->ID . '&redirect=' . $redirect
            ]);
        }
    }
}
LWW_Multitenancy::init();
?>